<?php

namespace Spatie\FlareDaemon\Support;

class ApiKey
{
    public static function label(string $apiKey): string
    {
        return strlen($apiKey) > 8 ? '...'.substr($apiKey, -8) : '[redacted]';
    }

    public static function redact(string $text, ?string $apiKey): string
    {
        if ($apiKey === null || $apiKey === '') {
            return $text;
        }

        return str_replace($apiKey, self::label($apiKey), $text);
    }
}
