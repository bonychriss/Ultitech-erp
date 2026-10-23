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
    global $pdo;
    $payees = function_exists('fetchActivePayees') ? fetchActivePayees($pdo instanceof PDO ? $pdo : null) : [];
    if ($payees === [] && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->query(
                "SELECT id, name, type
                 FROM payees
                 WHERE COALESCE(is_active, 1) = 1
                   AND name IS NOT NULL AND TRIM(name) <> ''
                 ORDER BY name ASC"
            );
            $payees = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e2) {
            $payees = [];
        }
    }
    $mapped = [];
    foreach ($payees as $p) {
        $name = trim((string) ($p['name'] ?? ''));
        $id = (int) ($p['id'] ?? 0);
        if ($name === '' || $id <= 0) {
            continue;
        }
        $mapped[] = [
            'id' => $id,
            'name' => $name,
            'type' => (string) ($p['type'] ?? 'Other'),
        ];
    }
    echo json_encode([
        'ok' => true,
        'payees' => $mapped,
        'count' => count($mapped),
        'db' => isset($GLOBALS['payees_operational_database'])
            ? (string) $GLOBALS['payees_operational_database']
            : '',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load payees.', 'payees' => []]);
}
