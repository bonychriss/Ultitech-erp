<?php
/**
 * Admin-only delete for mistaken / test invoices (unpaid, no payment records).
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Only administrators can delete invoices.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
if (!($salesDb instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Sales database is not available.']);
    exit;
}

$rawIds = $_POST['ids'] ?? $_POST['id'] ?? [];
if (!is_array($rawIds)) {
    $rawIds = array_filter(array_map('trim', explode(',', (string) $rawIds)));
}
$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static function ($id) {
    return $id > 0;
})));

if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No invoice selected.']);
    exit;
}

$companyId = (int) (currentCompanyId() ?? 0);
$deleted = [];
$errors = [];

foreach ($ids as $invoiceId) {
    $result = sales_delete_invoice($salesDb, $invoiceId, $companyId);
    if (!empty($result['ok'])) {
        $deleted[] = [
            'id' => $invoiceId,
            'invoice_number' => $result['invoice_number'] ?? null,
        ];
    } else {
        $errors[] = [
            'id' => $invoiceId,
            'message' => $result['message'] ?? 'Delete failed.',
        ];
    }
}

$ok = !empty($deleted) && empty($errors);
$partial = !empty($deleted) && !empty($errors);

echo json_encode([
    'ok' => $ok || $partial,
    'partial' => $partial,
    'deleted' => $deleted,
    'errors' => $errors,
    'message' => $ok
        ? (count($deleted) === 1 ? 'Invoice deleted.' : count($deleted) . ' invoices deleted.')
        : ($partial
            ? count($deleted) . ' deleted, ' . count($errors) . ' could not be removed.'
            : ($errors[0]['message'] ?? 'Could not delete invoice(s).')),
]);
