<?php
declare(strict_types=1);

/**
 * ERP entry for Sales � full module via Laravel bridge + React desks.
 * URL: /{company}/sales[/{desk}] ? sales.php
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

$salesPublicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . (string) ($_SERVER['REQUEST_URI'] ?? '/sales.php');
$salesPublicUrl = strtok($salesPublicUrl, '?') ?: $salesPublicUrl;

$GLOBALS['ERP_SALES_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'sales_url' => $salesPublicUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_SALES_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = 'sales';

/** @var array<string,string> $salesDeskEntries */
$salesDeskEntries = [
    'invoices' => __DIR__ . '/modules/sales/invoices/index.php',
    'orders' => __DIR__ . '/modules/sales/orders/index.php',
    'customers' => __DIR__ . '/modules/sales/customers/index.php',
    'my-sales' => __DIR__ . '/modules/sales/my-sales/index.php',
    'catalogue' => __DIR__ . '/modules/sales/catalogue.php',
    'customer-catalogue' => __DIR__ . '/modules/sales/customers/catalogue.php',
    'pricelist' => __DIR__ . '/modules/sales/pricelist.php',
    'settings' => __DIR__ . '/modules/sales/settings/index.php',
    'quotations' => __DIR__ . '/modules/sales/orders/create.php',
    'quote-create' => __DIR__ . '/modules/sales/orders/create.php',
    'invoice-create' => __DIR__ . '/modules/sales/invoices/create.php',
    'order-view' => __DIR__ . '/modules/sales/orders/view.php',
    'invoice-view' => __DIR__ . '/modules/sales/invoices/view.php',
    'quote-edit' => __DIR__ . '/modules/sales/orders/edit.php',
    'payment-create' => __DIR__ . '/modules/sales/payments/create.php',
    'print-order' => __DIR__ . '/modules/sales/orders/print.php',
    'print-invoice' => __DIR__ . '/modules/sales/invoices/print.php',
    'send-doc' => __DIR__ . '/modules/sales/send_doc.php',
    'products-view' => __DIR__ . '/modules/sales/products_view.php',
    'admin-targets' => __DIR__ . '/modules/sales/admin/targets.php',
    'admin-reassign' => __DIR__ . '/modules/sales/admin/reassign-sales.php',
    'delivery-note' => __DIR__ . '/modules/sales/orders/delivery_note.php',
];

/** @var array<string,string> $salesDeskApiFiles */
$salesDeskApiFiles = [
    'dashboard' => __DIR__ . '/modules/sales/dashboard/api/init.php',
    'init' => __DIR__ . '/modules/sales/dashboard/api/init.php',
    'my-sales' => __DIR__ . '/modules/sales/my-sales/api/init.php',
    'invoices' => __DIR__ . '/modules/sales/invoices/api/list-init.php',
    'orders' => __DIR__ . '/modules/sales/orders/api/sales-orders-init.php',
    'customers' => __DIR__ . '/modules/sales/customers/api/index-init.php',
    'catalogue' => __DIR__ . '/modules/sales/catalogue-ui/api/init.php',
    'pricelist' => __DIR__ . '/modules/sales/pricelist/api/init.php',
    'settings' => __DIR__ . '/modules/sales/settings/api/init.php',
    'quotations' => __DIR__ . '/modules/sales/orders/api/init.php',
    'create-init' => __DIR__ . '/modules/sales/invoices/api/create-init.php',
    'create-quote' => __DIR__ . '/modules/sales/invoices/api/create-quote.php',
    'create-invoice' => __DIR__ . '/modules/sales/invoices/api/create-invoice.php',
    'order-view-init' => __DIR__ . '/modules/sales/orders/api/view-init.php',
    'order-view-status' => __DIR__ . '/modules/sales/orders/api/view-status.php',
    'invoice-view-init' => __DIR__ . '/modules/sales/invoices/api/view-init.php',
    'quote-edit-init' => __DIR__ . '/modules/sales/invoices/api/quote-edit-init.php',
    'quote-edit-save' => __DIR__ . '/modules/sales/invoices/api/quote-edit-save.php',
    'convert-invoice' => __DIR__ . '/modules/sales/orders/api/convert-invoice.php',
    'exchange-rate' => __DIR__ . '/modules/sales/payments/exchange_rate.php',
];

$api = strtolower(trim((string) ($_GET['api'] ?? '')));
$desk = strtolower(trim((string) ($_GET['desk'] ?? '')));

if ($api !== '') {
    $laravelRoot = __DIR__ . '/erp-laravel';
    $laravelAutoload = $laravelRoot . '/vendor/autoload.php';
    if (!is_file($laravelAutoload)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'error' => 'erp-laravel vendor is missing. Run composer install in erp-laravel/.',
        ]);
        exit;
    }

    $laravelRouteMap = [
        'dashboard' => '/api/dashboard',
        'init' => '/api/dashboard',
        '1' => '/api/dashboard',
        'my-sales' => '/api/desk/my-sales',
        'invoices' => '/api/desk/invoices',
        'orders' => '/api/desk/orders',
        'customers' => '/api/desk/customers',
        'catalogue' => '/api/desk/catalogue',
        'pricelist' => '/api/desk/pricelist',
        'settings' => '/api/desk/settings',
        'quotations' => '/api/desk/quotations',
        'create-init' => '/api/desk/create-init',
        'create-quote' => '/api/desk/create-quote',
        'create-invoice' => '/api/desk/create-invoice',
        'order-view-init' => '/api/desk/order-view-init',
        'order-view-status' => '/api/desk/order-view-status',
        'invoice-view-init' => '/api/desk/invoice-view-init',
        'quote-edit-init' => '/api/desk/quote-edit-init',
        'quote-edit-save' => '/api/desk/quote-edit-save',
        'convert-invoice' => '/api/desk/convert-invoice',
        'exchange-rate' => '/api/desk/exchange-rate',
    ];

    if (isset($laravelRouteMap[$api])) {
        $GLOBALS['ERP_SALES_ROUTE'] = $laravelRouteMap[$api];
        require $laravelRoot . '/bootstrap/erp-bridge.php';
        exit;
    }

    $apiKey = $api === '1' ? 'dashboard' : $api;
    if (isset($salesDeskApiFiles[$apiKey]) && is_file($salesDeskApiFiles[$apiKey])) {
        require $salesDeskApiFiles[$apiKey];
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Unknown sales API: ' . $api]);
    exit;
}

$_SESSION['active_module'] = 'sales';
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'sales';
}

$laravelRoot = __DIR__ . '/erp-laravel';
$laravelEnv = $laravelRoot . '/.env';
$laravelEnvExample = $laravelRoot . '/.env.example';
if (!is_file($laravelEnv) && is_file($laravelEnvExample)) {
    @copy($laravelEnvExample, $laravelEnv);
}

// List + create/view/edit desks: Blade via erp-laravel (print/payment/admin stay legacy).
$laravelListDesks = [
    'invoices',
    'orders',
    'quotations',
    'customers',
    'my-sales',
    'pricelist',
    'settings',
    'catalogue',
    'customer-catalogue',
    'quote-create',
    'invoice-create',
    'order-view',
    'invoice-view',
    'quote-edit',
];
if ($desk !== '' && in_array($desk, $laravelListDesks, true)) {
    // Order→invoice conversion still needs legacy create.php (GET + order_id).
    if ($desk === 'invoice-create' && (int) ($_GET['order_id'] ?? 0) > 0) {
        // fall through to legacy entry
    } else {
        if ($desk === 'quote-create') {
            $_GET['mode'] = 'new';
            $_GET['document'] = 'quote';
        }
        if (!is_file($laravelRoot . '/vendor/autoload.php')) {
            http_response_code(503);
            echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
            echo '<h1>erp-laravel required</h1>';
            echo '<p>Run <code>composer install</code> in <code>erp-laravel/</code>.</p>';
            echo '</body></html>';
            exit;
        }
        $GLOBALS['ERP_SALES_ROUTE'] = '/sales/desk/' . $desk;
        require $laravelRoot . '/bootstrap/erp-bridge.php';
        exit;
    }
}

if ($desk !== '' && isset($salesDeskEntries[$desk])) {
    if ($desk === 'quote-create') {
        $_GET['mode'] = 'new';
        $_GET['document'] = 'quote';
    }

    $entry = $salesDeskEntries[$desk];
    if (!is_file($entry)) {
        http_response_code(404);
        echo 'Sales desk not found.';
        exit;
    }
    require $entry;
    exit;
}

// Default Sales dashboard: Blade shell via erp-laravel Domains/Sales
if (!is_file($laravelRoot . '/vendor/autoload.php')) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>erp-laravel required</h1>';
    echo '<p>Run <code>composer install</code> in <code>erp-laravel/</code>.</p>';
    echo '</body></html>';
    exit;
}

$GLOBALS['ERP_SALES_ROUTE'] = '/sales/dashboard';
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;