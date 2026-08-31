<?php

declare(strict_types=1);

/**
 * Asset/URL helpers for the employee "My Vouchers" React desk.
 *
 * To avoid duplicating the Vite build, the employee page reuses the SAME
 * compiled assets that power admin/all-vouchers.php (built into
 * admin/vouchers-ui/frontend/dist). Only the JSON API base differs so that
 * employee-scoped permissions and data are served from this folder.
 */

function employeeVouchersUiWebDir(): string
{
    // Directory of the currently-running script (e.g. /employee for my-vouchers.php).
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/employee'), '/');
    }
    return '/employee';
}

/**
 * @return array{assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function employeeVouchersUiLoadReactAssets(): ?array
{
    // Reuse the admin build (single source of truth for the compiled UI).
    $adminDistDir = __DIR__ . '/../../admin/vouchers-ui/frontend/dist';
    $distIndex = $adminDistDir . '/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '') {
        return null;
    }

    $cssPath = $adminDistDir . '/assets/' . $cssFile;
    $jsPath = $adminDistDir . '/assets/' . $jsFile;

    $employeeDir = employeeVouchersUiWebDir();               // e.g. /public_html/employee
    $rootDir = rtrim(dirname($employeeDir), '/');             // e.g. /public_html
    $assetBase = $rootDir . '/admin/vouchers-ui/frontend/dist/assets/';
    $apiUrl = $employeeDir . '/vouchers-ui/api';

    return [
        'assetBase' => $assetBase,
        'apiUrl' => $apiUrl,
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}
