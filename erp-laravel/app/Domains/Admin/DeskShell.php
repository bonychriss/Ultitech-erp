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
        return ['email-settings', 'whatsapp-settings', 'time-settings'];
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
        $shell = new AdminShell();

        return match ($desk) {
            'email-settings' => $shell->emailSettings($cfg),
            'whatsapp-settings' => $shell->whatsappSettings($cfg),
            'time-settings' => $shell->timeSettings($cfg),
            default => null,
        };
    }
}
