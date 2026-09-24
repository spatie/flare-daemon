<?php

namespace Spatie\FlareDaemon;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use Spatie\FlareDaemon\Support\ApiKey;
use Spatie\FlareDaemon\Support\Output;
use Throwable;

use function React\Promise\resolve;

class Ingest
{
    protected const DEGRADED_WINDOW_SECONDS = 60.0;

    /** @var array<string, array<string, Buffer>> */
    protected array $buffers = [];

    protected ?TimerInterface $maintenanceTimer = null;

    protected bool $shuttingDown = false;

    protected int $inFlight = 0;

    /** @var array<int, callable(): void> */
    protected array $drainCallbacks = [];

    protected QuotaState $quotaState;

    protected int $totalReceived = 0;

    protected int $totalBuffered = 0;

    protected int $totalForwarded = 0;

    /** @var array<string, int> */
    protected array $forwardedSinceLastSummary = [];

    /** @var array<string, int> */
    protected array $pausedDropsSinceLastSummary = [];

    protected float $lastSummaryAt;

    protected ?float $lastFailedDeliveryAt = null;

    /** @var array<string, array<string, string>> */
    protected array $lastPauseReasons = [];

    /** @var array<string, array<string, true>> */
    protected array $loggedPauses = [];

    public function __construct(
        protected LoopInterface $loop,
        protected Upstream $upstream,
        protected Output $output,
        ?QuotaState $quotaState = null,
        protected int $byteThreshold = 262144,
        protected float $flushAfterSeconds = 10.0,
        protected float $maintenanceIntervalSeconds = 1.0,
        protected int $quotaPauseSeconds = 60,
        protected int $rateLimitPauseSeconds = 10,
        protected float $summaryIntervalSeconds = 10.0,
    ) {
        $this->quotaState = $quotaState ?? new QuotaState;
        $this->lastSummaryAt = microtime(true);
        $this->maintenanceTimer = $this->loop->addPeriodicTimer(
            $this->maintenanceIntervalSeconds,
            fn () => $this->maintain(),
        );
    }

    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function accept(string $apiKey, string $type, array $payload): void
    {
        $this->totalReceived++;

        if ($this->shuttingDown) {
            return;
        }

        $now = microtime(true);

        if ($this->quotaState->isPaused($apiKey, $type, $now)) {
            $this->recordPausedDrops($type, 1);

            return;
        }

        $this->totalBuffered++;

        $this->output->debug('payload accepted', [
            'api_key' => $apiKey,
            'type' => $type,
        ]);

        $this->buffer($apiKey, $type)->add($payload, $now);

        // Flush immediately — no batch API yet. When batching arrives,
        // gate this behind shouldFlushBySize() again.
        $this->scheduleFlush($apiKey, $type);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return PromiseInterface<array{status: int, body: mixed, headers: array<string, string>}>
     */
    public function diagnose(string $apiKey, string $type, array $payload): PromiseInterface
    {
        if ($this->shuttingDown) {
            return resolve($this->result(503, ['message' => 'Daemon is shutting down']));
        }

        $this->output->debug('payload accepted', [
            'api_key' => $apiKey,
            'type' => $type,
            'test' => true,
        ]);

        return $this->upstream->send($apiKey, $type, $payload)->then(
            function (array $response) use ($apiKey, $type): array {
                $this->output->debug('payload forwarded upstream', [
                    'api_key' => $apiKey,
                    'type' => $type,
                    'status' => $response['status'],
                    'test' => true,
                ]);

                return $this->result(
                    $response['status'],
                    $response['body'],
                    $this->forwardedHeaders($response['headers']),
                );
            },
            function (Throwable $throwable): array {
                $this->output->error('upstream diagnostic request failed', [
                    'exception' => $throwable,
                    'test' => true,
                ]);

                return $this->result(502, ['message' => 'Upstream request failed']);
            },
        );
    }

    public function shutdown(?callable $onDrained = null): void
    {
        if ($onDrained !== null) {
            $this->drainCallbacks[] = $onDrained;
        }

        if ($this->shuttingDown) {
            $this->checkForDrain();

            return;
        }

        $this->shuttingDown = true;

        if ($this->maintenanceTimer !== null) {
            $this->loop->cancelTimer($this->maintenanceTimer);
            $this->maintenanceTimer = null;
        }

        foreach ($this->buffers as $apiKey => $typedBuffers) {
            foreach (array_keys($typedBuffers) as $type) {
                $this->scheduleFlush($apiKey, $type);
            }
        }

        $this->checkForDrain();
    }

    /**
     * @return array{
     *     degraded: bool,
     *     total_received: int,
     *     total_buffered: int,
     *     total_forwarded: int,
     *     total_dropped: int,
     *     keys: array<string, array<string, array{buffered: int, paused: bool, retry_after: string|null, last_429_reason: string|null, pause_reason: string|null}>>|object
     * }
     */
    public function status(): array
    {
        $now = microtime(true);
        $keys = array_unique([
            ...array_keys($this->buffers),
            ...$this->quotaState->keys(),
        ]);

        $status = [
            'degraded' => $this->isDegraded($now),
            'total_received' => $this->totalReceived,
            'total_buffered' => $this->totalBuffered,
            'total_forwarded' => $this->totalForwarded,
            'total_dropped' => $this->totalReceived - $this->totalForwarded,
        ];

        foreach ($keys as $apiKey) {
            $label = $this->uniqueKeyLabel($apiKey, $status['keys'] ?? []);

            foreach (QuotaState::ENTITY_TYPES as $type) {
                $buffer = $this->buffers[$apiKey][$type] ?? null;

                $reason = $this->quotaState->reason($apiKey, $type, $now);

                $status['keys'][$label][$type] = [
                    'buffered' => $buffer?->count() ?? 0,
                    'paused' => $this->quotaState->isPaused($apiKey, $type, $now),
                    'retry_after' => $this->quotaState->retryAfter($apiKey, $type, $now),
                    'last_429_reason' => $reason,
                    'pause_reason' => $reason,
                ];
            }
        }

        if (! isset($status['keys'])) {
            $status['keys'] = new \stdClass;
        }

        return $status;
    }

    /**
     * @return array{
     *     received: int,
     *     buffered: int,
     *     forwarded: int,
     *     pending: int,
     *     pending_bytes: int,
     *     in_flight: int
     * }
     */
    public function stats(): array
    {
        $pending = 0;
        $pendingBytes = 0;

        foreach ($this->buffers as $typedBuffers) {
            foreach ($typedBuffers as $buffer) {
                $pending += $buffer->count();
                $pendingBytes += $buffer->bufferedBytes();
            }
        }

        return [
            'received' => $this->totalReceived,
            'buffered' => $this->totalBuffered,
            'forwarded' => $this->totalForwarded,
            'pending' => $pending,
            'pending_bytes' => $pendingBytes,
            'in_flight' => $this->inFlight,
        ];
    }

    protected function maintain(): void
    {
        $now = microtime(true);

        foreach ($this->quotaState->resumeExpired($now) as $resumed) {
            if (! isset($this->loggedPauses[$resumed['api_key']][$resumed['type']])) {
                continue;
            }

            unset($this->loggedPauses[$resumed['api_key']][$resumed['type']]);
            $this->output->info('upstream pause expired; delivery can resume', $resumed);
        }

        foreach ($this->buffers as $apiKey => $typedBuffers) {
            foreach ($typedBuffers as $type => $buffer) {
                if (! $buffer->hasItems()) {
                    continue;
                }

                $oldestAge = $buffer->oldestAge($now);

                if ($buffer->shouldFlushBySize() || ($oldestAge !== null && $oldestAge >= $this->flushAfterSeconds)) {
                    $this->flush($apiKey, $type);
                }
            }
        }

        if ($now - $this->lastSummaryAt >= $this->summaryIntervalSeconds) {
            $this->logDeliverySummary();
            $this->lastSummaryAt = $now;
        }

        $this->checkForDrain();
    }

    protected function scheduleFlush(string $apiKey, string $type): void
    {
        $this->loop->futureTick(fn () => $this->flush($apiKey, $type));
    }

    protected function flush(string $apiKey, string $type): void
    {
        $buffer = $this->buffers[$apiKey][$type] ?? null;

        if ($buffer === null || ! $buffer->hasItems() || $buffer->isFlushing()) {
            $this->checkForDrain();

            return;
        }

        $now = microtime(true);

        if ($this->quotaState->isPaused($apiKey, $type, $now)) {
            $this->recordPausedDrops($type, count($buffer->drain()));
            $this->cleanupBuffer($apiKey, $type);
            $this->checkForDrain();

            return;
        }

        $item = $buffer->peek();

        if ($item === null) {
            $this->cleanupBuffer($apiKey, $type);
            $this->checkForDrain();

            return;
        }

        $buffer->markFlushing(true);
        $this->inFlight++;

        $this->upstream->send($apiKey, $type, $item['payload'])->then(
            fn (array $response) => $this->completeSuccessfulSend($apiKey, $type, $response),
            fn (Throwable $throwable) => $this->completeFailedSend($apiKey, $type, $throwable),
        );
    }

    /**
     * @param  array{status: int, body: mixed, headers: array<string, array<int, string>>}  $response
     */
    protected function completeSuccessfulSend(string $apiKey, string $type, array $response): void
    {
        $buffer = $this->buffers[$apiKey][$type] ?? null;
        $item = $buffer?->shift();

        if ($buffer !== null) {
            $buffer->markFlushing(false);
        }

        $this->inFlight--;

        if ($item === null) {
            $this->cleanupBuffer($apiKey, $type);
            $this->checkForDrain();

            return;
        }

        $status = $response['status'];
        $body = $response['body'];

        if ($status === 429) {
            $this->pauseAfterTooManyRequests($apiKey, $type, $response);
        } elseif ($status === 422) {
            $this->output->warning('upstream validation failed', [
                'api_key' => $apiKey,
                'type' => $type,
                'body' => Upstream::summarizeBody($body, $apiKey),
            ]);
        } elseif ($status < 200 || $status >= 300) {
            $this->lastFailedDeliveryAt = microtime(true);
            $this->output->error('upstream request failed', [
                'api_key' => $apiKey,
                'type' => $type,
                'status' => $status,
                'cf_ray' => $response['headers']['cf-ray'][0] ?? null,
                'body' => Upstream::summarizeBody($body, $apiKey),
            ]);
        } else {
            $this->totalForwarded++;
            unset($this->lastPauseReasons[$apiKey][$type]);
            $this->forwardedSinceLastSummary[$type] = ($this->forwardedSinceLastSummary[$type] ?? 0) + 1;
            $this->output->debug('payload forwarded upstream', [
                'api_key' => $apiKey,
                'type' => $type,
                'status' => $status,
            ]);
        }

        if (($this->buffers[$apiKey][$type] ?? null)?->hasItems()) {
            $this->scheduleFlush($apiKey, $type);
        } else {
            $this->cleanupBuffer($apiKey, $type);
        }

        $this->checkForDrain();
    }

    protected function completeFailedSend(string $apiKey, string $type, Throwable $throwable): void
    {
        $buffer = $this->buffers[$apiKey][$type] ?? null;
        $buffer?->shift();

        if ($buffer !== null) {
            $buffer->markFlushing(false);
        }

        $this->inFlight--;
        $this->lastFailedDeliveryAt = microtime(true);

        $this->output->error('upstream request failed', [
            'api_key' => $apiKey,
            'type' => $type,
            'exception' => $throwable,
        ]);

        if (($this->buffers[$apiKey][$type] ?? null)?->hasItems()) {
            $this->scheduleFlush($apiKey, $type);
        } else {
            $this->cleanupBuffer($apiKey, $type);
        }

        $this->checkForDrain();
    }

    /**
     * @param  array{status: int, body: mixed, headers: array<string, array<int, string>>}  $response
     */
    protected function pauseAfterTooManyRequests(string $apiKey, string $type, array $response): void
    {
        $now = microtime(true);
        $headers = $response['headers'];
        $reason = Upstream::summarizeBody(Upstream::reasonFromResponseBody($response['body'], 429), $apiKey);

        // Quota 429s carry `x-{type}-quota-reached: 1`. Rate limit and spike protection 429s don't. Plan limits are 403s.
        $defaultPauseSeconds = $this->isQuotaReached($type, $headers) ? $this->quotaPauseSeconds : $this->rateLimitPauseSeconds;
        $retryAfter = $this->parseRetryAfter($headers, $now) ?? $now + $defaultPauseSeconds;

        $this->quotaState->pause($apiKey, $type, $retryAfter, $reason);
        $this->logPause($apiKey, $type, $reason, $retryAfter);
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    protected function isQuotaReached(string $type, array $headers): bool
    {
        $header = match ($type) {
            'errors' => 'x-error-quota-reached',
            'traces' => 'x-trace-quota-reached',
            'logs' => 'x-log-quota-reached',
            default => null,
        };

        return $header !== null && ($headers[$header][0] ?? null) === '1';
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    protected function parseRetryAfter(array $headers, float $now): ?float
    {
        $header = $headers['retry-after'][0] ?? null;

        if ($header === null) {
            return null;
        }

        if (is_numeric($header)) {
            return $now + (float) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : (float) $timestamp;
    }

    protected function logPause(string $apiKey, string $type, string $reason, float $retryAfter): void
    {
        if (($this->lastPauseReasons[$apiKey][$type] ?? null) === $reason) {
            return;
        }

        $this->lastPauseReasons[$apiKey][$type] = $reason;
        $this->loggedPauses[$apiKey][$type] = true;

        $this->output->warning('upstream request paused', [
            'api_key' => $apiKey,
            'type' => $type,
            'reason' => $reason,
            'retry_after' => gmdate(DATE_ATOM, (int) $retryAfter),
        ]);
    }

    protected function isDegraded(float $now): bool
    {
        return $this->lastFailedDeliveryAt !== null
            && $now - $this->lastFailedDeliveryAt < self::DEGRADED_WINDOW_SECONDS;
    }

    /** @param array<string, mixed> $usedLabels */
    protected function uniqueKeyLabel(string $apiKey, array $usedLabels): string
    {
        $label = ApiKey::label($apiKey);
        $uniqueLabel = $label;

        for ($collision = 2; isset($usedLabels[$uniqueLabel]); $collision++) {
            $uniqueLabel = "{$label}#{$collision}";
        }

        return $uniqueLabel;
    }

    protected function recordPausedDrops(string $type, int $count): void
    {
        $this->pausedDropsSinceLastSummary[$type] = ($this->pausedDropsSinceLastSummary[$type] ?? 0) + $count;
    }

    protected function logDeliverySummary(): void
    {
        if ($this->pausedDropsSinceLastSummary !== []) {
            $this->output->warning('payloads dropped while upstream delivery is paused', $this->pausedDropsSinceLastSummary);
            $this->pausedDropsSinceLastSummary = [];
        }

        if ($this->forwardedSinceLastSummary === []) {
            return;
        }

        $total = array_sum($this->forwardedSinceLastSummary);
        $label = $total === 1 ? 'payload' : 'payloads';

        $this->output->info("forwarded {$total} {$label} upstream", $this->forwardedSinceLastSummary);

        $this->forwardedSinceLastSummary = [];
    }

    protected function cleanupBuffer(string $apiKey, string $type): void
    {
        if (($this->buffers[$apiKey][$type] ?? null)?->hasItems() === true) {
            return;
        }

        unset($this->buffers[$apiKey][$type]);

        if (($this->buffers[$apiKey] ?? []) === []) {
            unset($this->buffers[$apiKey]);
        }
    }

    protected function buffer(string $apiKey, string $type): Buffer
    {
        return $this->buffers[$apiKey][$type] ??= new Buffer($apiKey, $type, $this->byteThreshold);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: mixed, headers: array<string, string>}
     */
    protected function result(int $status, mixed $body, array $headers = []): array
    {
        return [
            'status' => $status,
            'body' => $body,
            'headers' => $headers,
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    protected function forwardedHeaders(array $headers): array
    {
        $retryAfter = $headers['retry-after'][0] ?? '';

        return $retryAfter === '' ? [] : ['Retry-After' => $retryAfter];
    }

    protected function checkForDrain(): void
    {
        if (! $this->shuttingDown || $this->inFlight !== 0) {
            return;
        }

        foreach ($this->buffers as $typedBuffers) {
            foreach ($typedBuffers as $buffer) {
                if ($buffer->hasItems()) {
                    return;
                }
            }
        }

        $this->logDeliverySummary();

        foreach ($this->drainCallbacks as $callback) {
            $callback();
        }

        $this->drainCallbacks = [];
    }
}
