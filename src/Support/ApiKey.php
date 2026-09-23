<?php

namespace Spatie\FlareDaemon\Support;

class ApiKey
{
    protected const VISIBLE_SUFFIX_LENGTH = 8;

    public static function label(string $apiKey): string
    {
        return self::isMaskable($apiKey) ? '...'.substr($apiKey, -self::VISIBLE_SUFFIX_LENGTH) : '[redacted]';
    }

    public static function redact(string $text, ?string $apiKey): string
    {
        if ($apiKey === null || ! self::isMaskable($apiKey)) {
            return $text;
        }

        return str_replace($apiKey, self::label($apiKey), $text);
    }

    protected static function isMaskable(string $apiKey): bool
    {
        return strlen($apiKey) > self::VISIBLE_SUFFIX_LENGTH;
    }
}
