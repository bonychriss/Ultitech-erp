<?php
declare(strict_types=1);

/**
 * ERP entry for Stock — full module via erp-laravel Domains/Stock.
 * URL: /{company}/stock[/{desk}] ? stock.php
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
if ($slug === '' && function_exists('getRequestedCompanySlug')) {
    $slug = trim((string) getRequestedCompanySlug());
}
$backUrl = $slug !== ''
    ? company_url('select-module', $slug)
    : app_url('/select-module.php');

$dbName = '';
try {
    global $pdo;
    if ($pdo instanceof PDO) {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
} catch (Throwable $e) {
    $dbName = '';
}

$stockPublicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . (string) ($_SERVER['REQUEST_URI'] ?? '/stock.php');
$stockPublicUrl = strtok($stockPublicUrl, '?') ?: $stockPublicUrl;

$GLOBALS['ERP_STOCK_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'stock_url' => $stockPublicUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_STOCK_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = 'stocks';

$_SESSION['active_module'] = 'stocks';
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}

$desk = strtolower(trim((string) ($_GET['desk'] ?? '')));

$laravelRoot = __DIR__ . '/erp-laravel';
$laravelAutoload = $laravelRoot . '/vendor/autoload.php';
$laravelEnv = $laravelRoot . '/.env';
$laravelEnvExample = $laravelRoot . '/.env.example';
if (!is_file($laravelEnv) && is_file($laravelEnvExample)) {
    @copy($laravelEnvExample, $laravelEnv);
}

/** @var array<string,string> $stockDeskEntries */
$stockDeskEntries = [
    'dashboard' => __DIR__ . '/stock/dashboard.php',
    'catalogue' => __DIR__ . '/stock/catalogue.php',
    'settings' => __DIR__ . '/stock/settings.php',
    'product-detail' => __DIR__ . '/stock/product-detail.php',
    'products' => __DIR__ . '/stock/modules/products/index.php',
    'product-view' => __DIR__ . '/stock/modules/products/view.php',
    'product-create' => __DIR__ . '/stock/modules/products/add.php',
    'product-edit' => __DIR__ . '/stock/modules/products/edit.php',
    'products-import' => __DIR__ . '/stock/modules/products/bulk_import.php',
    'categories' => __DIR__ . '/stock/modules/products/categories.php',
    'category-add' => __DIR__ . '/stock/modules/products/add_category.php',
    'category-edit' => __DIR__ . '/stock/modules/products/edit_category.php',
    'brands' => __DIR__ . '/stock/modules/brands/index.php',
    'brand-edit' => __DIR__ . '/stock/modules/brands/edit.php',
    'uploads' => __DIR__ . '/stock/modules/uploads/index.php',
    'uploads-upload' => __DIR__ . '/stock/modules/uploads/upload.php',
    'uploads-recycle' => __DIR__ . '/stock/modules/uploads/recycle_bin.php',
    'suppliers' => __DIR__ . '/stock/modules/suppliers/index.php',
    'supplier-add' => __DIR__ . '/stock/modules/suppliers/add.php',
    'supplier-edit' => __DIR__ . '/stock/modules/suppliers/edit.php',
    'supplier-view' => __DIR__ . '/stock/modules/suppliers/view.php',
    'statements' => __DIR__ . '/stock/modules/statements/supplier.php',
    'shipments' => __DIR__ . '/stock/modules/shipments/index.php',
    'shipment-create' => __DIR__ . '/stock/modules/shipments/create.php',
    'shipment-edit' => __DIR__ . '/stock/modules/shipments/edit.php',
    'shipment-view' => __DIR__ . '/stock/modules/shipments/view.php',
    'shipment-receive' => __DIR__ . '/stock/modules/shipments/receive.php',
    'shipment-import' => __DIR__ . '/stock/modules/shipments/import.php',
    'shippers' => __DIR__ . '/stock/modules/shippers/index.php',
    'shipper-add' => __DIR__ . '/stock/modules/shippers/add.php',
    'shipper-edit' => __DIR__ . '/stock/modules/shippers/edit.php',
    'purchases' => __DIR__ . '/stock/modules/purchases/index.php',
    'purchase-create' => __DIR__ . '/stock/modules/purchases/domestic_create.php',
    'purchase-edit' => __DIR__ . '/stock/modules/purchases/edit.php',
    'purchase-view' => __DIR__ . '/stock/modules/purchases/view_po.php',
    'purchase-receive' => __DIR__ . '/stock/modules/purchases/domestic_receive.php',
    'purchase-classification' => __DIR__ . '/stock/modules/purchases/edit_classification.php',
    'purchase-payments' => __DIR__ . '/stock/modules/purchases/supplier-payments.php',
    'purchase-receipt-audit' => __DIR__ . '/stock/modules/purchases/receipt_audit.php',
    'replenishment' => __DIR__ . '/stock/modules/reports/replenishment.php',
    'reports' => __DIR__ . '/stock/modules/reports/stock.php',
    'reports-purchases' => __DIR__ . '/stock/modules/reports/purchases.php',
    'movements' => __DIR__ . '/stock/modules/stock/movements.php',
    'stock-adjust' => __DIR__ . '/stock/modules/stock/adjust.php',
    'stock-batches' => __DIR__ . '/stock/modules/stock/batches.php',
    'transfers' => __DIR__ . '/stock/modules/transfers/index.php',
    'fleet-parts' => __DIR__ . '/stock/modules/fleet_parts/index.php',
    'analytics' => __DIR__ . '/stock/modules/analytics/index.php',
];

if (!is_file($laravelAutoload)) {
    if ($desk !== '' && isset($stockDeskEntries[$desk]) && is_file($stockDeskEntries[$desk])) {
        require $stockDeskEntries[$desk];
        exit;
    }
    require __DIR__ . '/stock/dashboard.php';
    exit;
}

if ($desk !== '' && isset($stockDeskEntries[$desk])) {
    $GLOBALS['ERP_STOCK_ROUTE'] = '/stock/desk/' . $desk;
    $GLOBALS['ERP_ROUTE'] = $GLOBALS['ERP_STOCK_ROUTE'];
    require $laravelRoot . '/bootstrap/erp-bridge.php';
    exit;
}

if ($desk !== '') {
    http_response_code(404);
    echo 'Stock desk not found.';
    exit;
}

$GLOBALS['ERP_STOCK_ROUTE'] = '/stock';
$GLOBALS['ERP_ROUTE'] = '/stock';
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
