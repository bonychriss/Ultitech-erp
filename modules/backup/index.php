<?php
/**
 * Company Backup � React shell.
 * modules/backup/index.php
 */
require_once __DIR__ . '/includes/backup-lib.php';

backupDeskRequireAccess();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'backup';
}

$page_title = 'Backup';
$employeeHeaderTitle = 'Backup';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--backup-desk';
$bodyExtraClass = 'page-backup-desk';

$assets = backupDeskLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Backup</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Backup</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/backup/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$backupHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__BACKUP_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__BACKUP_BOOT__ = ' . json_encode(backupDeskFetchPayload(backupDeskBootstrap()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';

require __DIR__ . '/includes/backup-react-shell.php';
