<?php

namespace App\Domains\Stock;

/**
 * Include any stock desk PHP page with correct $pdo / $stockBasePath scope.
 */
final class LegacyDeskBridge
{
    /**
     * @return array{html:string,ok:bool,contentType:string}
     */
    public function render(string $desk = 'dashboard'): array
    {
        $desk = strtolower(trim($desk));
        if ($desk === '' || $desk === 'home') {
            $desk = 'dashboard';
        }

        $path = DeskShell::entryPath($desk);
        if ($path === null) {
            return [
                'ok' => false,
                'contentType' => 'text/html; charset=UTF-8',
                'html' => '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                    . '<h1>Stock desk not found</h1>'
                    . '<p>Unknown or missing desk <code>'
                    . htmlspecialchars($desk, ENT_QUOTES, 'UTF-8')
                    . '</code>.</p>'
                    . '</body></html>',
            ];
        }

        if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
            $_GET['module'] = 'stocks';
        }

        // Asset base must point at physical /stock/ (not stock.php SCRIPT_NAME).
        $stockBasePath = function_exists('app_url')
            ? rtrim((string) app_url('/stock'), '/') . '/'
            : '/stock/';
        $rootPath = function_exists('app_url')
            ? rtrim((string) app_url('/'), '/') . '/'
            : '/';

        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof \PDO) && isset($GLOBALS['control_pdo']) && $GLOBALS['control_pdo'] instanceof \PDO) {
            $pdo = $GLOBALS['control_pdo'];
            $GLOBALS['pdo'] = $pdo;
        }

        ob_start();
        $previousCwd = getcwd() ?: null;
        $deskDir = dirname($path);
        try {
            // Legacy stock pages use relative requires (../../config/...).
            // Those resolve against CWD, not the included file — match direct PHP execution.
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
                    . '<h1>Stock desk error</h1>'
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
