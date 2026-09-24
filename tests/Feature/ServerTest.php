<?php

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Spatie\FlareDaemon\Ingest;
use Spatie\FlareDaemon\QuotaState;
use Spatie\FlareDaemon\Server;
use Spatie\FlareDaemon\Upstream;

it('exposes health and status endpoints', function () {
    $upstream = createUpstreamFixture(fn () => new Response(201, ['Content-Type' => 'application/json'], '{"ok":true}'));
    $daemon = createDaemonFixture($upstream['base_url']);

    $healthResponse = \React\Async\await($daemon['client']->get($daemon['daemon_url'].'/health'));
    $statusResponse = \React\Async\await($daemon['client']->get($daemon['daemon_url'].'/status'));

    expect($healthResponse->getStatusCode())->toBe(200)
        ->and(json_decode((string) $healthResponse->getBody(), true))->toBe(['status' => 'ok'])
        ->and($statusResponse->getStatusCode())->toBe(200)
        ->and(json_decode((string) $statusResponse->getBody(), true))->toBe(['degraded' => false, 'total_received' => 0, 'total_buffered' => 0, 'total_forwarded' => 0, 'total_dropped' => 0, 'keys' => []]);
});

it('keeps status records separate when masked key labels collide', function () {
    $rejectWithKeyInReason = fn (ServerRequestInterface $request) => new Response(429, [], 'Rate limit exceeded for '.$request->getHeaderLine('X-API-Token'));
    $upstream = createUpstreamFixture($rejectWithKeyInReason);
    $daemon = createDaemonFixture($upstream['base_url'], ['rate_limit_pause' => 60]);
    $firstKey = 'example-first-private-key-aB3x9K2m';
    $secondKey = 'example-second-private-key-aB3x9K2m';

    foreach ([$firstKey => 'errors', $secondKey => 'traces'] as $apiKey => $type) {
        \React\Async\await($daemon['client']->post(
            $daemon['daemon_url']."/v1/{$type}",
            ['Content-Type' => 'application/json', 'X-API-Token' => $apiKey],
            encodePayload(['message' => 'blocked']),
        ));

        waitUntil(fn () => $daemon['quota_state']->isPaused($apiKey, $type, microtime(true)));
    }

    $response = \React\Async\await($daemon['client']->get($daemon['daemon_url'].'/status'));
    $status = json_decode((string) $response->getBody(), true);

    expect((string) $response->getBody())->not->toContain($firstKey);
    expect((string) $response->getBody())->not->toContain($secondKey);
    expect($status['keys'])->toHaveCount(2)
        ->and($status['keys']['...aB3x9K2m']['errors']['paused'])->toBeTrue()
        ->and($status['keys']['...aB3x9K2m']['traces']['paused'])->toBeFalse()
        ->and($status['keys']['...aB3x9K2m#2']['errors']['paused'])->toBeFalse()
        ->and($status['keys']['...aB3x9K2m#2']['traces']['paused'])->toBeTrue()
        ->and($upstream['requests'][0]['headers']['X-API-Token'][0] ?? null)->toBe($firstKey)
        ->and($upstream['requests'][1]['headers']['X-API-Token'][0] ?? null)->toBe($secondKey);
});

it('validates incoming requests', function () {
    $upstream = createUpstreamFixture(fn () => new Response(201, ['Content-Type' => 'application/json'], '{"ok":true}'));
    $daemon = createDaemonFixture($upstream['base_url']);

    $missingKey = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        ['Content-Type' => 'application/json'],
        encodePayload(['message' => 'hello']),
    ));

    $invalidJson = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
        ],
        '{invalid',
    ));

    expect($missingKey->getStatusCode())->toBe(422)
        ->and(json_decode((string) $missingKey->getBody(), true))->toBe(['message' => 'Missing API key'])
        ->and($invalidJson->getStatusCode())->toBe(422)
        ->and(json_decode((string) $invalidJson->getBody(), true))->toBe(['message' => 'Invalid JSON']);
});

it('buffers normal payloads and flushes them asynchronously', function () {
    $upstream = createUpstreamFixture(fn () => new Response(201, ['Content-Type' => 'application/json'], '{"ok":true}'));
    $daemon = createDaemonFixture($upstream['base_url'], ['flush_after' => 0.02]);

    $response = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
        ],
        encodePayload(['message' => 'normal']),
    ));

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getHeaderLine('Content-Type'))->toContain('application/json')
        ->and(json_decode((string) $response->getBody(), true))->toBe(['status' => 'accepted']);

    waitFor(0.05);

    expect($upstream['requests'])->toHaveCount(1)
        ->and(upstreamPath($upstream['requests'], 0))->toBe('/v1/errors')
        ->and(upstreamBody($upstream['requests'], 0))->toBe(['message' => 'normal']);
});

it('bypasses the normal buffer for test payloads and returns the upstream response', function () {
    $upstream = createUpstreamFixture(fn () => new Response(201, ['Content-Type' => 'application/json'], '{"status":"ok"}'));
    $daemon = createDaemonFixture($upstream['base_url'], ['flush_after' => 1.0]);

    \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
        ],
        encodePayload(['message' => 'normal']),
    ));

    $testResponse = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
            'X-Flare-Test' => '1',
        ],
        encodePayload(['message' => 'test']),
    ));

    expect($testResponse->getStatusCode())->toBe(201)
        ->and($testResponse->getHeaderLine('Content-Type'))->toContain('application/json')
        ->and(json_decode((string) $testResponse->getBody(), true))->toBe([
            'status' => 'ok',
        ]);

    waitUntil(fn () => count($upstream['requests']) >= 2);

    $testIndex = null;
    $normalIndex = null;

    foreach ($upstream['requests'] as $i => $request) {
        assert(is_array($request['body']));

        if ($request['body']['message'] === 'test') {
            $testIndex = $i;
        } else {
            $normalIndex = $i;
        }
    }

    expect($testIndex)->not->toBeNull()
        ->and($normalIndex)->not->toBeNull();

    assert($testIndex !== null && $normalIndex !== null);

    expect(upstreamBody($upstream['requests'], $testIndex))->toBe(['message' => 'test'])
        ->and(upstreamBody($upstream['requests'], $normalIndex))->toBe(['message' => 'normal']);
});

it('returns upstream errors for test payloads without mutating daemon quota state', function () {
    $upstream = createUpstreamFixture(fn () => new Response(429, ['Retry-After' => '60', 'Content-Type' => 'text/plain'], 'Trace quota exceeded'));
    $daemon = createDaemonFixture($upstream['base_url'], ['flush_after' => 1.0]);

    $testResponse = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/traces',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
            'X-Flare-Test' => '1',
        ],
        encodePayload(['message' => 'test']),
    ));

    expect($testResponse->getStatusCode())->toBe(429)
        ->and($testResponse->getHeaderLine('Content-Type'))->toContain('text/plain')
        ->and($testResponse->getHeaderLine('Retry-After'))->toBe('60')
        ->and((string) $testResponse->getBody())->toBe('Trace quota exceeded')
        ->and(fetchStatus($daemon))->toBe(['degraded' => false, 'total_received' => 0, 'total_buffered' => 0, 'total_forwarded' => 0, 'total_dropped' => 0, 'keys' => []]);
});

it('returns validation and rejection responses for test payloads', function () {
    $responseCount = 0;

    $upstream = createUpstreamFixture(function () use (&$responseCount) {
        $responseCount++;

        return match ($responseCount) {
            1 => new Response(403, ['Content-Type' => 'text/plain'], 'Invalid API key'),
            2 => new Response(403, ['Content-Type' => 'application/json'], 'true'),
            default => new Response(422, ['Content-Type' => 'application/json'], '{"message":"The given data was invalid.","errors":{"payload":["Invalid"]}}'),
        };
    });
    $daemon = createDaemonFixture($upstream['base_url'], ['flush_after' => 1.0]);

    $forbiddenResponse = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
            'X-Flare-Test' => '1',
        ],
        encodePayload(['message' => 'test']),
    ));

    $scalarResponse = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
            'X-Flare-Test' => '1',
        ],
        encodePayload(['message' => 'test']),
    ));

    $invalidResponse = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
            'X-Flare-Test' => '1',
        ],
        encodePayload(['message' => 'test']),
    ));

    expect($forbiddenResponse->getStatusCode())->toBe(403)
        ->and($forbiddenResponse->getHeaderLine('Content-Type'))->toContain('text/plain')
        ->and((string) $forbiddenResponse->getBody())->toBe('Invalid API key')
        ->and($scalarResponse->getStatusCode())->toBe(403)
        ->and($scalarResponse->getHeaderLine('Content-Type'))->toContain('application/json')
        ->and((string) $scalarResponse->getBody())->toBe('true')
        ->and($invalidResponse->getStatusCode())->toBe(422)
        ->and($invalidResponse->getHeaderLine('Content-Type'))->toContain('application/json')
        ->and(json_decode((string) $invalidResponse->getBody(), true))->toBe([
            'message' => 'The given data was invalid.',
            'errors' => ['payload' => ['Invalid']],
        ]);
});

it('returns a daemon error when the upstream diagnostic request fails', function () {
    $daemon = createDaemonFixture('http://127.0.0.1:1', ['flush_after' => 1.0]);

    $response = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
            'X-Flare-Test' => '1',
        ],
        encodePayload(['message' => 'test']),
    ));

    expect($response->getStatusCode())->toBe(502)
        ->and(json_decode((string) $response->getBody(), true))->toBe(['message' => 'Upstream request failed']);
});

it('returns a daemon error when a diagnostic request arrives during shutdown', function () {
    $upstream = createUpstreamFixture(fn () => new Response(201, ['Content-Type' => 'application/json'], '{"ok":true}'));
    $daemon = createDaemonFixture($upstream['base_url'], ['flush_after' => 1.0]);

    $daemon['ingest']->shutdown();

    $response = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
            'X-Flare-Test' => '1',
        ],
        encodePayload(['message' => 'test']),
    ));

    expect($response->getStatusCode())->toBe(503)
        ->and(json_decode((string) $response->getBody(), true))->toBe(['message' => 'Daemon is shutting down']);
});

it('flushes payloads immediately without waiting for time or size thresholds', function () {
    $upstream = createUpstreamFixture(fn () => new Response(201, ['Content-Type' => 'application/json'], '{"ok":true}'));
    $daemon = createDaemonFixture($upstream['base_url'], [
        'flush_after' => 60.0,
        'maintenance_interval' => 60.0,
        'byte_threshold' => 10_000_000,
    ]);

    $response = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
        ],
        encodePayload(['message' => 'immediate']),
    ));

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getHeaderLine('Content-Type'))->toContain('application/json')
        ->and(json_decode((string) $response->getBody(), true))->toBe(['status' => 'accepted']);

    waitUntil(fn () => count($upstream['requests']) >= 1, timeout: 0.5);

    expect($upstream['requests'])->toHaveCount(1)
        ->and(upstreamBody($upstream['requests'], 0))->toBe(['message' => 'immediate']);
});

it('treats upstream 204 as success for normal payloads', function () {
    $captured = makeOutputWithCapture();
    $upstream = createUpstreamFixture(fn () => new Response(204, [], ''));
    $address = freeLocalAddress();
    $browser = (new Browser)->withRejectErrorResponse(false)->withTimeout(1.0);
    $upstreamClient = new Upstream($browser, $upstream['base_url'], 'FlareDaemon/tests');
    $ingest = new Ingest(
        loop: Loop::get(),
        upstream: $upstreamClient,
        output: $captured['output'],
        quotaState: new QuotaState,
        flushAfterSeconds: 0.05,
        maintenanceIntervalSeconds: 0.01,
    );
    $server = new Server(
        loop: Loop::get(),
        ingest: $ingest,
        output: $captured['output'],
        listenAddress: $address,
    );
    $server->listen();

    rememberShutdown(fn () => $ingest->shutdown());
    rememberCloser($server);

    $response = \React\Async\await($browser->post(
        'http://'.$address.'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
        ],
        encodePayload(['message' => 'test-204']),
    ));

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getHeaderLine('Content-Type'))->toContain('application/json')
        ->and(json_decode((string) $response->getBody(), true))->toBe(['status' => 'accepted']);

    waitUntil(fn () => count($upstream['requests']) >= 1);

    expect($upstream['requests'])->toHaveCount(1)
        ->and(upstreamBody($upstream['requests'], 0))->toBe(['message' => 'test-204']);

    $stderr = readStream($captured['stderr']);

    expect($stderr)->not->toContain('upstream request failed');
});

it('returns the 204 upstream response for test payloads', function () {
    $upstream = createUpstreamFixture(fn () => new Response(204, [], ''));
    $daemon = createDaemonFixture($upstream['base_url'], ['flush_after' => 1.0]);

    $testResponse = \React\Async\await($daemon['client']->post(
        $daemon['daemon_url'].'/v1/errors',
        [
            'Content-Type' => 'application/json',
            'X-API-Token' => 'api-key',
            'X-Flare-Test' => '1',
        ],
        encodePayload(['message' => 'test-diagnostic-204']),
    ));

    expect($testResponse->getStatusCode())->toBe(204)
        ->and((string) $testResponse->getBody())->toBe('');
});

it('logs a clear error when the port is already in use', function () {
    $address = freeLocalAddress();
    $port = explode(':', $address)[1];

    $blocker = new SocketServer($address, [], Loop::get());
    rememberCloser($blocker);

    $captured = makeOutputWithCapture();
    $browser = (new Browser)->withRejectErrorResponse(false)->withTimeout(1.0);
    $upstream = new Upstream($browser, 'http://127.0.0.1:1', 'FlareDaemon/tests');
    $ingest = new Ingest(
        loop: Loop::get(),
        upstream: $upstream,
        output: $captured['output'],
        quotaState: new QuotaState,
        maintenanceIntervalSeconds: 60.0,
    );
    rememberShutdown(fn () => $ingest->shutdown());

    $server = new Server(
        loop: Loop::get(),
        ingest: $ingest,
        output: $captured['output'],
        listenAddress: $address,
    );

    expect(fn () => $server->listen())->toThrow(RuntimeException::class);

    $stderr = readStream($captured['stderr']);

    expect($stderr)
        ->toContain("Port {$port} is already in use")
        ->toContain("lsof -i :{$port}")
        ->toContain('FLARE_DAEMON_LISTEN=127.0.0.1:9999');
});
