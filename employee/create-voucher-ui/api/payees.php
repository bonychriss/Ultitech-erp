<?php
/**
 * Live payee list for Create/Edit Payment Voucher React desk.
 */
require_once __DIR__ . '/../../../includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}

try {
    $payees = function_exists('fetchActivePayees') ? fetchActivePayees() : [];
    $mapped = [];
    foreach ($payees as $p) {
        $mapped[] = [
            'id' => (int) ($p['id'] ?? 0),
            'name' => (string) ($p['name'] ?? ''),
            'type' => (string) ($p['type'] ?? 'Other'),
        ];
    }
    echo json_encode(['ok' => true, 'payees' => $mapped], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load payees.', 'payees' => []]);
}
