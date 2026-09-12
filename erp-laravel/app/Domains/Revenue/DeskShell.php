<?php

namespace App\Domains\Revenue;

/**
 * Revenue desk registry — React pages under modules/revenue/frontend.
 */
final class DeskShell
{
    /**
     * @return array<string,array{page:string,title:string,header:string}>
     */
    public static function reactDesks(): array
    {
        return [
            'list' => [
                'page' => 'list',
                'title' => 'Revenues',
                'header' => 'Revenues',
            ],
            'create' => [
                'page' => 'create',
                'title' => 'Create Revenue',
                'header' => 'Create Revenue',
            ],
            'import' => [
                'page' => 'import',
                'title' => 'Import Revenue',
                'header' => 'Import Revenue',
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

        return implode('|', $keys) ?: 'list';
    }
}
