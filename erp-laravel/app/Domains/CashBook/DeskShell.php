<?php

namespace App\Domains\CashBook;

/**
 * Cash Book desk registry — React page keys.
 */
final class DeskShell
{
    /**
     * @return array<string,array{page:string,title:string,header:string}>
     */
    public static function reactDesks(): array
    {
        return [
            'books' => [
                'page' => 'books',
                'title' => 'Cash Book',
                'header' => 'Cash Book',
            ],
            'book' => [
                'page' => 'book',
                'title' => 'Cash Book',
                'header' => 'Cash Book',
            ],
            'categories' => [
                'page' => 'categories',
                'title' => 'Categories',
                'header' => 'Categories',
            ],
            'reports' => [
                'page' => 'reports',
                'title' => 'Reports',
                'header' => 'Reports',
            ],
            'import' => [
                'page' => 'import',
                'title' => 'Import Excel',
                'header' => 'Import Excel',
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
