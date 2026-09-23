<?php

use React\Http\Message\Response;

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

        return $statusBody['keys']['[redacted]']['traces']['paused'] ?? false;
    });

    $statusWhilePaused = \React\Async\await($daemon['client']->get($daemon['daemon_url'].'/status'));
    $pausedBody = json_decode((string) $statusWhilePaused->getBody(), true);

    expect($pausedBody['keys']['[redacted]']['traces']['paused'])->toBeTrue()
        ->and($pausedBody['keys']['[redacted]']['traces']['pause_reason'])->toBe('Trace quota exceeded');

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

it('lets diagnostic requests bypass a quota pause', function () {
    $upstream = createUpstreamFixture(fn () => new Response(429, ['Content-Type' => 'text/plain'], 'Error quota exceeded'));
    $daemon = createDaemonFixture($upstream['base_url'], ['flush_after' => 0.01, 'default_retry_after' => 60]);
    $headers = ['Content-Type' => 'application/json', 'X-API-Token' => 'example-api-key-aB3x9K2m'];

    \React\Async\await($daemon['client']->post($daemon['daemon_url'].'/v1/errors', $headers, encodePayload(['message' => 'normal'])));
    waitUntil(fn () => fetchStatus($daemon)['keys']['...aB3x9K2m']['errors']['paused'] ?? false);

    $testResponse = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [...$headers, 'X-Flare-Test' => '1'],
        encodePayload(['message' => 'test']),
    ));

    expect($testResponse->getStatusCode())->toBe(429)
        ->and((string) $testResponse->getBody())->toBe('Error quota exceeded')
        ->and($upstream['requests'])->toHaveCount(2);
});

it('keeps forwarding after a forbidden response and reports the daemon as degraded', function () {
    $responseCount = 0;
    $upstream = createUpstreamFixture(function () use (&$responseCount) {
        return ++$responseCount === 1
            ? new Response(403, ['Content-Type' => 'text/html'], '<html>Cloudflare: request blocked</html>')
            : new Response(204);
    });
    $daemon = createDaemonFixture($upstream['base_url']);
    $headers = ['Content-Type' => 'application/json', 'X-API-Token' => 'api-key'];

    \React\Async\await($daemon['client']->post($daemon['daemon_url'].'/v1/errors', $headers, encodePayload(['message' => 'blocked'])));
    waitUntil(fn () => fetchStatus($daemon)['degraded']);
    \React\Async\await($daemon['client']->post($daemon['daemon_url'].'/v1/errors', $headers, encodePayload(['message' => 'next'])));
    waitUntil(fn () => $daemon['ingest']->stats()['forwarded'] === 1);

    expect(upstreamBody($upstream['requests'], 1))->toBe(['message' => 'next'])
        ->and(fetchStatus($daemon)['keys'])->toBe([]);
});
