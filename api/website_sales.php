<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}

$canWebsite = (function_exists('isUltimate') && isUltimate())
    || (function_exists('isRoadmaster') && isRoadmaster());
if (!$canWebsite) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to web sales.']);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

if (!function_exists('verify_csrf') || !verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Refresh the page and try again.']);
    exit;
}

$stockFn = __DIR__ . '/../stock/config/functions.php';
if (is_file($stockFn)) {
    require_once $stockFn;
}
require_once __DIR__ . '/../stock/includes/web-sales-lib.php';

$pdo = webSalesPdo();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The customer database is not available.']);
    exit;
}

try {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $raw = $_POST['customer_ids'] ?? [];
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw]), static fn ($id) => $id > 0)));
        if ($ids === []) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Select at least one customer.']);
            exit;
        }
        $added = webSalesAddCustomers($pdo, array_slice($ids, 0, 500), (int) ($_SESSION['user_id'] ?? 0));
        echo json_encode(['success' => true, 'added' => $added]);
        exit;
    }
    if ($action === 'remove') {
        $id = (int) ($_POST['customer_id'] ?? 0);
        if ($id <= 0 || !webSalesRemoveCustomer($pdo, $id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'This customer is not in web sales.']);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('website_sales api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The change could not be saved.']);
}
