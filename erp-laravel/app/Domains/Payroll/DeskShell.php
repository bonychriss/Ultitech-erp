<?php

namespace App\Domains\Payroll;

/**
 * Payroll desk registry — React shells + optional legacy PHP desks.
 */
final class DeskShell
{
    /**
     * desk key => React page id (window.__PAYROLL_PAGE__) and titles.
     *
     * @return array<string,array{page:string,title:string,header:string}>
     */
    public static function reactDesks(): array
    {
        return [
            'run-payroll' => [
                'page' => 'run-payroll',
                'title' => 'Run payroll',
                'header' => 'Run payroll',
            ],
        ];
    }

    /**
     * desk key => path relative to app root (legacy PHP fallback).
     *
     * @return array<string,string>
     */
    public static function deskEntries(): array
    {
        return [
            'run-payroll' => 'modules/payroll/desks/run_payroll.php',
        ];
    }

    /** @return list<string> */
    public static function laravelDesks(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::reactDesks()),
            array_keys(self::deskEntries())
        )));
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

    public static function entryPath(string $desk): ?string
    {
        $desk = strtolower(trim($desk));
        $entries = self::deskEntries();
        if (!isset($entries[$desk])) {
            return null;
        }

        $root = rtrim((string) config('erp.app_root'), '\\/');
        $path = $root . '/' . ltrim($entries[$desk], '/');

        return is_file($path) ? $path : null;
    }
}
