<?php
require_once '../../../includes/functions.php';
require_once __DIR__ . '/../includes/balances_integration.php';
requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$month = trim((string) ($_GET['month'] ?? ''));

try {
    expenses_backfill_pending_records($pdo);
    echo json_encode(expenses_fetch_insights_stats($pdo, $month));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
