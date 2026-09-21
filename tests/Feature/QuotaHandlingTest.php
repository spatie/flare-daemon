<?php

use React\Http\Message\Response;
use Spatie\FlareDaemon\Support\Output;

it('pauses a key and type after a 429 response and resumes after retry after', function () {
    $responseCount = 0;

    $upstream = createUpstreamFixture(function () use (&$responseCount) {
        $responseCount++;

        return match ($responseCount) {
            1 => new Response(429, ['Retry-After' => '1', 'Content-Type' => 'text/plain'], 'Trace quota exceeded'),
            default => new Response(201, ['Content-Type' => 'application/json'], '{"ok":true}'),
        };
    });

    $daemon = createDaemonFixture($upstream['base_url'], [
        'flush_after' => 0.01,
        'default_retry_after' => 1,
    ]);

    \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/traces',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
        ],
        encodePayload(['trace' => 1]),
    ));

    waitUntil(fn () => $upstream['requests']->count() === 1);
    waitUntil(function () use ($daemon) {
        $statusResponse = \React\Async\await($daemon['client']->get($daemon['daemon_url'].'/status'));
        $statusBody = json_decode((string) $statusResponse->getBody(), true);

        return $statusBody['keys'][Output::apiKeyId('api-key')]['traces']['paused'] ?? false;
    });

    $statusWhilePaused = \React\Async\await($daemon['client']->get($daemon['daemon_url'].'/status'));
    $pausedBody = json_decode((string) $statusWhilePaused->getBody(), true);

    expect($pausedBody['keys'][Output::apiKeyId('api-key')]['traces']['paused'])->toBeTrue()
        ->and($pausedBody['keys'][Output::apiKeyId('api-key')]['traces']['last_429_reason'])->toBe('Trace quota exceeded');

    \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/traces',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
        ],
        encodePayload(['trace' => 2]),
    ));

    waitFor(1.05);

    \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/traces',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
        ],
        encodePayload(['trace' => 3]),
    ));

    waitFor(0.05);

    expect($upstream['requests'])->toHaveCount(2)
        ->and(upstreamBody($upstream['requests'], 0))->toBe(['trace' => 1])
        ->and(upstreamBody($upstream['requests'], 1))->toBe(['trace' => 3]);
});

it('keeps temporary pause state for normal payloads while still allowing diagnostic test requests', function () {
    $upstream = createUpstreamFixture(fn () => new Response(403, ['Content-Type' => 'text/plain'], 'Invalid API key'));
    $daemon = createDaemonFixture($upstream['base_url'], ['flush_after' => 0.01]);

    \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'example-private-api-key-aB3x9K2m',
        ],
        encodePayload(['message' => 'normal']),
    ));

    waitFor(0.05);

    $testResponse = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'example-private-api-key-aB3x9K2m',
            'X-Flare-Test' => '1',
        ],
        encodePayload(['message' => 'test']),
    ));

    $statusResponse = \React\Async\await($daemon['client']->get($daemon['daemon_url'].'/status'));
    $statusBody = json_decode((string) $statusResponse->getBody(), true);

    expect((string) $statusResponse->getBody())->not->toContain('example-private-api-key-aB3x9K2m')
        ->and($statusBody['degraded'])->toBeTrue()
        ->and($statusBody['keys']['...aB3x9K2m']['errors']['pause_reason'])->toBe('HTTP 403')
        ->and($testResponse->getStatusCode())->toBe(403)
        ->and((string) $testResponse->getBody())->toBe('Invalid API key')
        ->and($statusBody['keys']['...aB3x9K2m']['errors']['paused'])->toBeTrue()
        ->and($statusBody['keys']['...aB3x9K2m']['traces']['paused'])->toBeFalse()
        ->and($statusBody['keys']['...aB3x9K2m']['logs']['paused'])->toBeFalse();
});

it('recovers from a forbidden upstream response without pausing other telemetry', function (string $body) {
    $responseCount = 0;
    $upstream = createUpstreamFixture(function () use (&$responseCount, $body) {
        return ++$responseCount === 1
            ? new Response(403, ['Content-Type' => 'text/html'], $body)
            : new Response(204);
    });
    $daemon = createDaemonFixture($upstream['base_url']);
    $headers = ['Content-Type' => 'application/json', 'X-API-Token' => 'api-key'];

    \React\Async\await($daemon['client']->post($daemon['daemon_url'].'/v1/errors', $headers, encodePayload(['message' => 'blocked'])));
    waitUntil(fn () => $daemon['quota_state']->isPaused('api-key', 'errors', microtime(true)));

    expect($daemon['quota_state']->isPermanent('api-key', 'errors'))->toBeFalse()
        ->and($daemon['quota_state']->isPaused('api-key', 'traces', microtime(true)))->toBeFalse()
        ->and($daemon['quota_state']->isPaused('api-key', 'logs', microtime(true)))->toBeFalse();

    \React\Async\await($daemon['client']->post($daemon['daemon_url'].'/v1/errors', $headers, encodePayload(['message' => 'during pause'])));
    waitFor(0.05);
    expect($upstream['requests'])->toHaveCount(1);

    waitUntil(fn () => ! $daemon['quota_state']->isPaused('api-key', 'errors', microtime(true)), timeout: 2);
    \React\Async\await($daemon['client']->post($daemon['daemon_url'].'/v1/errors', $headers, encodePayload(['message' => 'after pause'])));
    waitUntil(fn () => $daemon['ingest']->stats()['forwarded'] === 1);
    expect($upstream['requests'])->toHaveCount(2)
        ->and($daemon['ingest']->status()['degraded'])->toBeFalse();
})->with(['<html>Cloudflare: request blocked</html>', 'Invalid API key']);
