<?php
require_once '../../../includes/functions.php';
requireLogin();

require_once __DIR__ . '/../includes/balances_integration.php';
require_once __DIR__ . '/../includes/currency_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A valid expense ID is required.']);
    exit;
}

try {
    expenses_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT e.* FROM erp_expenses e WHERE e.id = ? AND ' . expenses_scope_sql('e') . ' LIMIT 1'
    );
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$expense) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Expense not found.']);
        exit;
    }

    $expense = expenses_enrich_list_row($pdo, $expense);
    $attachment = trim((string) ($expense['attachment'] ?? ''));
    $expense['attachment_name'] = $attachment !== ''
        ? basename(str_replace('\\', '/', $attachment))
        : '';
    $proxyUrl = function_exists('app_url') ? app_url('/proxy_pdf.php') : '/proxy_pdf.php';
    $expense['attachment_url'] = $attachment !== ''
        ? $proxyUrl . '?file=' . rawurlencode(ltrim(str_replace('\\', '/', $attachment), '/'))
        : '';
    unset($expense['attachment']);

    echo json_encode([
        'ok' => true,
        'data' => $expense,
        'csrf_token' => csrf_token(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('expense-detail: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load expense details.']);
}
