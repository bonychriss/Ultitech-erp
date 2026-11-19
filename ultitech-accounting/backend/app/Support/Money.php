<?php

namespace App\Support;

use InvalidArgumentException;

class Money
{
    public static function normalize(mixed $value, int $scale = 4): string
    {
        if ($value === null || $value === '') {
            return number_format(0, $scale, '.', '');
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Amount must be numeric.');
        }

        return number_format((float) $value, $scale, '.', '');
    }

    public static function add(string $a, string $b, int $scale = 4): string
    {
        return bcadd(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function sub(string $a, string $b, int $scale = 4): string
    {
        return bcsub(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function compare(string $a, string $b, int $scale = 4): int
    {
        return bccomp(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function equals(string $a, string $b, int $scale = 4): bool
    {
        return self::compare($a, $b, $scale) === 0;
    }

    public static function isZero(string $value, int $scale = 4): bool
    {
        return self::equals($value, '0', $scale);
    }

    public static function multiply(string $amount, string $rate, int $scale = 4): string
    {
        return bcmul(self::normalize($amount, $scale), self::normalize($rate, 8), $scale);
    }
}
