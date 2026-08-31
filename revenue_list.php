<?php
declare(strict_types=1);

/**
 * Legacy URL: forwards to revenue_entries.php (canonical list).
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}
$q = $_SERVER['QUERY_STRING'] ?? '';
header('Location: revenue_entries.php' . ($q !== '' ? '?' . $q : ''), true, 302);
exit;
