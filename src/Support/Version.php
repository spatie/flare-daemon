<?php

namespace Spatie\FlareDaemon\Support;

use Composer\InstalledVersions;

class Version
{
    /**
     * @param  array<string, string> | null  $env
     */
    public static function detect(?array $env = null, ?string $composerVersion = null, ?string $fileVersion = null): string
    {
        $env ??= array_filter($_SERVER, 'is_string');

        $environmentVersion = $env['FLARE_DAEMON_VERSION'] ?? null;

        if ($environmentVersion === '') {
            $environmentVersion = null;
        }

        if ($environmentVersion === null) {
            $environmentVersion = getenv('FLARE_DAEMON_VERSION') ?: null;
        }

        if ($environmentVersion !== null) {
            return ltrim($environmentVersion, 'v');
        }

        $composerVersion ??= self::composerVersion();

        if (is_string($composerVersion) && $composerVersion !== '') {
            return ltrim($composerVersion, 'v');
        }

        $fileVersion ??= self::fileVersion();

        if (is_string($fileVersion) && $fileVersion !== '') {
            return ltrim($fileVersion, 'v');
        }

        return 'dev';
    }

    protected static function composerVersion(): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        if (! InstalledVersions::isInstalled('spatie/flare-daemon')) {
            return null;
        }

        return InstalledVersions::getPrettyVersion('spatie/flare-daemon');
    }

    protected static function fileVersion(): ?string
    {
        $path = dirname(__DIR__, 2).'/version.txt';

        if (! is_file($path)) {
            return null;
        }

        $contents = trim((string) file_get_contents($path));

        return $contents === '' ? null : $contents;
    }
}
