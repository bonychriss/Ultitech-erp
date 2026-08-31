<?php
require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';
require_once '../functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

$returnUrl = trim((string) ($_GET['return'] ?? ''));
$docType = strtolower(trim((string) ($_GET['doc'] ?? 'quote')));
$multiSelect = ($docType === 'statement');

$docLabel = match ($docType) {
    'invoice' => 'Invoice',
    'statement' => 'Statement',
    'purchase' => 'Purchase Order',
    default => 'Quotation',
};

if ($returnUrl === '') {
    $returnUrl = $docType === 'invoice'
        ? sales_module_url('invoices/create.php')
        : sales_module_url('orders/create.php', ['mode' => 'new']);
}

if (strpos($returnUrl, '://') === false) {
    if ($returnUrl !== '' && $returnUrl[0] !== '/') {
        $returnUrl = '/' . $returnUrl;
    }
    $returnUrl = str_replace('/staff/', '/', $returnUrl);
    $base = defined('APP_BASE_PATH') ? rtrim((string) APP_BASE_PATH, '/') : '';
    if ($base !== '' && $returnUrl !== '' && strpos($returnUrl, $base . '/') !== 0 && $returnUrl !== $base) {
        $returnUrl = $base . $returnUrl;
    }
}

$customers = [];
$popularity = [];
try {
    $customers = $pdo->query("
        SELECT id, customer_code, company_name, contact_person, email, phone, address, status
        FROM customers
        WHERE LOWER(TRIM(COALESCE(status, 'active'))) = 'active'
        ORDER BY company_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $popStmt = $pdo->query("
        SELECT customer_id, COUNT(id) AS invoice_count
        FROM invoices
        WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY customer_id
    ");
    foreach ($popStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $popularity[(int) $row['customer_id']] = (int) $row['invoice_count'];
    }
} catch (Throwable $e) {
    $customers = [];
    $popularity = [];
}

foreach ($customers as &$custRow) {
    $cid = (int) ($custRow['id'] ?? 0);
    $custRow['invoice_count'] = (int) ($popularity[$cid] ?? 0);
}
unset($custRow);

$addSelectedLabel = match ($docType) {
    'invoice' => 'invoice',
    'statement' => 'statement',
    'purchase' => 'purchase order',
    default => 'quotation',
};

require __DIR__ . '/partials/customer-catalogue-ui.php';
