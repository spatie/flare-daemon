<?php

namespace Spatie\FlareDaemon;

use DateTimeImmutable;
use DateTimeZone;

class QuotaState
{
    public const ENTITY_TYPES = ['errors', 'traces', 'logs'];

    /**
     * @var array<string, array<string, array{retry_after: float, reason: string}>>
     */
    protected array $states = [];

    public function pause(string $apiKey, string $type, float $retryAfter, string $reason): void
    {
        $this->states[$apiKey][$type] = [
            'retry_after' => $retryAfter,
            'reason' => $reason,
        ];
    }

    public function isPaused(string $apiKey, string $type, float $now): bool
    {
        return ($this->states[$apiKey][$type]['retry_after'] ?? 0.0) > $now;
    }

    public function reason(string $apiKey, string $type, float $now): ?string
    {
        if (! $this->isPaused($apiKey, $type, $now)) {
            return null;
        }

        return $this->states[$apiKey][$type]['reason'];
    }

    public function retryAfter(string $apiKey, string $type, float $now): ?string
    {
        if (! $this->isPaused($apiKey, $type, $now)) {
            return null;
        }

        return (new DateTimeImmutable('@'.(string) (int) $this->states[$apiKey][$type]['retry_after']))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DATE_ATOM);
    }

    /**
     * @return array<int, array{api_key: string, type: string}>
     */
    public function resumeExpired(float $now): array
    {
        $resumed = [];

        foreach ($this->states as $apiKey => $typeStates) {
            foreach ($typeStates as $type => $state) {
                if ($state['retry_after'] <= $now) {
                    $this->resume($apiKey, $type);

                    $resumed[] = [
                        'api_key' => $apiKey,
                        'type' => $type,
                    ];
                }
            }
        }

        return $resumed;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->states);
    }

    protected function resume(string $apiKey, string $type): void
    {
        unset($this->states[$apiKey][$type]);

        if (($this->states[$apiKey] ?? []) === []) {
            unset($this->states[$apiKey]);
        }
    }
}
