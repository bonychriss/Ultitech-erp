<?php
/**
 * Revenue entries list — React shell.
 */
require_once __DIR__ . '/modules/revenue/includes/revenue-lib.php';

revenueDeskRequireAccess();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'revenue';
}

if (isset($_GET['export']) && (string) $_GET['export'] === 'csv') {
    revenue_entries_export_csv(revenueDeskBootstrap(), $_GET);
    exit;
}

if (!empty($_GET['ren_probe'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $pdo = revenueDeskBootstrap();
    echo 'REVENUE_ENTRIES_REACT=1' . "\n";
    echo 'db=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
    echo 'rows=' . (int) $pdo->query('SELECT COUNT(*) FROM revenue_entries')->fetchColumn() . "\n";
    echo "OK\n";
    exit;
}

require __DIR__ . '/modules/revenue/desk.php';
