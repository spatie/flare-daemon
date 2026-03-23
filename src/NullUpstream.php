<?php

namespace Spatie\FlareDaemon;

use React\Http\Browser;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

class NullUpstream extends Upstream
{
    public function __construct()
    {
        parent::__construct(new Browser);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return PromiseInterface<array{status: int, body: mixed, headers: array<string, array<int, string>>}>
     */
    public function send(string $apiKey, string $type, array $payload): PromiseInterface
    {
        return resolve([
            'status' => 204,
            'body' => null,
            'headers' => [],
        ]);
    }
}
