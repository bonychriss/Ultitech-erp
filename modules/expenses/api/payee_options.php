<?php
require_once '../../../includes/functions.php';
require_once '../includes/payee_options.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$q = trim((string) ($_GET['q'] ?? ''));
$limit = min(500, max(10, (int) ($_GET['limit'] ?? 300)));

try {
    global $pdo;
    $options = expenses_collect_payee_options($pdo, $q, $limit);
    echo json_encode(['options' => $options]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'options' => []]);
}
