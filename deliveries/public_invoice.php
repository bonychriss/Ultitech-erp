<?php

declare(strict_types=1);

/**
 * Public invoice download/view for delivery verification.
 * Renders the same PDF layout as modules/sales/invoices/print.php.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/deliveries-ui/delivery-note-invoice.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$invoiceId = (int) ($_GET['id'] ?? 0);
$hash = trim((string) ($_GET['hash'] ?? ''));
$loggedIn = !empty($_SESSION['user_id']);

if ($invoiceId <= 0) {
    http_response_code(400);
    die('Invoice id is required.');
}

if ($hash !== '') {
    $order = getOrderByVerificationHash($hash);
    if (!$order) {
        http_response_code(403);
        die('Invalid or expired verification link.');
    }
    $linkedId = deliveries_resolve_sales_invoice_id($pdo, $order);
    if ($linkedId !== $invoiceId) {
        http_response_code(403);
        die('Invoice does not match this delivery.');
    }
    $companyId = deliveries_resolve_order_company_id($pdo, $order);
    if ($companyId > 0) {
        $_SESSION['company_id'] = $companyId;
    }
} elseif (!$loggedIn) {
    http_response_code(403);
    die('Access denied.');
}

$_GET['id'] = (string) $invoiceId;

$printFile = dirname(__DIR__) . '/modules/sales/invoices/print.php';
if (!is_file($printFile)) {
    http_response_code(503);
    die('Invoice print template is not available.');
}

// print.php uses paths relative to modules/sales/invoices/
$prevCwd = getcwd();
chdir(dirname($printFile));
try {
    require $printFile;
} finally {
    if ($prevCwd !== false) {
        chdir($prevCwd);
    }
}
