<?php

declare(strict_types=1);

function ordersDeskBootstrap(): void
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 4) . '/includes/config.php';
        require_once dirname(__DIR__, 4) . '/includes/functions.php';
        require_once dirname(__DIR__, 2) . '/functions.php';
        $booted = true;
    }
}

function ordersDeskRequireAccess(): void
{
    ordersDeskBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function ordersDeskLoadReactAssets(): ?array
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

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    $assetBase = function_exists('sales_app_url')
        ? sales_app_url('modules/sales/orders/frontend/dist/assets/')
        : '/modules/sales/orders/frontend/dist/assets/';
    $apiUrl = function_exists('sales_app_url')
        ? sales_app_url('modules/sales/orders/api')
        : '/modules/sales/orders/api';

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

function ordersDeskShellHeadExtras(): string
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
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function orders_desk_quotations_for_api(array $rows): array
{
    $includeOrderType = function_exists('salesSupportsTruckInvoices') && salesSupportsTruckInvoices();
    $out = [];
    foreach ($rows as $row) {
        $entry = [
            'id' => (int) ($row['id'] ?? 0),
            'order_number' => (string) ($row['order_number'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'company_name' => (string) ($row['company_name'] ?? ''),
            'salesperson' => (string) ($row['salesperson'] ?? ''),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'has_invoice' => !empty($row['has_invoice'])
                || in_array(strtolower(trim((string) ($row['status'] ?? ''))), ['invoiced', 'paid'], true),
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
 * Align order list rows with linked invoices (status => invoiced).
 *
 * @param list<array<string, mixed>> $rows
 */
function sales_desk_sync_order_rows_with_invoices(PDO $salesDb, array &$rows): void
{
    if ($rows === []) {
        return;
    }

    $orderIds = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $orderIds[$id] = $id;
        }
    }
    if ($orderIds === []) {
        return;
    }

    $orderIdList = array_values($orderIds);
    $placeholders = implode(',', array_fill(0, count($orderIdList), '?'));
    $invoiceSql = "SELECT DISTINCT order_id FROM invoices WHERE order_id IN ($placeholders)";
    $invoiceParams = $orderIdList;
    if (function_exists('salesAppendCompanyScope')) {
        salesAppendCompanyScope($invoiceSql, $invoiceParams, 'invoices');
    }

    $invoicedIds = [];
    try {
        $stmtInv = $salesDb->prepare($invoiceSql);
        $stmtInv->execute($invoiceParams);
        while ($col = $stmtInv->fetchColumn()) {
            $invoicedIds[(int) $col] = true;
        }
    } catch (Throwable $e) {
        error_log('sales_desk_sync_order_rows_with_invoices: ' . $e->getMessage());
        return;
    }

    if ($invoicedIds === []) {
        return;
    }

    foreach ($rows as &$row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || !isset($invoicedIds[$id])) {
            continue;
        }

        $statusKey = strtolower(trim((string) ($row['status'] ?? '')));
        if (in_array($statusKey, ['cancelled', 'canceled', 'paid'], true)) {
            continue;
        }

        if ($statusKey !== 'invoiced') {
            try {
                $updateSql = "UPDATE sales_orders SET status = 'invoiced' WHERE id = ?";
                $updateParams = [$id];
                if (function_exists('salesAppendCompanyScope')) {
                    salesAppendCompanyScope($updateSql, $updateParams, 'sales_orders');
                }
                $salesDb->prepare($updateSql)->execute($updateParams);
            } catch (Throwable $e) {
                error_log('sales_desk_sync_order_rows_with_invoices update: ' . $e->getMessage());
            }
        }

        $row['status'] = 'invoiced';
        $row['has_invoice'] = true;
    }
    unset($row);
}

/**
 * @return array<string, mixed>
 */
function sales_quotations_list_init_data(): array
{
    ordersDeskBootstrap();

    global $pdo;
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;

    $productsHasItemType = false;
    try {
        $prodCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
        $productsHasItemType = is_array($prodCols) && in_array('item_type', $prodCols, true);
    } catch (Throwable $e) {
        $productsHasItemType = false;
    }

    $isRoadmaster = function_exists('isRoadmaster') && isRoadmaster();
    $supportsOrderTypeSplit = function_exists('salesSupportsTruckInvoices') && salesSupportsTruckInvoices();

    $vehicleLineSelect = '0 AS _rm_vehicle_lines';
    if ($supportsOrderTypeSplit && $productsHasItemType) {
        $vehicleLineSelect = '(SELECT COUNT(*) FROM sales_order_items soi INNER JOIN products p ON p.id = soi.product_id WHERE soi.order_id = so.id AND LOWER(TRIM(COALESCE(p.item_type, \'\'))) IN (\'vehicle\', \'truck\')) AS _rm_vehicle_lines';
    }

    $listSql = "
        SELECT so.*, c.company_name, u.full_name AS salesperson, $vehicleLineSelect
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.id
        LEFT JOIN users u ON so.created_by = u.id";
    $listParams = [];
    $scope = function_exists('salesCompanyScopeSql') ? salesCompanyScopeSql('sales_orders', 'so') : ['', []];
    if ($scope[0] !== '') {
        $listSql .= ' WHERE 1=1' . $scope[0];
        $listParams = $scope[1];
    }
    $listSql .= ' ORDER BY so.created_at DESC';

    $quotations = [];
    try {
        $stmt = $salesDb->prepare($listSql);
        $stmt->execute($listParams);
        $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('sales quotations list init: ' . $e->getMessage());
        $quotations = [];
    }

    $mojibakeDash = "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9D";
    $realEmDash = "\xE2\x80\x94";
    foreach ($quotations as &$qRow) {
        foreach (['company_name', 'salesperson'] as $textCol) {
            if (isset($qRow[$textCol]) && is_string($qRow[$textCol]) && $qRow[$textCol] !== '') {
                $qRow[$textCol] = str_replace([$mojibakeDash, $realEmDash], '-', $qRow[$textCol]);
            }
        }
        $vehicleLines = (int) ($qRow['_rm_vehicle_lines'] ?? 0);
        unset($qRow['_rm_vehicle_lines']);
        if ($supportsOrderTypeSplit) {
            $ot = isset($qRow['order_type']) ? trim((string) $qRow['order_type']) : '';
            $storedTruck = (strtolower($ot) === 'truck');
            $qRow['order_type'] = ($storedTruck || $vehicleLines > 0) ? 'truck' : 'spare';
        } else {
            unset($qRow['order_type']);
        }
    }
    unset($qRow);

    sales_desk_sync_order_rows_with_invoices($salesDb, $quotations);

    $defaultCurrency = 'TZS';
    try {
        if (function_exists('currentCompanyId')) {
            $cid = (int) currentCompanyId();
            if ($cid > 0) {
                $st = $salesDb->prepare('SELECT default_currency FROM sales_settings WHERE company_id = ? LIMIT 1');
                $st->execute([$cid]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!empty($row['default_currency'])) {
                    $defaultCurrency = strtoupper(trim((string) $row['default_currency']));
                }
            }
        }
        if ($defaultCurrency === 'TZS') {
            $row = $salesDb->query('SELECT default_currency FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['default_currency'])) {
                $defaultCurrency = strtoupper(trim((string) $row['default_currency']));
            }
        }
    } catch (Throwable $e) {
        $defaultCurrency = 'TZS';
    }

    $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';
    $isUltimate = function_exists('isUltimate') && isUltimate();

    $flash = '';
    if (isset($_GET['msg']) && (string) $_GET['msg'] === 'deleted') {
        $flash = 'Selected quotations were deleted.';
    }

    return [
        'quotations' => orders_desk_quotations_for_api($quotations),
        'current_user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'is_roadmaster' => $isRoadmaster,
        'is_ultimate' => $isUltimate,
        'supports_order_type_split' => $supportsOrderTypeSplit,
        'use_rm_shell_layout' => $isRoadmaster || $isUltimate,
        'default_currency' => $defaultCurrency,
        'module' => $module,
        'flash' => $flash,
        'urls' => [
            'create_new' => sales_module_url('orders/create.php', ['mode' => 'new', 'module' => $module]),
            'create_truck' => $supportsOrderTypeSplit
                ? sales_module_url('orders/create.php', ['mode' => 'new', 'type' => 'truck', 'module' => $module])
                : '',
            'create_spare' => $supportsOrderTypeSplit
                ? sales_module_url('orders/create.php', ['mode' => 'new', 'type' => 'spare', 'module' => $module])
                : sales_module_url('orders/create.php', ['mode' => 'new', 'module' => $module]),
            'view' => sales_module_url('orders/view.php', ['module' => $module]),
            'print' => sales_module_url('orders/print.php', ['module' => $module]),
            'invoice_create' => sales_module_url('invoices/create.php', ['module' => $module]),
            'delete_post' => sales_module_url('orders/create.php', ['module' => $module]),
            'settings' => sales_module_url('settings/index.php', ['module' => $module]),
            'list' => sales_module_url('orders/create.php', ['module' => $module]),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function sales_orders_list_init_data(): array
{
    $data = sales_quotations_list_init_data();
    $module = (string) ($data['module'] ?? 'sales');

    $data['desk'] = 'sales_orders';
    $data['orders'] = $data['quotations'];
    unset($data['quotations']);
    $data['urls']['list'] = sales_module_url('orders/index.php', ['module' => $module]);
    $data['urls']['quotations'] = sales_module_url('orders/create.php', ['module' => $module]);

    return $data;
}

/**
 * Render the sales orders list React shell.
 */
function salesOrdersListRenderReactShell(): void
{
    $assets = ordersDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Sales Orders</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Sales Orders</h1>';
        echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/sales/orders/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'Sales Orders';
    $employeeHeaderTitle = 'Sales Orders';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';
    $ordersPage = 'sales_orders';

    $cfg = [
        'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'sales',
    ];

    $ordersHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__SALES_ORDERS_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__SALES_ORDERS_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__ORDERS_DESK_PAGE__ = ' . json_encode('sales_orders', JSON_UNESCAPED_SLASHES) . ';</script>';

    require dirname(__FILE__) . '/orders-react-shell.php';
    exit;
}

/**
 * Render the quotations list React shell.
 */
function salesQuotationsListRenderReactShell(): bool
{
    $assets = ordersDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Quotations</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Quotations</h1>';
        echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/sales/orders/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'Quotations';
    $employeeHeaderTitle = 'Quotations';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';
    $ordersPage = 'list';

    $cfg = [
        'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'sales',
    ];

    $ordersHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__QUOTATIONS_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__QUOTATIONS_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__ORDERS_DESK_PAGE__ = ' . json_encode('quotations', JSON_UNESCAPED_SLASHES) . ';</script>';

    require dirname(__FILE__) . '/orders-react-shell.php';
    exit;
}
