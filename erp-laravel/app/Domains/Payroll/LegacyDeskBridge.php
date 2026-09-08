<?php

namespace App\Domains\Payroll;

/**
 * Include a payroll desk PHP page with correct $pdo scope.
 */
final class LegacyDeskBridge
{
    /**
     * @return array{html:string,ok:bool,contentType:string}
     */
    public function render(string $desk): array
    {
        $desk = strtolower(trim($desk));
        $path = DeskShell::entryPath($desk);
        if ($path === null) {
            return [
                'ok' => false,
                'contentType' => 'text/html; charset=UTF-8',
                'html' => '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                    . '<h1>Payroll desk not found</h1>'
                    . '<p>Unknown or missing desk <code>'
                    . htmlspecialchars($desk, ENT_QUOTES, 'UTF-8')
                    . '</code>.</p>'
                    . '</body></html>',
            ];
        }

        if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
            $_GET['module'] = 'payroll';
        }

        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof \PDO) && isset($GLOBALS['control_pdo']) && $GLOBALS['control_pdo'] instanceof \PDO) {
            $pdo = $GLOBALS['control_pdo'];
            $GLOBALS['pdo'] = $pdo;
        }

        ob_start();
        $previousCwd = getcwd() ?: null;
        $deskDir = dirname($path);
        try {
            if ($deskDir !== '' && is_dir($deskDir)) {
                chdir($deskDir);
            }
            require $path;
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($previousCwd !== null) {
                @chdir($previousCwd);
            }

            return [
                'ok' => false,
                'contentType' => 'text/html; charset=UTF-8',
                'html' => '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                    . '<h1>Payroll desk error</h1>'
                    . '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
                    . '</body></html>',
            ];
        }

        if ($previousCwd !== null) {
            @chdir($previousCwd);
        }

        $html = (string) ob_get_clean();
        $contentType = 'text/html; charset=UTF-8';
        $trim = ltrim($html);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $contentType = 'application/json; charset=UTF-8';
        }

        return [
            'ok' => true,
            'contentType' => $contentType,
            'html' => $html,
        ];
    }
}
