<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Identificador de correlação e origem da unidade de trabalho atual. */
class Correlation
{
    private static ?string $id = null;

    private static ?string $ip = null;

    public static function start(?string $ip = null): string
    {
        self::$ip = $ip;

        return self::$id = (string) Str::uuid();
    }

    public static function id(): ?string
    {
        return self::$id;
    }

    public static function ip(): ?string
    {
        return self::$ip;
    }

    public static function clear(): void
    {
        self::$id = self::$ip = null;
    }
}
