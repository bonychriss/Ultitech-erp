<?php

namespace App\Domains\Admin;

/**
 * Admin desks served through erp-laravel (React shells under admin/*-ui).
 */
final class DeskShell
{
    /** @return list<string> */
    public static function laravelDesks(): array
    {
        return ['email-settings'];
    }

    public static function deskRegex(): string
    {
        return implode('|', array_map(
            static fn (string $d): string => preg_quote($d, '/'),
            self::laravelDesks()
        ));
    }

    /**
     * @return array{
     *   pageTitle:string,
     *   bodyClass:string,
     *   headMarkup:string,
     *   footerScripts:string,
     *   employeeHeaderTitle:string,
     *   hideHeaderCompanyBranding:bool,
     *   employeeHeaderExtraClass:string,
     *   mainRootClass:string
     * }|null
     */
    public function viewData(string $desk, array $cfg = []): ?array
    {
        $desk = strtolower(trim($desk));
        if ($desk !== 'email-settings') {
            return null;
        }

        return (new AdminShell())->emailSettings($cfg);
    }
}
