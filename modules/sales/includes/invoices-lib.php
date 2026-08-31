<?php

declare(strict_types=1);

function invoicesDeskBootstrap(): void
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 4) . '/includes/config.php';
        require_once dirname(__DIR__, 4) . '/includes/functions.php';
        require_once dirname(__DIR__, 2) . '/functions.php';
        $booted = true;
    }
}

function invoicesDeskRequireAccess(): void
{
    invoicesDeskBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
}

function invoicesDeskWebBasePath(): string
{
    if (function_exists('app_url')) {
        return app_url('/modules/sales/invoices');
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }

    return rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function invoicesDeskLoadReactAssets(): ?array
{
    $uiDir = dirname(__DIR__) . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '' || $cssFile === '') {
        return null;
    }

    $assetBase = invoicesDeskPublicUrl('frontend/dist/assets/');
    $apiUrl = invoicesDeskPublicUrl('api');
    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'distHtml' => $distHtml,
        'assetBase' => $assetBase,
        'apiUrl' => $apiUrl,
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

/**
 * Asset + API URLs anchored to the invoices module (safe when shell is included from orders/).
 *
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function invoicesDeskModuleAssetUrls(): ?array
{
    $assets = invoicesDeskLoadReactAssets();
    if ($assets === null) {
        return null;
    }

    // Static dist is served from document root (tenant-prefixed /roadmaster/.../dist 404s).
    // PHP API must keep the company slug so session cookies resolve under /{slug}/...
    if (function_exists('sales_app_url')) {
        $assets['assetBase'] = sales_app_url('modules/sales/invoices/frontend/dist/assets/');
    }
    if (function_exists('sales_module_url')) {
        $assets['apiUrl'] = rtrim(sales_module_url('invoices/api'), '/');
    } elseif (function_exists('sales_app_url')) {
        $assets['apiUrl'] = sales_app_url('modules/sales/invoices/api');
    }

    return $assets;
}

/**
 * Send HTML after discarding outer ERP output buffers (system-font injector can blank pages).
 */
function invoicesDeskEmitHtmlAndExit(string $html): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($html === '') {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Blank shell</title></head>';
        echo '<body style="font-family:sans-serif;padding:2rem;"><h1>Invoice UI shell rendered empty</h1>';
        echo '<p>The React shell template produced no HTML. Check invoices-react-shell.php and header_employee.php.</p>';
        echo '</body></html>';
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo $html;
    exit;
}

/**
 * Render the shared React document-create shell, or return false when dist is missing.
 * require() stays in this function so $assets / $invoicesHeadMarkup remain in scope.
 */
function salesDocumentCreateRenderReactShell(string $pageTitle, string $page = 'create'): bool
{
    $assets = invoicesDeskModuleAssetUrls();
    if ($assets === null) {
        return false;
    }

    $page_title = $pageTitle;
    $employeeHeaderTitle = $pageTitle;
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--inv-desk';
    $bodyExtraClass = 'page-inv-desk';
    $invoicesPage = $page;
    $invoicesHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__INVOICES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__INVOICES_PAGE__ = ' . json_encode($invoicesPage, JSON_UNESCAPED_SLASHES) . ';</script>';

    ob_start();
    try {
        require dirname(__FILE__) . '/invoices-react-shell.php';
        $html = (string) ob_get_clean();
    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        throw $e;
    }

    invoicesDeskEmitHtmlAndExit($html);
}

/**
 * Render the invoices list React shell.
 */
function salesInvoicesListRenderReactShell(): void
{
    $assets = invoicesDeskModuleAssetUrls();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Invoices</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Invoices</h1>';
        echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/sales/invoices/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'Invoices';
    $employeeHeaderTitle = 'Invoices';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';
    $bodyExtraClass = 'page-exp-desk exp-dashboard-page page-invoices-desk invoices-dashboard-page';
    $invoicesPage = 'list';
    $invoicesHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__INVOICES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__INVOICES_PAGE__ = ' . json_encode('list', JSON_UNESCAPED_SLASHES) . ';</script>';

    ob_start();
    try {
        require dirname(__FILE__) . '/invoices-react-shell.php';
        $html = (string) ob_get_clean();
    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        throw $e;
    }

    invoicesDeskEmitHtmlAndExit($html);
}

function invoicesDeskPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $base = invoicesDeskWebBasePath();

    return $base . '/' . $relativePath;
}

function invoicesDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
        '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 4) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    return implode("\n    ", $parts);
}

/**
 * @return array<string, mixed>
 */
function sales_invoice_create_init_data(): array
{
    invoicesDeskBootstrap();

    global $pdo;
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    $company_id = (int) (currentCompanyId() ?? 0);

    $products = [];
    try {
        $prodCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
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
        $stockUploadsBase = function_exists('app_url') ? app_url('/stock/uploads/products') : '/stock/uploads/products';

        $sql = "
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
            $whereCompanySql
            ORDER BY p.name
        ";
        $products = $salesDb->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($products === [] && $whereCompanySql !== ' ') {
            $products = $salesDb->query(str_replace($whereCompanySql, ' ', $sql))->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        foreach ($products as $pIdx => $productRow) {
            $pId = (int) ($productRow['id'] ?? 0);
            if ($pId < 1) {
                $products[$pIdx]['image_url'] = '';
                continue;
            }
            if (function_exists('sales_product_image_url')) {
                $line = [
                    'product_id' => $pId,
                    'main_image' => (string) ($productRow['main_image'] ?? ''),
                ];
                if (function_exists('sales_order_item_image_name')) {
                    $line['main_image'] = sales_order_item_image_name($line, $salesDb);
                }
                $products[$pIdx]['image_url'] = sales_product_image_url($pId, (string) ($line['main_image'] ?? ''), 'thumbnail');
            } else {
                $mainImage = (string) ($productRow['main_image'] ?? '');
                $products[$pIdx]['image_url'] = $mainImage !== ''
                    ? $stockUploadsBase . '/' . $pId . '/thumbnail/' . $mainImage
                    : '';
            }
        }
        unset($pIdx, $productRow);
    } catch (Throwable $e) {
        $products = [];
    }

    $customers = [];
    try {
        $customerCols = $salesDb->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $hasCustomerNotes = in_array('notes', $customerCols, true);
        $customerSelect = $hasCustomerNotes
            ? 'id, customer_code, company_name, contact_person, phone, email, notes'
            : 'id, customer_code, company_name, contact_person, phone, email';
        $customerSql = "SELECT {$customerSelect} FROM customers WHERE status = 'active'";
        $customerParams = [];
        if ($company_id > 0 && in_array('company_id', $customerCols, true)) {
            $customerSql .= ' AND company_id = ?';
            $customerParams[] = $company_id;
        }
        $customerSql .= ' ORDER BY company_name';
        $stmtCustomers = $salesDb->prepare($customerSql);
        $stmtCustomers->execute($customerParams);
        $customers = $stmtCustomers->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($customers === []) {
            $stmtCustomers = $salesDb->prepare("SELECT {$customerSelect} FROM customers WHERE status = 'active' ORDER BY company_name");
            $stmtCustomers->execute();
            $customers = $stmtCustomers->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $customers = [];
    }

    $marketCustomerIds = [];
    try {
        $crmDb = $pdo instanceof PDO ? $pdo : $salesDb;
        if ($company_id > 0 && $crmDb instanceof PDO) {
            $stmtMarket = $crmDb->prepare("
                SELECT DISTINCT customer_id
                FROM crm_contacts
                WHERE company_id = ?
                  AND notes LIKE '%market_id:%'
                  AND COALESCE(customer_id, 0) > 0
            ");
            $stmtMarket->execute([$company_id]);
            foreach ($stmtMarket->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $marketCustomerId) {
                $id = (int) $marketCustomerId;
                if ($id > 0) {
                    $marketCustomerIds[$id] = true;
                }
            }
        }
    } catch (Throwable $e) {
        $marketCustomerIds = [];
    }

    foreach ($customers as $cIdx => $customerRow) {
        $cid = (int) ($customerRow['id'] ?? 0);
        $notes = (string) ($customerRow['notes'] ?? '');
        $fromMarket = isset($marketCustomerIds[$cid])
            || stripos($notes, 'market_id:') !== false
            || stripos($notes, 'CRM Market') !== false
            || stripos($notes, 'Client Market') !== false
            || stripos($notes, 'Imported from CRM Market') !== false;
        $customers[$cIdx]['from_market'] = $fromMarket;
        unset($customers[$cIdx]['notes']);
    }
    unset($cIdx, $customerRow);

    $predefinedType = strtolower(trim((string) ($_GET['type'] ?? 'spare')));
    if (!in_array($predefinedType, ['truck', 'spare'], true)) {
        $predefinedType = 'spare';
    }
    if (function_exists('salesSupportsTruckInvoices') && !salesSupportsTruckInvoices() && $predefinedType === 'truck') {
        $predefinedType = 'spare';
    }

    $documentType = strtolower(trim((string) ($_GET['document'] ?? '')));
    if ($documentType !== 'quote' && isset($_GET['mode']) && strtolower(trim((string) $_GET['mode'])) === 'new') {
        $documentType = 'quote';
    }
    if (!in_array($documentType, ['invoice', 'quote'], true)) {
        $documentType = 'invoice';
    }
    $isQuote = $documentType === 'quote';
    $catalogueKind = $isQuote ? 'quote' : 'invoice';
    // Explicit return path — never use REQUEST_URI here (create-init is an API endpoint).
    $createReturnParams = ['module' => 'sales'];
    if ($predefinedType === 'truck') {
        $createReturnParams['type'] = 'truck';
    }
    if ($isQuote) {
        $createReturnParams['mode'] = 'new';
        $createReturnPath = sales_module_url('orders/create.php', $createReturnParams);
    } else {
        $createReturnPath = sales_module_url('invoices/create.php', $createReturnParams);
    }

    $users = [];
    if ($isQuote) {
        try {
            $userCols = $salesDb->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            $hasNameCol = in_array('name', $userCols, true);
            $hasActiveCol = in_array('is_active', $userCols, true);
            $displayExpr = $hasNameCol
                ? "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), NULLIF(TRIM(name), ''), username, ''))"
                : "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), username, ''))";
            $usersSql = 'SELECT id, username, ' . $displayExpr . ' AS full_name FROM users';
            if ($hasActiveCol) {
                $usersSql .= ' WHERE is_active = 1';
            }
            $usersSql .= " HAVING full_name <> '' ORDER BY full_name ASC";
            $users = $salesDb->query($usersSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $users = [];
        }
        $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
        $currentUserName = trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
        if ($currentUserId > 0 && $currentUserName !== '') {
            $hasCurrentUser = false;
            foreach ($users as $existingUser) {
                if ((int) ($existingUser['id'] ?? 0) === $currentUserId) {
                    $hasCurrentUser = true;
                    break;
                }
            }
            if (!$hasCurrentUser) {
                array_unshift($users, [
                    'id' => $currentUserId,
                    'username' => (string) ($_SESSION['username'] ?? ''),
                    'full_name' => $currentUserName,
                ]);
            }
        }
    }

    $companyTaxMode = trim((string) getCompanySetting('tax_calculation_mode', 'exclusive'));
    if (!in_array($companyTaxMode, ['exclusive', 'inclusive'], true)) {
        $companyTaxMode = 'exclusive';
    }

    if (is_file(dirname(__DIR__, 4) . '/includes/bot_exchange_rates.php')) {
        require_once dirname(__DIR__, 4) . '/includes/bot_exchange_rates.php';
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
    if (function_exists('isRoadmaster') && isRoadmaster() && $predefinedType !== 'truck') {
        foreach (['TZS', 'USD'] as $seedCode) {
            if (!in_array($seedCode, $seedRateCurrencies, true)) {
                $seedRateCurrencies[] = $seedCode;
            }
        }
    }
    if (function_exists('sales_invoice_bot_exchange_rates')) {
        $initialExchangeRates = sales_invoice_bot_exchange_rates($seedRateCurrencies);
    }

    $initialExchangeRate = '1.0000';
    if ($defaultCurrency !== 'TZS' && !empty($initialExchangeRates[$defaultCurrency])) {
        $initialExchangeRate = number_format((float) $initialExchangeRates[$defaultCurrency], 4, '.', '');
    }

    $nextInvoiceNumber = '';
    try {
        $nextInvoiceNumber = function_exists('sales_next_invoice_number')
            ? sales_next_invoice_number($salesDb, $company_id)
            : '';
    } catch (Throwable $e) {
        $nextInvoiceNumber = '';
    }

    $exchangeRateApiUrl = function_exists('sales_module_url')
        ? sales_module_url('payments/exchange_rate.php')
        : '../payments/exchange_rate.php';

    $pageTitle = $isQuote
        ? (function_exists('salesQuoteCreatePageTitle') ? salesQuoteCreatePageTitle($predefinedType) : 'Create Quotation')
        : (function_exists('salesInvoiceCreatePageTitle') ? salesInvoiceCreatePageTitle($predefinedType) : 'Create Invoice');

    return [
        'document_type' => $documentType,
        'products' => $products,
        'customers' => $customers,
        'users' => $users,
        'current_user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'current_user_name' => (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System Admin'),
        'catalogue_url' => sales_catalogue_url($catalogueKind, $createReturnPath),
        'customer_catalogue_url' => sales_customer_catalogue_url($catalogueKind, $createReturnPath),
        'stock_uploads_base' => function_exists('app_url') ? app_url('/stock/uploads/products') : '/stock/uploads/products',
        'is_roadmaster' => function_exists('isRoadmaster') ? isRoadmaster() : false,
        'is_ultimate' => function_exists('isUltimate') ? isUltimate() : false,
        'supports_truck_invoices' => function_exists('salesSupportsTruckInvoices') ? salesSupportsTruckInvoices() : false,
        'predefined_type' => $predefinedType,
        'next_invoice_number' => $nextInvoiceNumber,
        'tax_mode' => $companyTaxMode,
        'default_currency' => $defaultCurrency,
        'currency_options' => $invoiceCurrencyOptions,
        'initial_exchange_rate' => $initialExchangeRate,
        'initial_exchange_rates' => $initialExchangeRates,
        'exchange_rate_api_url' => $exchangeRateApiUrl,
        'invoices_index_url' => sales_module_url('invoices/index.php'),
        'quotations_index_url' => sales_module_url('orders/create.php', ['module' => 'sales']),
        'index_url' => $isQuote ? sales_module_url('orders/create.php', ['module' => 'sales']) : sales_module_url('invoices/index.php'),
        'customers_index_url' => sales_module_url('customers/index.php'),
        'page_title' => $pageTitle,
        'submit_label' => $isQuote ? 'Create Quotation' : 'Create Invoice',
        'money_animation_url' => function_exists('app_url')
            ? app_url('/assets/animations/Money.lottie')
            : '/assets/animations/Money.lottie',
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function invoices_desk_invoices_for_api(array $rows): array
{
    $includeOrderType = function_exists('salesSupportsTruckInvoices') && salesSupportsTruckInvoices();
    $out = [];
    foreach ($rows as $row) {
        $entry = [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'order_number' => (string) ($row['order_number'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'due_date' => (string) ($row['due_date'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'salesperson' => (string) ($row['salesperson'] ?? ''),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'balance_due' => (float) ($row['balance_due'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'created_by' => (int) ($row['created_by'] ?? 0),
        ];
        if ($includeOrderType) {
            $entry['order_type'] = (string) ($row['order_type'] ?? 'spare');
        }
        $out[] = $entry;
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function sales_invoices_list_init_data(): array
{
    invoicesDeskBootstrap();

    global $pdo;
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';

    $soHasOrderType = false;
    $productsHasItemType = false;
    $soHasOrderNumber = false;
    $soCols = [];

    try {
        $soCols = $salesDb->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $soHasOrderType = in_array('order_type', $soCols, true);
        $soHasOrderNumber = in_array('order_number', $soCols, true);
        $prodCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $productsHasItemType = in_array('item_type', $prodCols, true);
    } catch (Throwable $e) {
        error_log('sales invoices list init schema: ' . $e->getMessage());
    }

    $orderTypeSelect = $soHasOrderType ? 'so.order_type' : 'NULL AS order_type';
    if ($soHasOrderNumber) {
        $orderNumberSelect = 'so.order_number';
    } elseif (in_array('formatted_number', $soCols, true)) {
        $orderNumberSelect = 'so.formatted_number AS order_number';
    } else {
        $orderNumberSelect = "CONCAT('SO-', so.id) AS order_number";
    }
    $vehicleLineSelect = $productsHasItemType
        ? "(SELECT COUNT(*) FROM sales_order_items soi INNER JOIN products p ON p.id = soi.product_id WHERE soi.order_id = so.id AND LOWER(TRIM(COALESCE(p.item_type, ''))) IN ('vehicle', 'truck')) AS _rm_vehicle_lines"
        : '0 AS _rm_vehicle_lines';

    $sql = "SELECT i.*, c.company_name AS customer_name, {$orderNumberSelect}, {$orderTypeSelect}, u.full_name AS salesperson,
            {$vehicleLineSelect}
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN sales_orders so ON i.order_id = so.id
            LEFT JOIN users u ON i.created_by = u.id";
    $scope = function_exists('salesCompanyScopeSql') ? salesCompanyScopeSql('invoices', 'i') : ['', []];
    if (!empty($scope[0])) {
        $sql .= ' WHERE 1=1' . $scope[0];
    }
    $sql .= ' ORDER BY i.id DESC';

    if (function_exists('sales_fixup_random_invoice_numbers')) {
        sales_fixup_random_invoice_numbers($salesDb);
    }

    $invoices = [];
    try {
        if (function_exists('sales_connection_has_table') && !sales_connection_has_table($salesDb, 'invoices')) {
            $invoices = [];
        } else {
            $params = isset($scope[1]) && is_array($scope[1]) ? $scope[1] : [];
            if (!empty($params)) {
                $stmtInvoices = $salesDb->prepare($sql);
                $stmtInvoices->execute($params);
                $invoices = $stmtInvoices->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $invoices = $salesDb->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }
    } catch (Throwable $e) {
        error_log('sales invoices list init query: ' . $e->getMessage());
        $invoices = [];
    }

    foreach ($invoices as &$invRow) {
        $vehicleLines = (int) ($invRow['_rm_vehicle_lines'] ?? 0);
        unset($invRow['_rm_vehicle_lines']);
        if (function_exists('salesSupportsTruckInvoices') && salesSupportsTruckInvoices()) {
            $ot = isset($invRow['order_type']) ? trim((string) $invRow['order_type']) : '';
            $storedTruck = (strtolower($ot) === 'truck');
            $invRow['order_type'] = ($storedTruck || $vehicleLines > 0) ? 'truck' : 'spare';
        } else {
            unset($invRow['order_type']);
        }
    }
    unset($invRow);

    $defaultCurrency = 'TZS';
    $settingsDb = $salesDb;
    try {
        if (function_exists('sales_connection_has_table') && !sales_connection_has_table($settingsDb, 'sales_settings')) {
            $settingsDb = $pdo;
        }
        if (function_exists('currentCompanyId')) {
            $cidInv = (int) currentCompanyId();
            if ($cidInv > 0) {
                $stInv = $settingsDb->prepare('SELECT default_currency FROM sales_settings WHERE company_id = ? LIMIT 1');
                $stInv->execute([$cidInv]);
                $rowInv = $stInv->fetch(PDO::FETCH_ASSOC);
                if (!empty($rowInv['default_currency'])) {
                    $defaultCurrency = strtoupper(trim((string) $rowInv['default_currency']));
                }
            }
        }
        if ($defaultCurrency === 'TZS') {
            $rowInv = $settingsDb->query('SELECT default_currency FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (!empty($rowInv['default_currency'])) {
                $defaultCurrency = strtoupper(trim((string) $rowInv['default_currency']));
            }
        }
    } catch (Throwable $e) {
        $defaultCurrency = 'TZS';
    }

    $isRoadmaster = function_exists('isRoadmaster') && isRoadmaster();
    $isUltimate = function_exists('isUltimate') && isUltimate();
    $supportsOrderTypeSplit = function_exists('salesSupportsTruckInvoices') && salesSupportsTruckInvoices();

    return [
        'invoices' => invoices_desk_invoices_for_api($invoices),
        'current_user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'is_admin' => function_exists('isAdmin') && isAdmin(),
        'is_roadmaster' => $isRoadmaster,
        'is_ultimate' => $isUltimate,
        'supports_order_type_split' => $supportsOrderTypeSplit,
        'use_rm_shell_layout' => $isRoadmaster || $isUltimate,
        'default_currency' => $defaultCurrency,
        'module' => $module,
        'urls' => [
            'create' => sales_module_url('invoices/create.php', ['module' => $module]),
            'create_truck' => $supportsOrderTypeSplit
                ? sales_module_url('invoices/create.php', ['type' => 'truck', 'module' => $module])
                : '',
            'create_spare' => $supportsOrderTypeSplit
                ? sales_module_url('invoices/create.php', ['type' => 'spare', 'module' => $module])
                : sales_module_url('invoices/create.php', ['module' => $module]),
            'view' => sales_module_url('invoices/view.php', ['module' => $module]),
            'print' => sales_module_url('invoices/print.php', ['module' => $module]),
            'delete' => sales_module_url('invoices/delete.php', ['module' => $module]),
            'settings' => sales_module_url('settings/index.php', ['module' => $module]),
            'list' => sales_module_url('invoices/index.php', ['module' => $module]),
        ],
    ];
}
