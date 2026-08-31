<?php
/**
 * Debug helper for order -> invoice conversion (create.php?order_id=...).
 *
 * Usage (browser, must be logged in):
 *   .../invoices/debug-order-invoice.php?order_id=359&module=sales
 *   .../invoices/debug-order-invoice.php?order_id=359&format=json
 *   .../invoices/debug-order-invoice.php?order_id=359&run=1   (actually creates invoice)
 *
 * Usage (CLI):
 *   php debug-order-invoice.php 359
 *   php debug-order-invoice.php 359 --run
 *
 * Remove this file after debugging.
 */

$isCli = PHP_SAPI === 'cli';
$fatalError = null;

set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/../../../includes/config.php';
    require_once __DIR__ . '/../../../includes/functions.php';
    require_once __DIR__ . '/../functions.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!$isCli) {
        requireLogin();
    }
} catch (Throwable $e) {
    $fatalError = [
        'stage' => 'bootstrap',
        'message' => $e->getMessage(),
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
}

$orderId = 0;
$module = 'sales';
$runConvert = false;
$format = 'html';

if ($fatalError === null) {
    if ($isCli) {
        global $argv;
        $orderId = isset($argv[1]) ? (int) $argv[1] : 0;
        $runConvert = in_array('--run', $argv ?? [], true);
        $format = in_array('--json', $argv ?? [], true) ? 'json' : 'text';
    } else {
        $orderId = (int) ($_GET['order_id'] ?? 0);
        $module = trim((string) ($_GET['module'] ?? 'sales'));
        if ($module === '') {
            $module = 'sales';
        }
        $runConvert = !empty($_GET['run']);
        $format = strtolower(trim((string) ($_GET['format'] ?? 'html')));
    }
}

/** @param array<string, mixed> $payload */
function debug_output(array $payload, string $format, bool $isCli, int $exitCode = 0): void
{
    if ($format === 'json' || ($isCli && $format === 'text')) {
        if (!$isCli && !headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            if ($exitCode !== 0) {
                http_response_code(500);
            }
        }
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit($exitCode);
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        if ($exitCode !== 0) {
            http_response_code(500);
        }
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Debug order invoice</title>';
    echo '<style>body{font-family:monospace;background:#0f172a;color:#e2e8f0;padding:1.25rem}pre{white-space:pre-wrap;word-break:break-word}</style>';
    echo '</head><body><h1>Debug order invoice</h1><pre>' . htmlspecialchars((string) $json, ENT_QUOTES, 'UTF-8') . '</pre></body></html>';
    exit($exitCode);
}

if ($fatalError !== null) {
    debug_output(['ok' => false, 'fatal' => $fatalError], $isCli ? 'text' : 'html', $isCli, 1);
}

if ($orderId <= 0) {
    $usage = $isCli
        ? 'Usage: php debug-order-invoice.php <order_id> [--run] [--json]'
        : 'Missing order_id. Example: debug-order-invoice.php?order_id=359&module=sales';
    debug_output(['ok' => false, 'error' => $usage], $format === 'json' ? 'json' : 'html', $isCli, 1);
}

/** @return array<string, mixed> */
function debug_pdo_info($conn, $label)
{
    $info = [
        'label' => $label,
        'connected' => $conn instanceof PDO,
        'database' => null,
        'has_sales_orders' => false,
        'has_invoices' => false,
    ];

    if (!$conn instanceof PDO) {
        return $info;
    }

    try {
        $info['database'] = (string) $conn->query('SELECT DATABASE()')->fetchColumn();
    } catch (Throwable $e) {
        $info['database_error'] = $e->getMessage();
    }

    foreach (['sales_orders', 'invoices'] as $table) {
        try {
            $chk = $conn->query('SHOW TABLES LIKE ' . $conn->quote($table));
            $exists = ($chk && $chk->fetch(PDO::FETCH_NUM));
            if ($table === 'sales_orders') {
                $info['has_sales_orders'] = (bool) $exists;
            } else {
                $info['has_invoices'] = (bool) $exists;
            }
        } catch (Throwable $e) {
            $info['table_check_error_' . $table] = $e->getMessage();
        }
    }

    return $info;
}

/** @return array<string, mixed>|null */
function debug_fetch_order(PDO $conn, $orderId, $scoped)
{
    try {
        $sql = 'SELECT * FROM sales_orders WHERE id = ?';
        $params = [(int) $orderId];
        if ($scoped && function_exists('salesCompanyScopeSql')) {
            $scope = salesCompanyScopeSql('sales_orders');
            $sql .= $scope[0];
            $params = array_merge($params, $scope[1]);
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return ['_query_error' => $e->getMessage()];
    }
}

/** @return array<string, mixed>|null */
function debug_fetch_invoice_for_order(PDO $conn, $orderId, $scoped)
{
    try {
        $sql = 'SELECT * FROM invoices WHERE order_id = ?';
        $params = [(int) $orderId];
        if ($scoped && function_exists('salesCompanyScopeSql')) {
            $scope = salesCompanyScopeSql('invoices');
            $sql .= $scope[0];
            $params = array_merge($params, $scope[1]);
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return ['_query_error' => $e->getMessage()];
    }
}

/** @return list<string> */
function debug_table_columns(PDO $conn, $table)
{
    try {
        $safeTable = str_replace('`', '', (string) $table);
        return $conn->query('SHOW COLUMNS FROM `' . $safeTable . '`')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @param array<string, mixed>|null $row */
function debug_row_is_valid($row)
{
    return is_array($row) && !isset($row['_query_error']);
}

$report = [
    'ok' => false,
    'order_id' => $orderId,
    'module' => $module,
    'mode' => $runConvert ? 'run' : 'dry-run',
    'fatal' => null,
];

try {
    global $pdo;

    $companyId = (int) (currentCompanyId() ?? 0);
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    $sameConnection = ($pdo instanceof PDO && $salesDb instanceof PDO && $pdo === $salesDb);

    $pdoInfo = debug_pdo_info($pdo instanceof PDO ? $pdo : null, 'global $pdo');
    $salesDbInfo = debug_pdo_info($salesDb instanceof PDO ? $salesDb : null, 'sales_pdo()');

    if ($pdo instanceof PDO) {
        $pdoInfo['order_row'] = debug_fetch_order($pdo, $orderId, false);
        $pdoInfo['order_row_scoped'] = debug_fetch_order($pdo, $orderId, true);
        $pdoInfo['invoice_for_order'] = debug_fetch_invoice_for_order($pdo, $orderId, true);
    }
    if ($salesDb instanceof PDO) {
        $salesDbInfo['order_row'] = debug_fetch_order($salesDb, $orderId, false);
        $salesDbInfo['order_row_scoped'] = debug_fetch_order($salesDb, $orderId, true);
        $salesDbInfo['invoice_for_order'] = debug_fetch_invoice_for_order($salesDb, $orderId, true);
    }

    $invCols = $salesDb instanceof PDO ? debug_table_columns($salesDb, 'invoices') : [];
    $soCols = $salesDb instanceof PDO ? debug_table_columns($salesDb, 'sales_orders') : [];
    $itemCount = 0;
    if ($salesDb instanceof PDO) {
        try {
            $stItems = $salesDb->prepare('SELECT COUNT(*) FROM sales_order_items WHERE order_id = ?');
            $stItems->execute([$orderId]);
            $itemCount = (int) $stItems->fetchColumn();
        } catch (Throwable $e) {
            $itemCount = -1;
            $report['item_count_error'] = $e->getMessage();
        }
    }

    $createUrl = function_exists('sales_module_url')
        ? sales_module_url('invoices/create.php', ['order_id' => $orderId, 'module' => $module])
        : ('create.php?order_id=' . $orderId . '&module=' . rawurlencode($module));

    $redirectPreview = null;
    if (debug_row_is_valid($salesDbInfo['invoice_for_order'] ?? null)) {
        $existingId = (int) $salesDbInfo['invoice_for_order']['id'];
        $redirectPreview = function_exists('sales_module_url')
            ? sales_module_url('invoices/view.php', ['id' => $existingId, 'module' => $module])
            : ('view.php?id=' . $existingId);
    }

    $blockers = [];
    if (!$salesDb instanceof PDO) {
        $blockers[] = 'sales_pdo() is not available';
    }
    if ($salesDb instanceof PDO && !$salesDbInfo['has_sales_orders']) {
        $blockers[] = 'sales_orders table missing in sales DB';
    }
    if ($salesDb instanceof PDO && !$salesDbInfo['has_invoices']) {
        $blockers[] = 'invoices table missing in sales DB';
    }
    if ($salesDb instanceof PDO && !debug_row_is_valid($salesDbInfo['order_row_scoped'] ?? null)) {
        $err = is_array($salesDbInfo['order_row_scoped'] ?? null) ? ($salesDbInfo['order_row_scoped']['_query_error'] ?? 'missing row') : 'missing row';
        $blockers[] = 'Order not found in sales_pdo() with company scope (' . $err . ')';
    }
    if ($salesDb instanceof PDO && debug_row_is_valid($salesDbInfo['order_row_scoped'] ?? null)) {
        $st = (string) ($salesDbInfo['order_row_scoped']['status'] ?? '');
        if ($st !== 'confirmed' && !debug_row_is_valid($salesDbInfo['invoice_for_order'] ?? null)) {
            $blockers[] = 'Order status is "' . $st . '" (Create Invoice expects "confirmed")';
        }
        $custId = (int) ($salesDbInfo['order_row_scoped']['customer_id'] ?? 0);
        if ($custId <= 0 && !debug_row_is_valid($salesDbInfo['invoice_for_order'] ?? null)) {
            $blockers[] = 'Order has no customer_id (fallback customer lookup may apply)';
        }
    }
    if ($invCols === []) {
        $blockers[] = 'Could not read invoices table columns';
    }

    $convertResult = null;
    $convertError = null;
    if ($runConvert) {
        $includePath = __DIR__ . '/includes/invoice-from-order.php';
        if (!is_file($includePath)) {
            $convertError = [
                'message' => 'Missing include: ' . $includePath,
                'type' => 'FileNotFound',
            ];
        } else {
            require_once $includePath;
            try {
                $convertResult = sales_convert_order_to_invoice($orderId, $module);
            } catch (Throwable $e) {
                $convertError = [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
            }
        }
    }

    $nextInvoiceNumber = null;
    $nextInvoiceNumberError = null;
    if ($salesDb instanceof PDO && function_exists('sales_next_invoice_number')) {
        try {
            $nextInvoiceNumber = sales_next_invoice_number($salesDb, $companyId);
        } catch (Throwable $e) {
            $nextInvoiceNumberError = $e->getMessage();
        }
    }

    $report = array_merge($report, [
        'ok' => $convertError === null && ($runConvert ? $convertResult !== null : count($blockers) === 0 || debug_row_is_valid($salesDbInfo['invoice_for_order'] ?? null)),
        'session' => [
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'company_id' => (int) ($_SESSION['company_id'] ?? 0),
            'company_slug' => (string) ($_SESSION['company_slug'] ?? ''),
        ],
        'company_id_resolved' => $companyId,
        'connections' => [
            'same_pdo_instance' => $sameConnection,
            'global_pdo' => $pdoInfo,
            'sales_pdo' => $salesDbInfo,
        ],
        'schema' => [
            'sales_orders_columns' => $soCols,
            'invoices_columns' => $invCols,
            'sales_order_item_count' => $itemCount,
        ],
        'urls' => [
            'create_php' => $createUrl,
            'expected_redirect_if_invoice_exists' => $redirectPreview,
        ],
        'blockers' => $blockers,
        'next_invoice_number_preview' => $nextInvoiceNumber,
        'next_invoice_number_error' => $nextInvoiceNumberError,
        'convert_result' => $convertResult,
        'convert_error' => $convertError,
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'display_errors' => ini_get('display_errors'),
            'headers_sent' => headers_sent(),
        ],
        'files' => [
            'invoice_from_order_exists' => is_file(__DIR__ . '/includes/invoice-from-order.php'),
            'create_php_exists' => is_file(__DIR__ . '/create.php'),
        ],
    ]);
} catch (Throwable $e) {
    $report['ok'] = false;
    $report['fatal'] = [
        'message' => $e->getMessage(),
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
}

debug_output($report, $format, $isCli, $report['ok'] ? 0 : 1);
