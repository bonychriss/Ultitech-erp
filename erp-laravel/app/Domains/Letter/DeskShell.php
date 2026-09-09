<?php

namespace App\Domains\Letter;

/**
 * Letter desk registry — React shells.
 */
final class DeskShell
{
    /**
     * @return array<string,array{page:string,title:string,header:string}>
     */
    public static function reactDesks(): array
    {
        return [
            'compose' => [
                'page' => 'compose',
                'title' => 'Compose letter',
                'header' => 'Compose letter',
            ],
        ];
    }

    /** @return list<string> */
    public static function laravelDesks(): array
    {
        return array_keys(self::reactDesks());
    }

    public static function isReactDesk(string $desk): bool
    {
        return isset(self::reactDesks()[strtolower(trim($desk))]);
    }

    public static function deskRegex(): string
    {
        $keys = array_map(static fn (string $k): string => preg_quote($k, '/'), self::laravelDesks());

        return implode('|', $keys);
    }
}
