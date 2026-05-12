<?php

use Symfony\Component\Process\Process;

it('allows operators to configure php memory limit through helm', function () {
    $process = new Process([
        'helm',
        'template',
        'flare-daemon',
        'charts/flare-daemon',
        '--set',
        'phpMemoryLimit=192M',
    ], dirname(__DIR__, 2));

    $process->mustRun();

    expect($process->getOutput())
        ->toContain('name: PHP_MEMORY_LIMIT')
        ->toContain('value: "192M"');
});

it('passes php memory limit from the environment to the daemon process', function () {
    $entrypoint = file_get_contents(dirname(__DIR__, 2).'/docker/entrypoint.sh');
    assert(is_string($entrypoint));

    expect($entrypoint)
        ->toContain('PHP_MEMORY_LIMIT')
        ->toContain('-d memory_limit="${PHP_MEMORY_LIMIT:-128M}"');
});
