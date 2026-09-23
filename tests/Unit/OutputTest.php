<?php

use Spatie\FlareDaemon\Support\Output;

it('writes debug messages only when verbose is enabled', function () {
    $stdout = fopen('php://temp', 'w+');
    assert(is_resource($stdout));

    $quiet = new Output($stdout, null, verbose: false);
    $quiet->debug('should not appear');

    rewind($stdout);
    expect(stream_get_contents($stdout))->toBe('');

    $verbose = new Output($stdout, null, verbose: true);
    $verbose->debug('should appear');

    rewind($stdout);
    expect(stream_get_contents($stdout))->toContain('DEBUG')
        ->toContain('should appear');
});

it('always writes info messages regardless of verbose setting', function () {
    $stdout = fopen('php://temp', 'w+');
    assert(is_resource($stdout));

    $output = new Output($stdout, null, verbose: false);
    $output->info('always visible');

    rewind($stdout);
    expect(stream_get_contents($stdout))->toContain('INFO')
        ->toContain('always visible');
});

it('redacts credentials in structured logs and exception messages', function () {
    $apiKey = 'secret/key"with-escaping-aB3x9K2m';
    $capture = makeOutputWithCapture();

    $capture['output']->error('upstream request failed', [
        'api_key' => $apiKey,
        'body' => ['message' => "Rejected {$apiKey}"],
        'exception' => new RuntimeException("Rejected {$apiKey}"),
        'url' => new class($apiKey) implements Stringable
        {
            public function __construct(protected string $apiKey) {}

            public function __toString(): string
            {
                return "https://example.com/?key={$this->apiKey}";
            }
        },
    ]);

    $log = readStream($capture['stderr']);

    expect($log)->toContain('...aB3x9K2m');
    expect($log)->not->toContain($apiKey, 'secret', 'with-escaping');
});

it('labels short api keys without rewriting other log text', function () {
    $capture = makeOutputWithCapture();

    $capture['output']->error('upstream request failed', [
        'api_key' => '20',
        'body' => 'rejected at 2026-01-20',
    ]);

    expect(readStream($capture['stderr']))
        ->toContain('"api_key":"[redacted]"')
        ->toContain('rejected at 2026-01-20');
});
