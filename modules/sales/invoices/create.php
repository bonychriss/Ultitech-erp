<?php
// modules/sales/invoices/create.php
require_once dirname(__DIR__, 3) . '/includes/config.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 1) . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

$company_id = (int) (currentCompanyId() ?? 0);
$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
$error = null;

$doc = strtolower(trim((string) ($_GET['doc'] ?? 'invoice')));
if (in_array($doc, ['quote', 'quotation'], true)) {
    header('Location: ' . (function_exists('sales_module_url') ? sales_module_url('orders/create.php', ['mode' => 'new']) : '/modules/sales/orders/create.php?mode=new'));
    exit;
}

$order_id = (int) ($_GET['order_id'] ?? 0);

// === MODE 1: Convert Existing Order (must exit before direct-create UI) ===
if ($order_id > 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/includes/invoice-from-order.php';
    $module = isset($_GET['module']) ? trim((string) $_GET['module']) : 'sales';
    if ($module === '') {
        $module = 'sales';
    }

    try {
        $result = sales_convert_order_to_invoice($order_id, $module);
        sales_invoice_redirect($result['redirect']);
    } catch (Throwable $e) {
        $message = $e->getMessage() !== '' ? $e->getMessage() : 'Unexpected error while creating the invoice.';
        $isNotFound = function_exists('str_contains')
            ? str_contains(strtolower($message), 'not found')
            : (strpos(strtolower($message), 'not found') !== false);
        sales_invoice_fail($message, $isNotFound ? 404 : 500);
    }

    sales_invoice_fail('Invoice conversion did not complete. Please try again or contact support.');
}

// Resolve type early so React GET can exit before heavy product/customer queries.
$predefinedType = strtolower(trim((string) ($_GET['type'] ?? 'spare')));
if (!in_array($predefinedType, ['truck', 'spare'], true)) {
    $predefinedType = 'spare';
}
if (!salesSupportsTruckInvoices() && $predefinedType === 'truck') {
    $redirectParams = $_GET;
    unset($redirectParams['type']);
    $redirectQuery = http_build_query($redirectParams);
    header('Location: create.php' . ($redirectQuery !== '' ? '?' . $redirectQuery : ''));
    exit;
}

// === MODE 2a: React create shell (GET) — same desk UI as orders/create.php?mode=new ===
// Escape hatch: ?legacy=1 forces the PHP partial.
$forceLegacyCreate = isset($_GET['legacy']) && (string) $_GET['legacy'] === '1';
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && empty($order_id)
    && !$forceLegacyCreate
    && function_exists('salesInvoiceCreateUsesReactShell')
    && salesInvoiceCreateUsesReactShell($predefinedType)
) {
    require_once __DIR__ . '/includes/invoices-lib.php';
    $invoiceCreatePageTitle = salesInvoiceCreatePageTitle($predefinedType);
    try {
        if (!salesDocumentCreateRenderReactShell($invoiceCreatePageTitle)) {
            // Dist missing: fall through to PHP partial below.
        } else {
            // salesDocumentCreateRenderReactShell exits on success.
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Create Invoice</title></head><body style="font-family:sans-serif;padding:2rem;max-width:40rem;">';
        echo '<h1 style="color:#b91c1c;">Could not render invoice create page</h1>';
        echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p style="color:#64748b;font-size:0.875rem;">' . htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><a href="create.php?module=sales&amp;legacy=1">Open legacy create form</a></p>';
        echo '</body></html>';
        exit;
    }
}

require_once '../../../includes/revenue_ledger.php';

// === MODE 2: Direct Creation (Form Handling) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($order_id)) {
    require_once __DIR__ . '/includes/invoice-direct-create.php';
    $wantsJson = !empty($_POST['_api'])
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json'));
    try {
        $createResult = sales_process_direct_invoice_create($_POST);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'invoice_id' => $createResult['invoice_id'],
                'redirect' => $createResult['redirect'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        header('Location: ' . $createResult['redirect']);
        exit;
    } catch (Throwable $e) {
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $error = 'Error creating invoice: ' . $e->getMessage();
    }
}


// === MODE 3: Direct Creation Form (View) ===
// Fetch necessary data for form
$products = [];
try {
    $prodCols = [];
    try {
        $prodCols = $salesDb->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        $prodCols = [];
    }

    // Prefer main_image but fall back to image.
    $imgSelect = 'NULL AS main_image';
    if (in_array('main_image', $prodCols, true) && in_array('image', $prodCols, true)) {
        $imgSelect = 'COALESCE(p.main_image, p.image) AS main_image';
    } elseif (in_array('main_image', $prodCols, true)) {
        $imgSelect = 'p.main_image AS main_image';
    } elseif (in_array('image', $prodCols, true)) {
        $imgSelect = 'p.image AS main_image';
    }

    $itemTypeSelect = in_array('item_type', $prodCols, true) ? 'p.item_type' : "'' AS item_type";
    $hasProductCompanyId = in_array('company_id', $prodCols, true);
    $whereCompanySql = ($company_id > 0 && $hasProductCompanyId) ? (' WHERE p.company_id = ' . (int) $company_id . ' ') : ' ';

    // Keep product loading schema-safe: some installs miss company_id in one or more tables.
    $products = $salesDb->query("
        SELECT p.id, p.product_code, p.name, p.description, p.unit_price as selling_price, $itemTypeSelect, $imgSelect,
               (
                   COALESCE((SELECT SUM(quantity) FROM stock WHERE product_id = p.id), 0) -
                   COALESCE((
                       SELECT SUM(soi.quantity)
                       FROM sales_order_items soi
                       JOIN sales_orders so ON soi.order_id = so.id
                       WHERE soi.product_id = p.id
                       AND so.status IN ('confirmed', 'invoiced', 'paid')
                       AND so.status NOT IN ('shipped', 'delivered', 'cancelled')
                       AND (so.shipped_at IS NULL OR so.shipped_at = '0000-00-00 00:00:00')
                   ), 0)
               ) as stock_quantity
        FROM products p
        " . $whereCompanySql . "
        ORDER BY p.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: if company-scoped query returns nothing, retry without company filter.
    if ($products === [] && $whereCompanySql !== ' ') {
        $products = $salesDb->query("
            SELECT p.id, p.product_code, p.name, p.description, p.unit_price as selling_price, $itemTypeSelect, $imgSelect,
                   (
                       COALESCE((SELECT SUM(quantity) FROM stock WHERE product_id = p.id), 0) -
                       COALESCE((
                           SELECT SUM(soi.quantity)
                           FROM sales_order_items soi
                           JOIN sales_orders so ON soi.order_id = so.id
                           WHERE soi.product_id = p.id
                           AND so.status IN ('confirmed', 'invoiced', 'paid')
                           AND so.status NOT IN ('shipped', 'delivered', 'cancelled')
                           AND (so.shipped_at IS NULL OR so.shipped_at = '0000-00-00 00:00:00')
                       ), 0)
                   ) as stock_quantity
            FROM products p
            ORDER BY p.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $products = [];
}

$customers = [];
try {
    $customerCols = [];
    try {
        $customerCols = $salesDb->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        $customerCols = [];
    }

    $customerSql = "SELECT id, customer_code, company_name, contact_person, phone, email FROM customers WHERE status = 'active'";
    $customerParams = [];
    if ($company_id > 0 && in_array('company_id', $customerCols, true)) {
        $customerSql .= " AND company_id = ?";
        $customerParams[] = $company_id;
    }
    $customerSql .= " ORDER BY company_name";

    $stmtCustomers = $salesDb->prepare($customerSql);
    $stmtCustomers->execute($customerParams);
    $customers = $stmtCustomers->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($customers === []) {
        $stmtCustomers = $salesDb->prepare("SELECT id, customer_code, company_name, contact_person, phone, email FROM customers WHERE status = 'active' ORDER BY company_name");
        $stmtCustomers->execute();
        $customers = $stmtCustomers->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $customers = [];
}

$nextInvoiceNumber = '';
try {
    $nextInvoiceNumber = function_exists('sales_next_invoice_number')
        ? sales_next_invoice_number($salesDb, $company_id)
        : '';
} catch (Throwable $e) {
    $nextInvoiceNumber = '';
}
$catalogueUrl = sales_catalogue_url('invoice');
$customerCatalogueUrl = sales_customer_catalogue_url('invoice', sales_module_url('invoices/create.php'));
$invoiceCreatePageTitle = salesInvoiceCreatePageTitle($predefinedType);
$employeeHeaderTitle = $invoiceCreatePageTitle;
$page_title = $invoiceCreatePageTitle;
$companyTaxMode = trim((string) getCompanySetting('tax_calculation_mode', 'exclusive'));
if (!in_array($companyTaxMode, ['exclusive', 'inclusive'], true)) {
    $companyTaxMode = 'exclusive';
}

if (is_file(__DIR__ . '/../../../includes/bot_exchange_rates.php')) {
    require_once __DIR__ . '/../../../includes/bot_exchange_rates.php';
}

$defaultCurrency = 'TZS';
try {
    if ($company_id > 0) {
        $stCur = $salesDb->prepare('SELECT default_currency FROM sales_settings WHERE company_id = ? LIMIT 1');
        $stCur->execute([$company_id]);
        $rowCur = $stCur->fetch(PDO::FETCH_ASSOC);
        if (!empty($rowCur['default_currency'])) {
            $defaultCurrency = strtoupper(trim((string) $rowCur['default_currency']));
        }
    } else {
        $rowCur = $salesDb->query('SELECT default_currency FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!empty($rowCur['default_currency'])) {
            $defaultCurrency = strtoupper(trim((string) $rowCur['default_currency']));
        }
    }
} catch (Throwable $e) {
    $defaultCurrency = 'TZS';
}

$invoiceCurrencyOptions = sales_invoice_currency_options();
if (!isset($invoiceCurrencyOptions[$defaultCurrency])) {
    $defaultCurrency = 'TZS';
}

$initialExchangeRates = ['TZS' => '1.0000'];
$seedRateCurrencies = [strtoupper($defaultCurrency)];
if (isRoadmaster() && $predefinedType !== 'truck') {
    foreach (['TZS', 'USD'] as $seedCode) {
        if (!in_array($seedCode, $seedRateCurrencies, true)) {
            $seedRateCurrencies[] = $seedCode;
        }
    }
}
if (function_exists('sales_invoice_bot_exchange_rates')) {
    $initialExchangeRates = sales_invoice_bot_exchange_rates($seedRateCurrencies);
}

$initialExchangeRate = 1.0;
$initialExchangeRateMeta = null;
if ($defaultCurrency !== 'TZS' && !empty($initialExchangeRates[$defaultCurrency])) {
    $initialExchangeRate = (float) $initialExchangeRates[$defaultCurrency];
} elseif (function_exists('bot_get_exchange_rate')) {
    $botRateInfo = bot_get_exchange_rate($defaultCurrency);
    if (is_array($botRateInfo) && (float) ($botRateInfo['rate'] ?? 0) > 0) {
        $initialExchangeRate = (float) $botRateInfo['rate'];
        $initialExchangeRateMeta = $botRateInfo;
    }
}

$exchangeRateApiUrl = function_exists('sales_module_url')
    ? sales_module_url('payments/exchange_rate.php')
    : '../payments/exchange_rate.php';

// PHP partial path — bypass ERP system-font OB (same blank-page issue as React shell).
ob_start();
try {
    require __DIR__ . '/partials/create-invoice-view.php';
    $createViewHtml = (string) ob_get_clean();
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Create Invoice</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1 style="color:#b91c1c;">Could not render invoice create form</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($createViewHtml === '') {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Create Invoice</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Create form rendered empty</h1>';
    echo '<p>partials/create-invoice-view.php produced no HTML.</p>';
    echo '</body></html>';
    exit;
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
echo $createViewHtml;
exit;