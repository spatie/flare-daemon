<?php

use Spatie\FlareDaemon\Support\Version;

it('returns dev when no version source is available', function () {
    expect(Version::detect(
        env: [],
        composerVersion: '',
        fileVersion: '',
    ))->toBe('dev');
});

it('prefers an explicit environment version override', function () {
    expect(Version::detect(
        env: ['FLARE_DAEMON_VERSION' => '1.2.3'],
        composerVersion: '0.0.0',
        fileVersion: '9.9.9',
    ))->toBe('1.2.3');
});

it('uses the composer-installed package version when available', function () {
    expect(Version::detect(
        env: [],
        composerVersion: '1.2.3',
        fileVersion: null,
    ))->toBe('1.2.3');
});

it('falls back to the packaged version file when present', function () {
    expect(Version::detect(
        env: [],
        composerVersion: '',
        fileVersion: '1.2.3',
    ))->toBe('1.2.3');
});
