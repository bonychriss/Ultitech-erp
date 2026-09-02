<?php
// modules/sales/functions.php

if (!function_exists('clean_input')) {
    function clean_input($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
}

if (!function_exists('sales_json_script')) {
    function sales_json_script($data) {
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                return 'null';
            }
        }
        return $json;
    }
}

if (!function_exists('sales_resolve_document_signature_url')) {
    /**
     * Resolve authorized-signature image URL for invoices, orders, and quotations.
     *
     * @param array    $document Row with optional created_by, salesperson, order_id
     * @param PDO|null $salesDb  Optional PDO for sales_orders.created_by lookup
     */
    function sales_resolve_document_signature_url(array $document, $salesDb = null): string
    {
        $candidateIds = [];
        if (!empty($document['created_by'])) {
            $candidateIds[] = (int) $document['created_by'];
        }
        if (!empty($document['order_id']) && $salesDb instanceof PDO) {
            try {
                $stmt = $salesDb->prepare('SELECT created_by FROM sales_orders WHERE id = ? LIMIT 1');
                $stmt->execute([(int) $document['order_id']]);
                $orderCreatorId = (int) $stmt->fetchColumn();
                if ($orderCreatorId > 0) {
                    $candidateIds[] = $orderCreatorId;
                }
            } catch (Throwable $e) {
            }
        }
        if (!empty($_SESSION['user_id'])) {
            $candidateIds[] = (int) $_SESSION['user_id'];
        }
        $candidateIds = array_values(array_unique(array_filter($candidateIds)));

        foreach ($candidateIds as $userId) {
            $rawPath = function_exists('getUserSignaturePathById')
                ? getUserSignaturePathById($userId)
                : null;
            $url = ($rawPath && function_exists('mediaUrlFromPath'))
                ? mediaUrlFromPath($rawPath)
                : '';
            if ($url !== '') {
                return $url;
            }
        }

        $salesperson = trim((string) ($document['salesperson'] ?? ''));
        if ($salesperson !== '' && function_exists('getUserSignaturePathByName')) {
            $rawPath = getUserSignaturePathByName($salesperson);
            if ($rawPath && function_exists('mediaUrlFromPath')) {
                return mediaUrlFromPath($rawPath);
            }
        }

        return '';
    }
}

/**
 * True when URL tenant routing is on but sales tables (sales_orders, etc.) live on control DB.
 */
function sales_uses_control_database()
{
    if (defined('SALES_ON_CONTROL_DB') && SALES_ON_CONTROL_DB) {
        return true;
    }
    global $pdo, $control_pdo;
    if (!($control_pdo instanceof PDO)) {
        return false;
    }
    if ($pdo === $control_pdo) {
        return false;
    }
    $tenantConn = isset($GLOBALS['tenant_pdo']) && $GLOBALS['tenant_pdo'] instanceof PDO
        ? $GLOBALS['tenant_pdo']
        : $pdo;
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = false;
    try {
        $chk = $tenantConn->query("SHOW TABLES LIKE 'sales_orders'");
        if (!$chk || !$chk->fetch(PDO::FETCH_NUM)) {
            $cached = true;
        }
    } catch (Throwable $e) {
        $cached = true;
    }
    return $cached;
}

/**
 * Check whether a PDO connection has a given table.
 */
function sales_connection_has_table($conn, $table)
{
    if (!($conn instanceof PDO)) {
        return false;
    }
    try {
        $st = $conn->query('SHOW TABLES LIKE ' . $conn->quote((string) $table));
        return ($st && $st->fetch(PDO::FETCH_NUM));
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * True when connection has a usable sales_orders table (not an empty/stub schema).
 */
function sales_connection_has_sales_orders_schema($conn)
{
    if (!sales_connection_has_table($conn, 'sales_orders')) {
        return false;
    }
    try {
        $cols = $conn->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: array();
        return in_array('order_number', $cols, true)
            || in_array('formatted_number', $cols, true)
            || in_array('customer_id', $cols, true);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * True when a connection has at least one non-cancelled invoice or fulfilled sales order.
 */
function sales_connection_has_sales_activity($conn)
{
    if (!($conn instanceof PDO)) {
        return false;
    }
    try {
        if (sales_connection_has_table($conn, 'invoices')) {
            $invoiceCount = (int) $conn->query("SELECT COUNT(*) FROM invoices WHERE status NOT IN ('cancelled')")->fetchColumn();
            if ($invoiceCount > 0) {
                return true;
            }
        }
        if (sales_connection_has_sales_orders_schema($conn)) {
            $orderCount = (int) $conn->query("SELECT COUNT(*) FROM sales_orders WHERE status IN ('confirmed','invoiced','shipped','paid','delivered')")->fetchColumn();

            return $orderCount > 0;
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

/**
 * PDO for the logged-in company's configured tenant database (when it has sales tables).
 */
function sales_resolve_company_tenant_pdo()
{
    global $control_pdo;

    if (!($control_pdo instanceof PDO) || !function_exists('connectToTenantDatabase')) {
        return null;
    }

    $cid = (int) (currentCompanyId() ?? 0);
    if ($cid <= 0 && !empty($_SESSION['company_id'])) {
        $cid = (int) $_SESSION['company_id'];
    }
    if ($cid <= 0) {
        return null;
    }

    try {
        $st = $control_pdo->prepare('SELECT db_name, db_host FROM companies WHERE id = ? LIMIT 1');
        $st->execute([$cid]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $dbName = trim((string) ($row['db_name'] ?? ''));
        if ($dbName === '') {
            return null;
        }
        $dbHost = trim((string) ($row['db_host'] ?? ''));
        $tenantPdo = connectToTenantDatabase($dbName, $dbHost !== '' ? $dbHost : null);
        if ($tenantPdo instanceof PDO && sales_connection_has_sales_orders_schema($tenantPdo)) {
            return $tenantPdo;
        }
    } catch (Throwable $e) {
        error_log('sales_resolve_company_tenant_pdo: ' . $e->getMessage());
    }

    return null;
}

/**
 * Find the MySQL database that contains sales_orders (may differ from voucher tenant DB).
 */
function sales_find_database_pdo()
{
    static $resolved = null;
    static $resolvedCid = -1;

    $cid = (int) (function_exists('currentCompanyId') ? (currentCompanyId() ?? 0) : 0);
    if ($cid <= 0 && !empty($_SESSION['company_id'])) {
        $cid = (int) $_SESSION['company_id'];
    }
    if ($resolved instanceof PDO && $resolvedCid === $cid) {
        return $resolved;
    }

    global $pdo, $control_pdo;

    if ($pdo instanceof PDO && defined('IS_TENANT_DB') && IS_TENANT_DB
        && sales_connection_has_sales_orders_schema($pdo)) {
        $resolved = $pdo;
        $resolvedCid = $cid;
        try {
            $GLOBALS['sales_database_name'] = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
        }

        return $resolved;
    }

    $companyPdo = sales_resolve_company_tenant_pdo();
    if ($companyPdo instanceof PDO) {
        $resolved = $companyPdo;
        $resolvedCid = $cid;
        try {
            $GLOBALS['sales_database_name'] = (string) $companyPdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
        }

        return $resolved;
    }

    $tryConnections = array();
    if ($pdo instanceof PDO) {
        $tryConnections[] = $pdo;
    }
    if ($control_pdo instanceof PDO && $control_pdo !== $pdo) {
        $tryConnections[] = $control_pdo;
    }

    $schemaFallback = null;
    foreach ($tryConnections as $conn) {
        if (!sales_connection_has_sales_orders_schema($conn)) {
            continue;
        }
        if ($schemaFallback === null) {
            $schemaFallback = $conn;
        }
        if (sales_connection_has_sales_activity($conn)) {
            $resolved = $conn;
            $resolvedCid = $cid;
            try {
                $GLOBALS['sales_database_name'] = (string) $conn->query('SELECT DATABASE()')->fetchColumn();
            } catch (Throwable $e) {
            }

            return $resolved;
        }
    }

    $dbCandidates = array();

    if (defined('DATA_DB_NAME') && trim((string) DATA_DB_NAME) !== '') {
        $dbCandidates[] = trim((string) DATA_DB_NAME);
    }
    if (defined('SALES_DB_NAME') && trim((string) SALES_DB_NAME) !== '') {
        $dbCandidates[] = trim((string) SALES_DB_NAME);
    }

    if ($control_pdo instanceof PDO) {
        try {
            $cid = (int) (currentCompanyId() ?? 0);
            if ($cid <= 0 && !empty($_SESSION['company_id'])) {
                $cid = (int) $_SESSION['company_id'];
            }
            if ($cid > 0) {
                $st = $control_pdo->prepare('SELECT db_name FROM companies WHERE id = ? LIMIT 1');
                $st->execute(array($cid));
                $rowDb = trim((string) ($st->fetchColumn() ?: ''));
                if ($rowDb !== '') {
                    $dbCandidates[] = $rowDb;
                }
            }
        } catch (Throwable $e) {
        }
        try {
            foreach ($control_pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) as $dbName) {
                $dbName = (string) $dbName;
                if ($dbName === '' || in_array($dbName, array('information_schema', 'performance_schema', 'mysql', 'sys'), true)) {
                    continue;
                }
                if (preg_match('/ultimate|trading|voucher|sales|roadmaster/i', $dbName)) {
                    $dbCandidates[] = $dbName;
                }
            }
        } catch (Throwable $e) {
        }
    }

    $dbCandidates[] = 'new_trading_voucher_db';
    $dbCandidates[] = 'ultimate_trading_voucher';

    $dbCandidates = array_values(array_unique(array_filter($dbCandidates)));
    $candidateFallback = null;
    foreach ($dbCandidates as $dbName) {
        if ($dbName === '' || !function_exists('connectToTenantDatabase')) {
            continue;
        }
        $tenantPdo = connectToTenantDatabase($dbName);
        if (!($tenantPdo instanceof PDO) || !sales_connection_has_sales_orders_schema($tenantPdo)) {
            continue;
        }
        if ($candidateFallback === null) {
            $candidateFallback = $tenantPdo;
        }
        if (sales_connection_has_sales_activity($tenantPdo)) {
            $resolved = $tenantPdo;
            $resolvedCid = $cid;
            $GLOBALS['sales_database_name'] = $dbName;
            if (!defined('SALES_ON_CONTROL_DB')) {
                define('SALES_ON_CONTROL_DB', true);
            }
            error_log('sales_find_database_pdo: using database ' . $dbName);

            return $resolved;
        }
    }

    if ($schemaFallback instanceof PDO) {
        $resolved = $schemaFallback;
        $resolvedCid = $cid;
        try {
            $GLOBALS['sales_database_name'] = (string) $schemaFallback->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
        }

        return $resolved;
    }

    if ($candidateFallback instanceof PDO) {
        $resolved = $candidateFallback;
        $resolvedCid = $cid;
        try {
            $GLOBALS['sales_database_name'] = (string) $candidateFallback->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
        }
        if (!defined('SALES_ON_CONTROL_DB')) {
            define('SALES_ON_CONTROL_DB', true);
        }

        return $resolved;
    }

    $resolved = ($pdo instanceof PDO) ? $pdo : $control_pdo;
    $resolvedCid = $cid;
    return $resolved;
}

/**
 * True when the current HTTP request targets the sales module.
 */
function sales_is_module_request()
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (stripos($uri, '/modules/sales/') !== false || stripos($script, '/modules/sales/') !== false) {
        return true;
    }
    return (bool) preg_match('#/[a-z0-9][a-z0-9-]*/sales(/|$|\?)#i', $uri);
}

/**
 * PDO for sales module queries (falls back to control DB when tenant DB has no sales tables).
 */
function sales_pdo()
{
    return sales_find_database_pdo();
}

/**
 * Resolve PDO for sales tables (never probes sales_orders on tenant — avoids fatal 1146).
 */
function sales_resolve_db()
{
    return sales_pdo();
}

/**
 * SQL fragment + params for company_id filtering on shared control DB (includes legacy NULL rows).
 *
 * @return array{0:string,1:array<int,mixed>}
 */
function salesCompanyScopeSql($table, $alias = '')
{
    if (defined('IS_TENANT_DB') && IS_TENANT_DB && !sales_uses_control_database()) {
        return ['', []];
    }
    $cid = (int) (currentCompanyId() ?? 0);
    if ($cid <= 0) {
        return ['', []];
    }
    $conn = sales_pdo();
    static $hasColumn = [];
    $colKey = $table . "\0" . $alias;
    if (!isset($hasColumn[$colKey])) {
        $hasColumn[$colKey] = function_exists('columnExists') && columnExists($table, 'company_id', $conn);
    }
    if (!$hasColumn[$colKey]) {
        return ['', []];
    }
    $safeAlias = $alias !== '' ? preg_replace('/[^a-z0-9_]/i', '', $alias) : '';
    $col = ($safeAlias !== '' ? $safeAlias . '.' : '') . 'company_id';

    static $strictTagged = [];
    $tagKey = $table . ':' . $cid;
    if (!isset($strictTagged[$tagKey])) {
        $strictTagged[$tagKey] = false;
        try {
            $st = $conn->prepare("SELECT COUNT(*) FROM `{$table}` WHERE company_id = ?");
            $st->execute([$cid]);
            $strictTagged[$tagKey] = ((int) $st->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $strictTagged[$tagKey] = true;
        }
    }

    if ($strictTagged[$tagKey]) {
        return [" AND {$col} = ?", [$cid]];
    }

    return [" AND ({$col} = ? OR {$col} IS NULL OR {$col} = 0)", [$cid]];
}

/**
 * Append company scope to a SQL string and bound parameters.
 */
function salesAppendCompanyScope(&$sql, &$params, $table, $alias = '')
{
    $scope = salesCompanyScopeSql($table, $alias);
    $sql .= $scope[0];
    foreach ($scope[1] as $p) {
        $params[] = $p;
    }
}

/**
 * Named placeholder variant for UNION queries (single ? in fragment).
 *
 * @return array{0:string,1:int|null} SQL fragment and company id to bind
 */
function salesCompanyScopeNamed($table, $alias, $paramName)
{
    $scope = salesCompanyScopeSql($table, $alias);
    if ($scope[0] === '') {
        return ['', null];
    }
    $safeName = preg_replace('/[^a-z0-9_]/i', '', $paramName);
    $sql = str_replace('?', ':' . $safeName, $scope[0]);
    return [$sql, (int) $scope[1][0]];
}

/**
 * Company scope for sales queries: only when shared DB has company_id on the table.
 * Tenant databases are already isolated — no column filter needed.
 * @deprecated Prefer salesCompanyScopeSql() for correct legacy NULL handling.
 */
function salesScopedCompanyId($table)
{
    $scope = salesCompanyScopeSql($table);
    if ($scope[0] === '') {
        return null;
    }
    return (int) (currentCompanyId() ?? 0);
}

function getSalesTotal($user_id, $month) {
    $pdo = sales_pdo();
    $sql = "
        SELECT SUM(total_amount) 
        FROM invoices 
        WHERE created_by = ? 
        AND DATE_FORMAT(created_at, '%Y-%m') = ? 
        AND status != 'cancelled'";
    $params = [$user_id, $month];
    salesAppendCompanyScope($sql, $params, 'invoices');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() ?: 0;
}

function getPendingOrders($user_id) {
    $pdo = sales_pdo();
    $sql = "SELECT COUNT(*) FROM sales_orders WHERE created_by = ? AND status IN ('draft', 'quotation')";
    $params = [$user_id];
    salesAppendCompanyScope($sql, $params, 'sales_orders');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() ?: 0;
}

function getOverdueInvoices($user_id) {
    $pdo = sales_pdo();
    $sql = "SELECT COUNT(*) FROM invoices WHERE created_by = ? AND status = 'overdue'";
    $params = [$user_id];
    salesAppendCompanyScope($sql, $params, 'invoices');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() ?: 0;
}

function getTopCustomers($user_id, $limit = 5) {
    $pdo = sales_pdo();
    $stmt = $pdo->prepare("
        SELECT c.id, c.company_name, c.contact_person, 
               SUM(so.total_amount) as total_purchases
        FROM customers c
        JOIN sales_orders so ON c.id = so.customer_id
        WHERE so.created_by = ?
        AND so.status IN ('confirmed', 'invoiced', 'paid', 'delivered')
        GROUP BY c.id
        ORDER BY total_purchases DESC
        LIMIT " . intval($limit)
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSalesTarget($user_id, $period) {
    $pdo = sales_pdo();
    ensureSalesTargetsSchema();
    $stmt = $pdo->prepare("SELECT target_amount FROM sales_targets WHERE user_id = ? AND period = ?");
    $stmt->execute([$user_id, $period]);
    $result = $stmt->fetchColumn();
    return $result ? $result : 75000000.00;
}

function getCommissionEarned($user_id, $month) {
    $pdo = sales_pdo();
    ensureSalesCommissionsSchema();
    $stmt = $pdo->prepare("
        SELECT SUM(commission_amount) 
        FROM sales_commissions 
        WHERE sales_rep_id = ? 
        AND DATE_FORMAT(created_at, '%Y-%m') = ?
    ");
    $stmt->execute([$user_id, $month]);
    return $stmt->fetchColumn() ?: 0;
}

function getRecentQuotes($user_id, $limit = 5) {
    $pdo = sales_pdo();
    $stmt = $pdo->prepare("
        SELECT so.*, c.company_name as customer_name 
        FROM sales_orders so
        JOIN customers c ON so.customer_id = c.id
        WHERE so.created_by = ?
        ORDER BY so.created_at DESC
        LIMIT " . intval($limit)
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getStatusColor($status) {
    switch ($status) {
        case 'draft': return 'secondary';
        case 'quotation': return 'info';
        case 'confirmed': return 'primary';
        case 'processing': return 'warning';
        case 'shipped': return 'info';
        case 'delivered': return 'success';
        case 'invoiced': return 'primary';
        case 'paid': return 'success';
        case 'cancelled': return 'danger';
        case 'on_hold': return 'warning';
        default: return 'secondary';
    }
}

function getNextOrderNumber() {
    $pdo = sales_pdo();
    $year = date('Y');
    $prefix = "SO-$year-";
    $prefixLen = strlen($prefix) + 1; // +1 for 1-based substring
    
    // Find the highest number suffix used this year
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(order_number, ?) AS UNSIGNED)) FROM sales_orders WHERE order_number LIKE ?");
    $stmt->execute([$prefixLen, "$prefix%"]);
    $maxNum = $stmt->fetchColumn();
    
    return ($maxNum ? $maxNum : 0) + 1;
}
function ensureShareTokensSchema() {
    $pdo = sales_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_share_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL,
        doc_type ENUM('order', 'invoice') NOT NULL,
        doc_id INT NOT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME NULL,
        expires_at DATETIME NULL,
        INDEX (token)
    )");

    // Migration: Add expires_at if missing (for users who had the first version of the fix)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM sales_share_tokens")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('expires_at', $cols)) {
            $pdo->exec("ALTER TABLE sales_share_tokens ADD COLUMN expires_at DATETIME AFTER used_at");
        }
    } catch (Exception $e) {}

    // Backfill: Set expires_at for old records that have it as NULL
    try {
        $pdo->exec("UPDATE sales_share_tokens SET expires_at = DATE_ADD(created_at, INTERVAL 24 HOUR) WHERE expires_at IS NULL");
    } catch (Exception $e) {}
}

function generateShareToken($docType, $docId, $userId) {
    $pdo = sales_pdo();
    ensureShareTokensSchema();
    
    // Reuse existing token if it's still valid (within 24 hours)
    $stmt = $pdo->prepare("SELECT token FROM sales_share_tokens 
                           WHERE doc_type = ? AND doc_id = ? 
                           AND expires_at > NOW() 
                           ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$docType, $docId]);
    $existing = $stmt->fetchColumn();
    
    if ($existing) {
        return $existing;
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    $stmt = $pdo->prepare("INSERT INTO sales_share_tokens (token, doc_type, doc_id, created_by, expires_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$token, $docType, $docId, $userId, $expiresAt]);
    return $token;
}
function checkStockAvailability($orderId) {
    $pdo = sales_pdo();
    $stmt = $pdo->prepare("SELECT soi.product_id, soi.quantity as needed, p.name, COALESCE(s.quantity, 0) as in_stock 
                           FROM sales_order_items soi
                           JOIN products p ON soi.product_id = p.id
                           LEFT JOIN stock s ON p.id = s.product_id
                           WHERE soi.order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $errors = [];
    foreach ($items as $item) {
        if ($item['needed'] > $item['in_stock']) {
            $errors[] = "{$item['name']} (Need {$item['needed']}, Have {$item['in_stock']})";
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

function updateProductStock($productId, $quantity, $type, $refType, $refId, $notes = '') {
    $pdo = sales_pdo();
    
    // Get Current Stock
    $stmt = $pdo->prepare("SELECT id, quantity FROM stock WHERE product_id = ?");
    $stmt->execute([$productId]);
    $stock = $stmt->fetch();
    
    $currentQty = $stock ? $stock['quantity'] : 0;
    $newQty = $currentQty;
    
    if ($type === 'subtract') {
        $newQty = $currentQty - $quantity;
        // Optional: Block if negative? User typically wants to force it.
    } elseif ($type === 'add') {
        $newQty = $currentQty + $quantity;
    }
    
    // Update or Insert
    if ($stock) {
        $pdo->prepare("UPDATE stock SET quantity = ?, last_updated = NOW() WHERE id = ?")->execute([$newQty, $stock['id']]);
    } else {
        $pdo->prepare("INSERT INTO stock (product_id, quantity, location, last_updated) VALUES (?, ?, 'Warehouse A', NOW())")->execute([$productId, $newQty]);
    }
    
    // Log Movement
    $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, reference_id, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$productId, ($type === 'add' ? 'in' : 'out'), $quantity, $refType, $refId, $notes]);
}

function deductStockForOrder($orderId) {
    $pdo = sales_pdo();
    $stmt = $pdo->prepare("SELECT * FROM sales_order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        updateProductStock($item['product_id'], $item['quantity'], 'subtract', 'sales_order', $orderId, "Order #$orderId Shipped");
    }
}

/**
 * Deduct stock for an order and return a structured result for UI feedback.
 *
 * @return array{
 *   attempted:bool,
 *   success:bool,
 *   message:string,
 *   items_processed:int,
 *   error?:string
 * }
 */
function sales_deduct_stock_for_order_result(int $orderId): array
{
    if ($orderId <= 0) {
        return [
            'attempted' => false,
            'success' => false,
            'message' => 'Invalid order for stock deduction.',
            'items_processed' => 0,
        ];
    }

    if (!function_exists('deductStockForOrder')) {
        return [
            'attempted' => false,
            'success' => false,
            'message' => 'Stock deduction is not available on this system.',
            'items_processed' => 0,
        ];
    }

    try {
        $pdo = sales_pdo();
        $stmt = $pdo->prepare('SELECT product_id, quantity FROM sales_order_items WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $lineCount = 0;
        foreach ($items as $item) {
            if ((int) ($item['product_id'] ?? 0) > 0 && (float) ($item['quantity'] ?? 0) > 0) {
                $lineCount++;
            }
        }

        if ($lineCount === 0) {
            return [
                'attempted' => true,
                'success' => true,
                'message' => 'No product lines required stock deduction.',
                'items_processed' => 0,
            ];
        }

        deductStockForOrder($orderId);

        return [
            'attempted' => true,
            'success' => true,
            'message' => 'Stock deducted successfully for ' . $lineCount . ' line(s).',
            'items_processed' => $lineCount,
        ];
    } catch (Throwable $e) {
        return [
            'attempted' => true,
            'success' => false,
            'message' => 'Stock was not deducted. ' . $e->getMessage(),
            'items_processed' => 0,
            'error' => $e->getMessage(),
        ];
    }
}

function restoreStockForOrder($orderId) {
    $pdo = sales_pdo();
    $stmt = $pdo->prepare("SELECT * FROM sales_order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        updateProductStock($item['product_id'], $item['quantity'], 'add', 'sales_order_cancel', $orderId, "Order #$orderId Cancelled");
    }
}

function ensureCustomerColumnsExist() {
    $pdo = sales_pdo();
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tin', $cols)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN tin VARCHAR(50) AFTER tax_number");
        }
        if (!in_array('vrn', $cols)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN vrn VARCHAR(50) AFTER tin");
        }
        if (!in_array('currency', $cols)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN currency VARCHAR(10) DEFAULT 'TZS' AFTER vrn");
        }

        // Also ensure sales_orders has shipped_at / order_type (Roadmaster spare vs truck)
        $soCols = $pdo->query("SHOW COLUMNS FROM sales_orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('shipped_at', $soCols, true)) {
            $pdo->exec("ALTER TABLE sales_orders ADD COLUMN shipped_at DATETIME NULL AFTER status");
            $soCols[] = 'shipped_at';
        }
        if (!in_array('order_type', $soCols, true)) {
            $after = in_array('status', $soCols, true) ? ' AFTER status' : '';
            $pdo->exec("ALTER TABLE sales_orders ADD COLUMN order_type VARCHAR(20) NOT NULL DEFAULT 'spare'{$after}");
        }
    } catch (Exception $e) {
        // Log Error or handle silently
    }
}

/**
 * Supported invoice / sales order currency codes for pickers.
 *
 * @return array<string, array{name:string, flag:string}>
 */
function sales_invoice_currency_options(): array
{
    return [
        'TZS' => ['name' => 'Tanzanian Shilling', 'flag' => 'tz'],
        'USD' => ['name' => 'US Dollar', 'flag' => 'us'],
        'EUR' => ['name' => 'Euro', 'flag' => 'eu'],
        'GBP' => ['name' => 'British Pound', 'flag' => 'gb'],
        'KES' => ['name' => 'Kenyan Shilling', 'flag' => 'ke'],
        'UGX' => ['name' => 'Ugandan Shilling', 'flag' => 'ug'],
        'RWF' => ['name' => 'Rwandan Franc', 'flag' => 'rw'],
        'ZAR' => ['name' => 'South African Rand', 'flag' => 'za'],
        'AED' => ['name' => 'UAE Dirham', 'flag' => 'ae'],
        'SAR' => ['name' => 'Saudi Riyal', 'flag' => 'sa'],
        'INR' => ['name' => 'Indian Rupee', 'flag' => 'in'],
        'CNY' => ['name' => 'Chinese Yuan', 'flag' => 'cn'],
        'JPY' => ['name' => 'Japanese Yen', 'flag' => 'jp'],
        'CHF' => ['name' => 'Swiss Franc', 'flag' => 'ch'],
        'CAD' => ['name' => 'Canadian Dollar', 'flag' => 'ca'],
        'AUD' => ['name' => 'Australian Dollar', 'flag' => 'au'],
        'NGN' => ['name' => 'Nigerian Naira', 'flag' => 'ng'],
    ];
}

/**
 * Default BOT mean rates (TZS per 1 unit) for invoice currency pickers.
 *
 * @param list<string> $codes
 * @return array<string, string>
 */
function sales_invoice_bot_exchange_rates(array $codes): array
{
    $rates = ['TZS' => '1.0000'];
    $options = sales_invoice_currency_options();
    $botFile = __DIR__ . '/../../includes/bot_exchange_rates.php';
    if (is_file($botFile)) {
        require_once $botFile;
    }

    foreach ($codes as $code) {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || $code === 'TZS' || isset($rates[$code]) || !isset($options[$code])) {
            continue;
        }
        if (!function_exists('bot_get_exchange_rate')) {
            continue;
        }
        $info = bot_get_exchange_rate($code);
        if (is_array($info) && (float) ($info['rate'] ?? 0) > 0) {
            $rates[$code] = number_format((float) $info['rate'], 4, '.', '');
        }
    }

    return $rates;
}

/**
 * Resolve order currency rates: saved JSON / exchange_rate column, then BOT defaults.
 *
 * @param array<string, mixed> $orderRow
 * @param list<string> $displayCurrencies
 * @return array<string, float>
 */
function sales_resolve_currency_rates(array $orderRow, array $displayCurrencies): array
{
    $rates = ['TZS' => 1.0];
    $options = sales_invoice_currency_options();

    if (!empty($orderRow['currency_rates'])) {
        $decoded = json_decode((string) $orderRow['currency_rates'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $rateCode => $rateValue) {
                $rateCode = strtoupper(trim((string) $rateCode));
                if ($rateCode !== '' && isset($options[$rateCode])) {
                    $rates[$rateCode] = max(0.0, (float) $rateValue);
                }
            }
        }
    }

    $billing = strtoupper(trim((string) ($orderRow['currency'] ?? '')));
    $storedExchangeRate = (float) ($orderRow['exchange_rate'] ?? 0);
    if ($billing !== '' && $billing !== 'TZS' && $storedExchangeRate > 1.0) {
        if (empty($rates[$billing]) || (float) $rates[$billing] <= 1.01) {
            $rates[$billing] = $storedExchangeRate;
        }
    }

    $codes = $displayCurrencies;
    if ($codes === []) {
        $codes = array_values(array_filter([$billing, 'USD', 'TZS']));
    }

    $botFile = __DIR__ . '/../../includes/bot_exchange_rates.php';
    if (is_file($botFile)) {
        require_once $botFile;
        if (function_exists('bot_get_exchange_rate')) {
            foreach ($codes as $rateCode) {
                $rateCode = strtoupper(trim((string) $rateCode));
                if ($rateCode === 'TZS' || !isset($options[$rateCode])) {
                    continue;
                }
                if (!empty($rates[$rateCode]) && (float) $rates[$rateCode] > 0) {
                    continue;
                }
                $rateInfo = bot_get_exchange_rate($rateCode);
                if (is_array($rateInfo) && (float) ($rateInfo['rate'] ?? 0) > 0) {
                    $rates[$rateCode] = (float) $rateInfo['rate'];
                }
            }
        }
    }

    $rates['TZS'] = 1.0;

    $needsUsd = in_array('USD', $displayCurrencies, true)
        || in_array('USD', $codes, true)
        || strtoupper(trim((string) ($orderRow['currency'] ?? ''))) === 'TZS';
    if ($needsUsd && ((float) ($rates['USD'] ?? 0) <= 1.01)) {
        $botRates = sales_invoice_bot_exchange_rates(['USD']);
        if ((float) ($botRates['USD'] ?? 0) > 1.01) {
            $rates['USD'] = (float) $botRates['USD'];
        }
    }

    return $rates;
}

/**
 * Truck invoices/quotes are Roadmaster-only; Ultimate (PPE/safety) uses standard invoices.
 */
function salesSupportsTruckInvoices(): bool
{
    return function_exists('isRoadmaster') && isRoadmaster();
}

/**
 * Normalize order/invoice type: Ultimate and other tenants always use spare (standard).
 */
function salesNormalizeOrderType(?string $orderType): string
{
    if (!salesSupportsTruckInvoices()) {
        return 'spare';
    }

    return strtolower(trim((string) ($orderType ?? 'spare'))) === 'truck' ? 'truck' : 'spare';
}

/**
 * Invoice create uses the React desk shell (Roadmaster, or Ultimate standard).
 */
function salesInvoiceCreateUsesReactShell(?string $predefinedType = null): bool
{
    if (function_exists('isUltimate') && isUltimate()) {
        return true;
    }

    return salesSupportsTruckInvoices();
}

/**
 * Quotation create uses the React desk shell (Roadmaster, or Ultimate standard).
 */
function salesQuoteCreateUsesReactShell(): bool
{
    if (function_exists('isUltimate') && isUltimate()) {
        return true;
    }

    return salesSupportsTruckInvoices();
}

/**
 * Quotation list statuses that may be bulk-deleted (no linked invoice).
 *
 * @return list<string>
 */
function sales_quotation_deletable_statuses(): array
{
    return array('quotation', 'draft', 'cancelled', 'canceled');
}

function sales_quotation_status_is_deletable(?string $status): bool
{
    return in_array(strtolower(trim((string) $status)), sales_quotation_deletable_statuses(), true);
}

/**
 * Quotations list uses the React desk shell (Roadmaster, or Ultimate).
 */
function salesQuotationsListUsesReactShell(): bool
{
    if (function_exists('isUltimate') && isUltimate()) {
        return true;
    }

    return salesSupportsTruckInvoices();
}

/**
 * Sales orders index uses the React desk shell (Roadmaster, or Ultimate).
 */
function salesOrdersListUsesReactShell(): bool
{
    return salesQuotationsListUsesReactShell();
}

/**
 * Sales order view uses the React shell (Roadmaster, or Ultimate).
 */
function salesOrderViewUsesReactShell(): bool
{
    return salesQuotationsListUsesReactShell();
}

/**
 * Invoices index uses the React desk shell (Roadmaster, or Ultimate).
 */
function salesInvoicesListUsesReactShell(): bool
{
    return salesQuotationsListUsesReactShell();
}

/**
 * Sales invoice view uses the React shell (Roadmaster, or Ultimate).
 */
function salesInvoiceViewUsesReactShell(): bool
{
    return salesQuotationsListUsesReactShell();
}

/**
 * Page title for the quotation create form.
 */
function salesQuoteCreatePageTitle(?string $orderType = null): string
{
    if (function_exists('isUltimate') && isUltimate()) {
        return 'Create Quotation';
    }

    $type = strtolower(trim((string) ($orderType ?? 'spare')));
    if ($type === 'truck') {
        return 'Create Truck Quotation';
    }

    return 'Create Spare Quotation';
}

/**
 * Page title for the invoice create form.
 */
function salesInvoiceCreatePageTitle(?string $orderType = null): string
{
    if (function_exists('isUltimate') && isUltimate()) {
        return 'Create Invoice';
    }

    $type = strtolower(trim((string) ($orderType ?? 'spare')));
    if ($type === 'truck') {
        return 'Create Truck Invoice';
    }

    return 'Create Spare Invoice';
}

/**
 * Branding lines for printed sales documents (Roadmaster vs Ultimate).
 *
 * @param array<string, mixed> $companySettings
 * @return array{brand:string,main:string,sub:string,tagline:string,navy:string,accent:string,grayscale_watermark:bool}
 */
function sales_document_brand_profile(array $companySettings = [], ?string $brand = null): array
{
    if ($brand === null || $brand === '') {
        if (function_exists('isRoadmaster') && isRoadmaster()) {
            $brand = 'roadmaster';
        } elseif (function_exists('isUltimate') && isUltimate()) {
            $brand = 'ultimate';
        } else {
            $brand = 'generic';
        }
    }

    if ($brand === 'roadmaster') {
        return [
            'brand' => 'roadmaster',
            'main' => 'ROADMASTER',
            'sub' => 'SPARES LIMITED',
            'tagline' => 'YOUR TRUCK SPARES PARTNER',
            'navy' => '#0D2A4A',
            'accent' => '#000000',
            'grayscale_watermark' => true,
        ];
    }

    $name = trim((string) ($companySettings['company_name'] ?? ''));
    if ($name === '' && defined('COMPANY_NAME')) {
        $name = trim((string) COMPANY_NAME);
    }
    if ($name === '') {
        $name = 'Ultimate General Trading';
    }

    $main = $name;
    $sub = '';
    if (preg_match('/^(.+?)\s+(COMPANY|CO\.|LTD\.?|LIMITED|TRADING COMPANY|GENERAL TRADING(?:\s+COMPANY)?)\s*$/i', $name, $m)) {
        $main = trim($m[1]);
        $sub = strtoupper(trim($m[2]));
    }

    return [
        'brand' => $brand,
        'main' => strtoupper($main),
        'sub' => strtoupper($sub),
        'tagline' => 'PARTS THAT LAST, BACKED BY MASTERS',
        'navy' => '#008784',
        'accent' => '#008784',
        'grayscale_watermark' => false,
    ];
}

/**
 * Resolved font key for sales documents (quotations, invoices, delivery notes).
 *
 * @param array<string, mixed> $companySettings
 */
function sales_document_font_key(array $companySettings = []): string
{
    $catalog = function_exists('getSystemFontCatalog') ? getSystemFontCatalog() : [];
    $default = 'arima';
    $key = strtolower(trim((string) ($companySettings['sales_document_font'] ?? '')));
    if ($key !== '' && isset($catalog[$key])) {
        return $key;
    }

    return $default;
}

/**
 * @param array<string, mixed> $companySettings
 * @return array{label?:string,stack?:string,google?:string,local_css?:string}
 */
function sales_document_font_definition(array $companySettings = []): array
{
    $catalog = function_exists('getSystemFontCatalog') ? getSystemFontCatalog() : [];
    $key = sales_document_font_key($companySettings);

    return $catalog[$key] ?? $catalog['arima'] ?? $catalog['poppins'] ?? ['stack' => "'Arima', Arial, sans-serif"];
}

/**
 * @param array<string, mixed> $companySettings
 */
function sales_document_font_family_css(array $companySettings = []): string
{
    $def = sales_document_font_definition($companySettings);

    return (string) ($def['stack'] ?? "'Arima', Arial, sans-serif");
}

/**
 * Stylesheet link tags for the active sales document font.
 *
 * @param array<string, mixed> $companySettings
 */
function sales_document_font_stylesheet_links(array $companySettings = []): string
{
    $def = sales_document_font_definition($companySettings);
    $html = '';

    if (!empty($def['local_css'])) {
        $url = function_exists('app_url') ? app_url($def['local_css']) : $def['local_css'];
        $html .= '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n    ";
    }
    if (!empty($def['google'])) {
        $html .= '<link rel="stylesheet" href="' . htmlspecialchars($def['google'], ENT_QUOTES, 'UTF-8') . '">' . "\n    ";
    }

    return $html;
}

/**
 * @import rules for inline document stylesheets.
 *
 * @param array<string, mixed> $companySettings
 */
function sales_document_font_import_css(array $companySettings = []): string
{
    $def = sales_document_font_definition($companySettings);
    $css = '';

    if (!empty($def['local_css']) && function_exists('app_url')) {
        $css .= '@import url(' . json_encode(app_url($def['local_css']), JSON_UNESCAPED_SLASHES) . ");\n    ";
    }
    if (!empty($def['google'])) {
        $css .= '@import url(' . json_encode($def['google'], JSON_UNESCAPED_SLASHES) . ");\n    ";
    }

    return $css;
}

/**
 * Footer sections from Sales Settings → Document settings (Ultimate spare_* fields).
 *
 * @param array<string, mixed> $companySettings
 * @return list<array{title: string, body: string}>
 */
function sales_document_settings_second_page_sections(array $companySettings = []): array
{
    $sections = [];
    $map = [
        'spare_payment_details' => 'Payment instructions',
        'spare_terms' => 'Terms & conditions',
        'spare_validity' => 'Document validity',
        'invoice_remarks' => 'Remarks',
        'spare_return_policy' => 'Return policy',
        'spare_thanks_note' => 'Closing note',
    ];

    foreach ($map as $key => $title) {
        $body = trim((string) ($companySettings[$key] ?? ''));
        if ($body !== '') {
            $sections[] = ['title' => $title, 'body' => $body];
        }
    }

    return $sections;
}

function sales_document_has_settings_second_page(array $companySettings = []): bool
{
    return sales_document_settings_second_page_sections($companySettings) !== [];
}

/**
 * Resolve branded document layout inner path for Roadmaster truck/spare documents.
 * Ultimate and other tenants use view_sheet_inner.php in their view pages instead.
 */
function sales_branded_document_layout_inner_path(bool $isTruck): ?string
{
    if (!function_exists('isRoadmaster') || !isRoadmaster()) {
        return null;
    }

    $file = $isTruck ? 'truck-invoice-layout-inner.php' : 'spare-invoice-layout-inner.php';
    $path = __DIR__ . '/layouts/roadmaster/' . $file;

    return is_file($path) ? $path : null;
}

/**
 * Classic document sheet used by Ultimate invoices, orders, and quotations.
 */
function sales_standard_document_view_inner_path(string $documentKind = 'invoice'): ?string
{
    $documentKind = strtolower(trim($documentKind));
    $paths = [
        'invoice' => __DIR__ . '/invoices/view_sheet_inner.php',
        'order' => __DIR__ . '/orders/view_sheet_inner.php',
        'quotation' => __DIR__ . '/view_sheet_inner.php',
    ];
    $path = $paths[$documentKind] ?? $paths['invoice'];

    return is_file($path) ? $path : null;
}

/**
 * Title on order/quotation print sheets (Quotation, Sales Order, or Invoice).
 */
function sales_order_document_title_label($status, bool $hasLinkedInvoice = false): string
{
    $st = strtolower(trim((string) $status));
    if (in_array($st, ['draft', 'quotation', 'sent'], true)) {
        return 'Quotation';
    }
    if ($hasLinkedInvoice || in_array($st, ['invoiced', 'paid'], true)) {
        return 'Invoice';
    }

    return 'Sales Order';
}

/**
 * Order display currencies with billing currency first.
 *
 * @param list<string> $currencies
 */
function sales_order_display_currencies_ordered(array $currencies, string $billingCurrency): array
{
    $billingCurrency = strtoupper(trim($billingCurrency));
    $ordered = [];
    if ($billingCurrency !== '' && in_array($billingCurrency, $currencies, true)) {
        $ordered[] = $billingCurrency;
    }
    foreach ($currencies as $code) {
        $code = strtoupper(trim((string) $code));
        if ($code !== '' && $code !== $billingCurrency && !in_array($code, $ordered, true)) {
            $ordered[] = $code;
        }
    }

    return $ordered;
}

/**
 * Build dual-currency display context for Roadmaster invoice / quotation views.
 *
 * @param array<string, mixed> $invoice
 * @param list<array<string, mixed>> $items
 * @param bool $forceUsdTzs Legacy fallback: include USD + TZS when no saved display currencies.
 * @return array{
 *   display_currencies:list<string>,
 *   amount_currency:string,
 *   rates:array<string, float>,
 *   render:callable(float):string
 * }
 */
function sales_invoice_dual_currency_context(array $invoice, array $items, bool $forceUsdTzs = false): array
{
    $orderRow = [
        'currency' => $invoice['currency'] ?? $invoice['order_currency'] ?? '',
        'exchange_rate' => $invoice['exchange_rate'] ?? $invoice['order_exchange_rate'] ?? 0,
        'display_currencies' => $invoice['display_currencies'] ?? $invoice['order_display_currencies'] ?? '',
        'currency_rates' => $invoice['currency_rates'] ?? $invoice['order_currency_rates'] ?? '',
        'subtotal' => $invoice['subtotal'] ?? 0,
        'tax_amount' => $invoice['tax_amount'] ?? 0,
        'total_amount' => $invoice['total_amount'] ?? 0,
    ];

    $displayCurrencies = [];
    if (!empty($orderRow['display_currencies'])) {
        $decoded = json_decode((string) $orderRow['display_currencies'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $code) {
                $code = strtoupper(trim((string) $code));
                if ($code !== '' && !in_array($code, $displayCurrencies, true)) {
                    $displayCurrencies[] = $code;
                }
            }
        }
    }

    $docCurrency = strtoupper(trim((string) ($orderRow['currency'] ?? '')));
    if ($docCurrency === '') {
        $docCurrency = 'TZS';
    }

    $hasSavedMultiCurrency = !empty($orderRow['display_currencies'])
        && is_array(json_decode((string) $orderRow['display_currencies'], true));

    $amountCurrency = $docCurrency;
    $hasStoredRatesJson = !empty($orderRow['currency_rates']);
    if (!$hasSavedMultiCurrency && $docCurrency !== 'TZS') {
        $maxLineAmount = 0.0;
        foreach ($items as $lineItem) {
            $maxLineAmount = max(
                $maxLineAmount,
                (float) ($lineItem['line_total'] ?? 0),
                (float) ($lineItem['unit_price'] ?? 0)
            );
        }
        $legacyStoredRate = (float) ($orderRow['exchange_rate'] ?? 0);
        $ratesLookLegacy = !$hasStoredRatesJson && $legacyStoredRate <= 1.01;
        if ($maxLineAmount >= 100000 && $ratesLookLegacy) {
            $amountCurrency = 'TZS';
        }
    }

    if ($displayCurrencies === []) {
        foreach ([$docCurrency, $amountCurrency, 'USD', 'TZS'] as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code !== '' && !in_array($code, $displayCurrencies, true)) {
                $displayCurrencies[] = $code;
            }
        }
    }

    if ($forceUsdTzs && !$hasSavedMultiCurrency) {
        $displayCurrencies = array_values(array_unique(array_merge(['USD', 'TZS'], $displayCurrencies)));
    } elseif (!$hasSavedMultiCurrency && !in_array('USD', $displayCurrencies, true) && ($docCurrency === 'USD' || $amountCurrency === 'TZS')) {
        array_splice($displayCurrencies, 1, 0, ['USD']);
        $displayCurrencies = array_values(array_unique($displayCurrencies));
    }

    $displayCurrencies = sales_order_display_currencies_ordered($displayCurrencies, $docCurrency);

    $rates = sales_resolve_currency_rates($orderRow, $displayCurrencies);
    if (in_array('USD', $displayCurrencies, true) && (float) ($rates['USD'] ?? 0) <= 1.01) {
        if ($docCurrency === 'USD' && (float) ($orderRow['exchange_rate'] ?? 0) > 1.01) {
            $rates['USD'] = (float) $orderRow['exchange_rate'];
        }
        if ((float) ($rates['USD'] ?? 0) <= 1.01 && !empty($orderRow['currency_rates'])) {
            $decodedRates = json_decode((string) $orderRow['currency_rates'], true);
            if (is_array($decodedRates) && (float) ($decodedRates['USD'] ?? 0) > 1.01) {
                $rates['USD'] = (float) $decodedRates['USD'];
            }
        }
        if ((float) ($rates['USD'] ?? 0) <= 1.01 && function_exists('sales_invoice_bot_exchange_rates')) {
            $botUsdRates = sales_invoice_bot_exchange_rates(['USD']);
            if ((float) ($botUsdRates['USD'] ?? 0) > 1.01) {
                $rates['USD'] = (float) $botUsdRates['USD'];
            }
        }
    }

    $amountToTzs = static function (float $amount, string $fromCurrency) use ($rates): float {
        $fromCurrency = strtoupper(trim($fromCurrency));
        if ($fromCurrency === 'TZS') {
            return $amount;
        }
        $rate = (float) ($rates[$fromCurrency] ?? 0.0);

        return $rate > 0 ? $amount * $rate : $amount;
    };

    $amountFromTzs = static function (float $tzsAmount, string $toCurrency) use ($rates): float {
        $toCurrency = strtoupper(trim($toCurrency));
        if ($toCurrency === 'TZS') {
            return $tzsAmount;
        }
        $rate = (float) ($rates[$toCurrency] ?? 0.0);

        return $rate > 0 ? $tzsAmount / $rate : $tzsAmount;
    };

    $convertAmount = static function (float $amount, string $fromCurrency, string $toCurrency) use ($amountToTzs, $amountFromTzs): float {
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        return $amountFromTzs($amountToTzs($amount, $fromCurrency), $toCurrency);
    };

    $render = static function (float $amount) use ($amountCurrency, $displayCurrencies, $convertAmount): string {
        $lines = [];
        foreach ($displayCurrencies as $displayCode) {
            $converted = $convertAmount($amount, $amountCurrency, $displayCode);
            $lines[] = '<div class="truck-dual-price-line">' . htmlspecialchars($displayCode) . ' ' . number_format($converted, 2) . '</div>';
        }

        return '<div class="truck-dual-price">' . implode('', $lines) . '</div>';
    };

    return [
        'display_currencies' => $displayCurrencies,
        'amount_currency' => $amountCurrency,
        'rates' => $rates,
        'render' => $render,
    ];
}

/**
 * Ensure sales_orders can store multiple display currencies and BOT rates.
 */
function ensureSalesOrderMultiCurrencyColumns(): void
{
    $pdo = sales_pdo();
    try {
        $soCols = $pdo->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('display_currencies', $soCols, true)) {
            $after = in_array('currency', $soCols, true) ? ' AFTER currency' : '';
            $pdo->exec("ALTER TABLE sales_orders ADD COLUMN display_currencies TEXT NULL{$after}");
            $soCols[] = 'display_currencies';
        }
        if (!in_array('currency_rates', $soCols, true)) {
            $after = in_array('display_currencies', $soCols, true) ? ' AFTER display_currencies' : '';
            $pdo->exec("ALTER TABLE sales_orders ADD COLUMN currency_rates TEXT NULL{$after}");
        }
    } catch (Throwable $e) {
        error_log('ensureSalesOrderMultiCurrencyColumns: ' . $e->getMessage());
    }
}

function getSalesLeaderboard($limit = null) {
    $pdo = sales_pdo();
    $sql = "
        SELECT u.id, u.username, u.profile_photo, SUM(i.total_amount) as total_sold
        FROM invoices i
        INNER JOIN users u ON i.created_by = u.id
        WHERE i.created_by IS NOT NULL
          AND i.status != 'cancelled'";
    $params = [];
    salesAppendCompanyScope($sql, $params, 'invoices', 'i');
    $sql .= "
        GROUP BY u.id, u.username, u.profile_photo
        HAVING COALESCE(SUM(i.total_amount), 0) > 0
        ORDER BY total_sold DESC";
    if ($limit !== null) {
        $sql .= " LIMIT " . intval($limit);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ensureSalesTargetsSchema() {
    $pdo = sales_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_targets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        period VARCHAR(10) NOT NULL COMMENT 'YYYY-MM or YYYY',
        target_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_target (user_id, period)
    )");
}

/** Commission ledger (optional; dashboard and reports SUM this table). */
function ensureSalesCommissionsSchema() {
    $pdo = sales_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_commissions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `sales_rep_id` INT NOT NULL,
        `commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_sales_commissions_rep_date` (`sales_rep_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function getYearlyCommission($user_id, $year) {
    $pdo = sales_pdo();
    ensureSalesCommissionsSchema();
    $stmt = $pdo->prepare("
        SELECT SUM(commission_amount) 
        FROM sales_commissions 
        WHERE sales_rep_id = ? 
        AND DATE_FORMAT(created_at, '%Y') = ?
    ");
    $stmt->execute([$user_id, $year]);
    return $stmt->fetchColumn() ?: 0;
}

function getYearlySalesTotal($user_id, $year) {
    $pdo = sales_pdo();
    $stmt = $pdo->prepare("
        SELECT SUM(total_amount) 
        FROM invoices 
        WHERE created_by = ? 
        AND DATE_FORMAT(created_at, '%Y') = ? 
        AND status != 'cancelled'
    ");
    $stmt->execute([$user_id, $year]);
    return $stmt->fetchColumn() ?: 0;
}

function getGlobalYearlyTarget($year) {
    $pdo = sales_pdo();
    ensureSalesTargetsSchema();
    // First check for company-wide yearly target (user_id = 0)
    $stmt = $pdo->prepare("SELECT target_amount FROM sales_targets WHERE user_id = 0 AND period = ?");
    $stmt->execute([$year]);
    $companyTarget = $stmt->fetchColumn();
    
    if ($companyTarget !== false && $companyTarget > 0) {
        return $companyTarget;
    }
    
    // Fallback: Sum up all monthly targets for the year (e.g. '2025-01', '2025-02')
    $stmt = $pdo->prepare("SELECT SUM(target_amount) FROM sales_targets WHERE period LIKE ? AND user_id != 0");
    $stmt->execute([$year . '%']);
    return $stmt->fetchColumn() ?: 0.00;
}

function getGlobalSalesTotal($month) {
    $pdo = sales_pdo();
    $sql = "SELECT SUM(total_amount) FROM invoices WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status != 'cancelled'";
    $params = [$month];
    salesAppendCompanyScope($sql, $params, 'invoices');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() ?: 0;
}

function getUserMonthlySales($user_id, $month) {
    $pdo = sales_pdo();
    $sql = "
        SELECT SUM(total_amount) 
        FROM invoices 
        WHERE created_by = ? 
        AND DATE_FORMAT(created_at, '%Y-%m') = ? 
        AND status != 'cancelled'";
    $params = [$user_id, $month];
    salesAppendCompanyScope($sql, $params, 'invoices');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() ?: 0;
}

function getGlobalPendingOrders() {
    $pdo = sales_pdo();
    $sql = "SELECT COUNT(*) FROM sales_orders WHERE status IN ('draft', 'quotation')";
    $params = [];
    salesAppendCompanyScope($sql, $params, 'sales_orders');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() ?: 0;
}

function getGlobalOverdueInvoices() {
    $pdo = sales_pdo();
    $sql = "SELECT COUNT(*) FROM invoices WHERE status = 'overdue'";
    $params = [];
    salesAppendCompanyScope($sql, $params, 'invoices');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() ?: 0;
}

function getGlobalCommissionEarned($month) {
    $pdo = sales_pdo();
    ensureSalesCommissionsSchema();
    $stmt = $pdo->prepare("
        SELECT SUM(commission_amount) 
        FROM sales_commissions 
        WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
    ");
    $stmt->execute([$month]);
    return $stmt->fetchColumn() ?: 0;
}

function getAllRecentQuotes($limit = 5) {
    $pdo = sales_pdo();
    $sql = "
        SELECT so.*, c.company_name as customer_name, u.username as created_by_name
        FROM sales_orders so
        JOIN customers c ON so.customer_id = c.id
        LEFT JOIN users u ON so.created_by = u.id
        WHERE 1=1";
    $params = [];
    salesAppendCompanyScope($sql, $params, 'sales_orders', 'so');
    $sql .= " ORDER BY so.created_at DESC LIMIT " . intval($limit);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRecentInvoices($limit = 5) {
    $pdo = sales_pdo();
    $sql = "
        SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.status, c.company_name as customer_name, u.full_name as salesperson
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        LEFT JOIN sales_orders so ON i.order_id = so.id
        LEFT JOIN users u ON COALESCE(i.created_by, so.created_by) = u.id
        WHERE i.status != 'cancelled'";
    $params = [];
    salesAppendCompanyScope($sql, $params, 'invoices', 'i');
    $sql .= " ORDER BY i.created_at DESC LIMIT " . intval($limit);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getGlobalYearlySales($year) {
    $pdo = sales_pdo();
    $sql = "SELECT SUM(total_amount) FROM invoices WHERE DATE_FORMAT(created_at, '%Y') = ? AND status != 'cancelled'";
    $params = [$year];
    salesAppendCompanyScope($sql, $params, 'invoices');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() ?: 0.00;
}

/**
 * Get most outgoing products by how often they go out (distinct orders),
 * not by total quantity sold.
 * Uses sales_order_items from confirmed/invoiced/shipped/paid/delivered orders.
 *
 * @param int $limit Max number of products to return
 * @param int $days Look back period in days (default 30)
 * @param string|null $category 'truck', 'spare', or null/all for every product
 * @return array<int, array<string, mixed>>
 */
function getMostOutgoingProducts($limit = 5, $days = 30, $category = null)
{
    $pdo = sales_pdo();
    $limit = max(1, (int) $limit);
    $days = max(1, (int) $days);
    $category = $category !== null ? strtolower(trim((string) $category)) : 'all';
    if (!in_array($category, ['all', 'truck', 'spare'], true)) {
        $category = 'all';
    }

    $soCols = [];
    $soiCols = [];
    $prodCols = [];
    $invCols = [];
    try {
        $soCols = $pdo->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $soiCols = $pdo->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $prodCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (sales_connection_has_table($pdo, 'invoices')) {
            $invCols = $pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
    } catch (Throwable $e) {
        return [];
    }

    $truckLineParts = [];
    if (in_array('item_type', $prodCols, true)) {
        $truckLineParts[] = "LOWER(TRIM(COALESCE(p.item_type, ''))) IN ('vehicle', 'truck')";
    }
    if (in_array('truck_type', $prodCols, true)) {
        $truckLineParts[] = "TRIM(COALESCE(p.truck_type, '')) != ''";
    }
    if (in_array('order_type', $soCols, true)) {
        $truckLineParts[] = "LOWER(TRIM(COALESCE(so.order_type, ''))) = 'truck'";
    }

    $categoryFilter = '';
    if ($category === 'truck' && $truckLineParts !== []) {
        $categoryFilter = ' AND (' . implode(' OR ', $truckLineParts) . ')';
    } elseif ($category === 'spare' && $truckLineParts !== []) {
        $categoryFilter = ' AND NOT (' . implode(' OR ', $truckLineParts) . ')';
    }

    $scopeInv = in_array('company_id', $invCols, true)
        ? salesCompanyScopeSql('invoices', 'i')
        : ['', []];
    $scopeSo = in_array('company_id', $soCols, true)
        ? salesCompanyScopeSql('sales_orders', 'so')
        : ['', []];

    $scopeAttempts = [];
    if ($scopeInv[0] !== '') {
        $scopeAttempts[] = $scopeInv;
    }
    if ($scopeSo[0] !== '' && $scopeSo[0] !== $scopeInv[0]) {
        $scopeAttempts[] = $scopeSo;
    }
    $scopeAttempts[] = ['', []];

    $hasInvoices = $invCols !== [] && sales_connection_has_table($pdo, 'invoices');
    $dateExpr = $hasInvoices
        ? 'COALESCE(i.invoice_date, i.created_at, so.created_at)'
        : 'so.created_at';
    $lineTextCols = [];
    if (in_array('description', $soiCols, true)) {
        $lineTextCols[] = 'soi.description';
    }
    if (in_array('notes', $soiCols, true)) {
        $lineTextCols[] = 'soi.notes';
    }
    $lineTextExpr = $lineTextCols !== []
        ? 'TRIM(COALESCE(' . implode(', ', $lineTextCols) . ", ''))"
        : "''";
    $lineNameExpr = "COALESCE(NULLIF(TRIM(COALESCE(p.name, '')), ''), NULLIF({$lineTextExpr}, ''), 'Item')";
    $lineKeyExpr = 'COALESCE(NULLIF(soi.product_id, 0), soi.id)';
    $lineFilter = '(soi.product_id IS NOT NULL AND soi.product_id > 0)';
    if ($lineTextCols !== []) {
        $lineFilter = "(($lineFilter) OR {$lineTextExpr} != '')";
    }

    foreach ($scopeAttempts as $scope) {
        $scopeSql = $scope[0];
        $scopeParams = $scope[1];

        if ($hasInvoices) {
            $sql = "SELECT {$lineKeyExpr} AS product_id,
                    {$lineNameExpr} AS product_name,
                    COALESCE(p.main_image, p.image) AS main_image,
                    COUNT(DISTINCT so.id) AS outgoing_count,
                    COALESCE(SUM(soi.quantity), 0) AS total_qty,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(DISTINCT c.company_name ORDER BY so.id DESC SEPARATOR '||'),
                        '||',
                        1
                    ) AS top_customer_name
                FROM sales_order_items soi
                JOIN sales_orders so ON so.id = soi.order_id
                INNER JOIN invoices i ON i.order_id = so.id AND i.status NOT IN ('cancelled')
                JOIN customers c ON c.id = COALESCE(i.customer_id, so.customer_id)
                LEFT JOIN products p ON p.id = soi.product_id AND soi.product_id > 0
                WHERE {$lineFilter}
                  AND DATE({$dateExpr}) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  {$scopeSql}
                  {$categoryFilter}
                GROUP BY {$lineKeyExpr}, {$lineNameExpr}, COALESCE(p.main_image, p.image)
                ORDER BY outgoing_count DESC, total_qty DESC
                LIMIT {$limit}";
            $params = array_merge($scopeParams, [$days]);
        } else {
            $sql = "SELECT {$lineKeyExpr} AS product_id,
                    {$lineNameExpr} AS product_name,
                    COALESCE(p.main_image, p.image) AS main_image,
                    COUNT(DISTINCT so.id) AS outgoing_count,
                    COALESCE(SUM(soi.quantity), 0) AS total_qty,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(DISTINCT c.company_name ORDER BY so.id DESC SEPARATOR '||'),
                        '||',
                        1
                    ) AS top_customer_name
                FROM sales_order_items soi
                JOIN sales_orders so ON so.id = soi.order_id
                JOIN customers c ON c.id = so.customer_id
                LEFT JOIN products p ON p.id = soi.product_id AND soi.product_id > 0
                WHERE {$lineFilter}
                  AND so.status IN ('confirmed','invoiced','shipped','paid','delivered')
                  AND DATE(so.created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  {$scopeSql}
                  {$categoryFilter}
                GROUP BY {$lineKeyExpr}, {$lineNameExpr}, COALESCE(p.main_image, p.image)
                ORDER BY outgoing_count DESC, total_qty DESC
                LIMIT {$limit}";
            $params = array_merge($scopeParams, [$days]);
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows !== []) {
                return $rows;
            }
        } catch (Throwable $e) {
            error_log('getMostOutgoingProducts: ' . $e->getMessage());
        }
    }

    return [];
}

function getGlobalTopCustomers($month, $limit = 3) {
    $pdo = sales_pdo();
    $sql = "
        SELECT c.id, c.company_name, c.contact_person, 
               SUM(i.total_amount) as total_purchases
        FROM customers c
        JOIN invoices i ON c.id = i.customer_id
        WHERE DATE_FORMAT(i.created_at, '%Y-%m') = ?
        AND i.status != 'cancelled'";
    $params = [$month];
    salesAppendCompanyScope($sql, $params, 'invoices', 'i');
    $sql .= " GROUP BY c.id ORDER BY total_purchases DESC LIMIT " . intval($limit);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSalesFunnelStats($month) {
    $pdo = sales_pdo();
    $whereSo = "WHERE DATE_FORMAT(created_at, '%Y-%m') = ?";
    $paramsSo = [$month];
    salesAppendCompanyScope($whereSo, $paramsSo, 'sales_orders');

    $whereInv = "WHERE DATE_FORMAT(created_at, '%Y-%m') = ?";
    $paramsInv = [$month];
    salesAppendCompanyScope($whereInv, $paramsInv, 'invoices');

    // Drafts
    $stmtDraft = $pdo->prepare("SELECT COUNT(*) FROM sales_orders $whereSo AND status = 'draft'");
    $stmtDraft->execute($paramsSo);
    $drafts = $stmtDraft->fetchColumn();

    // Quotations
    $stmtQuote = $pdo->prepare("SELECT COUNT(*) FROM sales_orders $whereSo AND status = 'quotation'");
    $stmtQuote->execute($paramsSo);
    $quotes = $stmtQuote->fetchColumn();

    // Confirmed
    $stmtConfirmed = $pdo->prepare("SELECT COUNT(*) FROM sales_orders $whereSo AND status = 'confirmed'");
    $stmtConfirmed->execute($paramsSo);
    $confirmed = $stmtConfirmed->fetchColumn();

    // Invoiced (from invoices table)
    $stmtInvoiced = $pdo->prepare("SELECT COUNT(*) FROM invoices $whereInv AND status != 'cancelled'");
    $stmtInvoiced->execute($paramsInv);
    $invoiced = $stmtInvoiced->fetchColumn();

    return [
        'drafts' => $drafts ?: 0,
        'quotes' => $quotes ?: 0,
        'confirmed' => $confirmed ?: 0,
        'invoiced' => $invoiced ?: 0
    ];
}

/**
 * Revenue totals grouped by calendar day for dashboard charts.
 *
 * @return array<string, float> Y-m-d => amount
 */
function salesRevenueTotalsByDay(DateTimeInterface $start, DateTimeInterface $end): array
{
    $pdo = sales_pdo();
    $sql = "
        SELECT DATE(created_at) AS sale_day, SUM(total_amount) AS total
        FROM invoices
        WHERE status != 'cancelled'
          AND created_at >= ?
          AND created_at < ?
    ";
    $params = [
        $start->format('Y-m-d 00:00:00'),
        $end->format('Y-m-d 00:00:00'),
    ];
    salesAppendCompanyScope($sql, $params, 'invoices');
    $sql .= ' GROUP BY DATE(created_at)';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $day = (string) ($row['sale_day'] ?? '');
        if ($day !== '') {
            $out[$day] = (float) ($row['total'] ?? 0);
        }
    }

    return $out;
}

/**
 * Quotation totals grouped by calendar day for dashboard charts.
 *
 * @return array<string, float> Y-m-d => amount
 */
function salesQuoteTotalsByDay(DateTimeInterface $start, DateTimeInterface $end): array
{
    $pdo = sales_pdo();
    $sql = "
        SELECT DATE(created_at) AS quote_day, SUM(total_amount) AS total
        FROM sales_orders
        WHERE status IN ('draft', 'quotation')
          AND created_at >= ?
          AND created_at < ?
    ";
    $params = [
        $start->format('Y-m-d 00:00:00'),
        $end->format('Y-m-d 00:00:00'),
    ];
    salesAppendCompanyScope($sql, $params, 'sales_orders');
    $sql .= ' GROUP BY DATE(created_at)';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $day = (string) ($row['quote_day'] ?? '');
        if ($day !== '') {
            $out[$day] = (float) ($row['total'] ?? 0);
        }
    }

    return $out;
}

/**
 * @param callable(DateTimeInterface, DateTimeInterface): array<string, float> $totalsFn
 * @return array{day:list<array{label:string,value:float}>,weekly:list<array{label:string,value:float}>,monthly:list<array{label:string,value:float}>}
 */
function salesBuildGrowthSeries(callable $totalsFn, callable $monthlyTotalsFn): array
{
    $today = new DateTimeImmutable('today');

    $dayStart = $today->modify('-6 days');
    $dayEnd = $today->modify('+1 day');
    $dayTotals = $totalsFn($dayStart, $dayEnd);
    $daySeries = [];
    for ($i = 0; $i < 7; $i++) {
        $date = $dayStart->modify("+{$i} days");
        $key = $date->format('Y-m-d');
        $daySeries[] = [
            'label' => $date->format('D'),
            'value' => (float) ($dayTotals[$key] ?? 0),
        ];
    }

    $dow = (int) $today->format('w');
    $weekStart = $today->modify('-' . $dow . ' days');
    $weekEnd = $weekStart->modify('+7 days');
    $weekTotals = $totalsFn($weekStart, $weekEnd);
    $weekLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $weeklySeries = [];
    for ($i = 0; $i < 7; $i++) {
        $date = $weekStart->modify("+{$i} days");
        $key = $date->format('Y-m-d');
        $weeklySeries[] = [
            'label' => $weekLabels[$i],
            'value' => (float) ($weekTotals[$key] ?? 0),
        ];
    }

    $monthStart = $today->modify('first day of this month')->modify('-5 months');
    $monthEnd = $today->modify('first day of next month');
    $monthTotals = $monthlyTotalsFn($monthStart, $monthEnd);
    $monthlySeries = [];
    for ($i = 0; $i < 6; $i++) {
        $date = $monthStart->modify("+{$i} months");
        $key = $date->format('Y-m');
        $monthlySeries[] = [
            'label' => $date->format('M'),
            'value' => (float) ($monthTotals[$key] ?? 0),
        ];
    }

    return [
        'day' => $daySeries,
        'weekly' => $weeklySeries,
        'monthly' => $monthlySeries,
    ];
}

/**
 * Monthly totals helper for invoice revenue.
 *
 * @return array<string, float>
 */
function salesRevenueTotalsByMonth(DateTimeInterface $start, DateTimeInterface $end): array
{
    $pdo = sales_pdo();
    $sql = "
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS sale_month, SUM(total_amount) AS total
        FROM invoices
        WHERE status != 'cancelled'
          AND created_at >= ?
          AND created_at < ?
    ";
    $params = [
        $start->format('Y-m-d 00:00:00'),
        $end->format('Y-m-d 00:00:00'),
    ];
    salesAppendCompanyScope($sql, $params, 'invoices');
    $sql .= ' GROUP BY DATE_FORMAT(created_at, \'%Y-%m\')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(string) ($row['sale_month'] ?? '')] = (float) ($row['total'] ?? 0);
    }

    return $out;
}

/**
 * Monthly totals helper for quotations.
 *
 * @return array<string, float>
 */
function salesQuoteTotalsByMonth(DateTimeInterface $start, DateTimeInterface $end): array
{
    $pdo = sales_pdo();
    $sql = "
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS quote_month, SUM(total_amount) AS total
        FROM sales_orders
        WHERE status IN ('draft', 'quotation')
          AND created_at >= ?
          AND created_at < ?
    ";
    $params = [
        $start->format('Y-m-d 00:00:00'),
        $end->format('Y-m-d 00:00:00'),
    ];
    salesAppendCompanyScope($sql, $params, 'sales_orders');
    $sql .= ' GROUP BY DATE_FORMAT(created_at, \'%Y-%m\')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(string) ($row['quote_month'] ?? '')] = (float) ($row['total'] ?? 0);
    }

    return $out;
}

/**
 * Build revenue growth series for dashboard (day / weekly / monthly toggles).
 *
 * @return array{day:list<array{label:string,value:float}>,weekly:list<array{label:string,value:float}>,monthly:list<array{label:string,value:float}>}
 */
function getRevenueGrowthSeries(): array
{
    return salesBuildGrowthSeries(
        'salesRevenueTotalsByDay',
        'salesRevenueTotalsByMonth'
    );
}

/**
 * Build quotation growth series for dashboard (day / weekly / monthly toggles).
 *
 * @return array{day:list<array{label:string,value:float}>,weekly:list<array{label:string,value:float}>,monthly:list<array{label:string,value:float}>}
 */
function getQuoteGrowthSeries(): array
{
    return salesBuildGrowthSeries(
        'salesQuoteTotalsByDay',
        'salesQuoteTotalsByMonth'
    );
}

function getRecentActivities($limit = 10) {
    $pdo = sales_pdo();
    $scopeNamedSo = salesCompanyScopeNamed('sales_orders', 'so', 'cid_so');
    $scope = $scopeNamedSo[0];
    $cidSo = $scopeNamedSo[1];
    $scopeNamedInv = salesCompanyScopeNamed('invoices', 'i', 'cid_inv');
    $scopeInv = $scopeNamedInv[0];
    $cidInv = $scopeNamedInv[1];
    
    $sql = "
        (SELECT 
            so.id,
            so.created_at, 
            'order' as type, 
            so.order_number as ref_number, 
            so.total_amount, 
            so.status,
            c.company_name as customer_name,
            u.username as user_name
         FROM sales_orders so
         JOIN customers c ON so.customer_id = c.id
         LEFT JOIN users u ON so.created_by = u.id
         WHERE 1=1 $scope
         ORDER BY so.created_at DESC LIMIT :limit1)
        
        UNION ALL
        
        (SELECT 
            i.id,
            i.created_at, 
            'invoice' as type, 
            i.invoice_number as ref_number, 
            i.total_amount, 
            i.status,
            c.company_name as customer_name,
            u.username as user_name
         FROM invoices i
         JOIN customers c ON i.customer_id = c.id
         LEFT JOIN users u ON i.created_by = u.id
         WHERE 1=1 $scopeInv
         ORDER BY i.created_at DESC LIMIT :limit2)
         
        ORDER BY created_at DESC
        LIMIT :limit3
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit1', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':limit2', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':limit3', $limit, PDO::PARAM_INT);
    if ($cidSo) {
        $stmt->bindValue(':cid_so', $cidSo, PDO::PARAM_INT);
    }
    if ($cidInv) {
        $stmt->bindValue(':cid_inv', $cidInv, PDO::PARAM_INT);
    }
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
/**
 * Get daily sales totals for the last N days.
 * Returns array of [date => total]
 */
function getDailySalesStats($days = 30) {
    $pdo = sales_pdo();
    $stats = [];
    // Initialize with 0s
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stats[$date] = 0;
    }

    $sql = "
        SELECT DATE(created_at) as date, SUM(total_amount) as total
        FROM invoices 
        WHERE status != 'cancelled' 
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    $params = [$days];
    salesAppendCompanyScope($sql, $params, 'invoices');
    $sql .= " GROUP BY DATE(created_at)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $stats[$row['date']] = (float)$row['total'];
    }
    return array_values($stats);
}

/** 
 * Get daily new quotes/drafts count for last N days.
 */
function getDailyQuoteStats($days = 30) {
    $pdo = sales_pdo();
    $stats = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stats[$date] = 0;
    }

    $sql = "
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM sales_orders 
        WHERE status IN ('draft', 'quotation')
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    $params = [$days];
    salesAppendCompanyScope($sql, $params, 'sales_orders');
    $sql .= " GROUP BY DATE(created_at)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $stats[$row['date']] = (int)$row['count'];
    }
    return array_values($stats);
}

/**
 * Get daily overdue invoices count based on DUE DATE.
 * This shows "Invoices that became overdue" on each day.
 */
function getDailyOverdueStats($days = 30) {
    $pdo = sales_pdo();
    ensureCustomerColumnsExist();
    $stats = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stats[$date] = 0;
    }

    $sql = "
        SELECT DATE(due_date) as date, COUNT(*) as count
        FROM invoices 
        WHERE status = 'overdue'
        AND due_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    $params = [$days];
    salesAppendCompanyScope($sql, $params, 'invoices');
    $sql .= " GROUP BY DATE(due_date)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $stats[$row['date']] = (int)$row['count'];
    }
    return array_values($stats);
}

/**
 * Get daily commission earned.
 */
function getDailyCommissionStats($days = 30) {
    $pdo = sales_pdo();
    $stats = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stats[$date] = 0;
    }

    ensureSalesCommissionsSchema();
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) as date, SUM(commission_amount) as total
        FROM sales_commissions
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY DATE(created_at)
    ");
    $stmt->execute([$days]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $stats[$row['date']] = (float)$row['total'];
    }
    return array_values($stats);
}

/**
 * Generate SVG path data 'd' attribute from an array of numbers using smooth quadratic curves.
 * @param array $data Array of numerical values
 * @param int $width Viewbox width
 * @param int $height Viewbox height
 * @return string SVG Path data (M... C...)
 */
function generateSparklinePath($data, $width = 100, $height = 30) {
    if (empty($data)) return "M 0 $height L $width $height";

    $max = max($data);
    $count = count($data);
    $step = $width / max(1, $count - 1);
    
    // Normalize height: 0 value = $height (bottom), max value = 5 (top padding)
    $range = $max == 0 ? 1 : $max; 
    $availableHeight = $height - 5; 

    // Calculate points
    $points = [];
    foreach ($data as $i => $val) {
        $x = $i * $step;
        $y = $height - (($val / $range) * $availableHeight);
        $points[] = ['x' => $x, 'y' => $y];
    }

    if ($count < 2) {
        return "M 0 " . number_format($points[0]['y'], 2) . " L $width " . number_format($points[0]['y'], 2);
    }

    // Generate Path with Quadratic Bezier Curves for smoothing
    // Start at first point
    $path = "M " . number_format($points[0]['x'], 2) . " " . number_format($points[0]['y'], 2);

    for ($i = 0; $i < $count - 1; $i++) {
        $p0 = $points[$i];
        $p1 = $points[$i + 1];

        // Control point is halfway between current and next point x, 
        // but keeps current point's y for a horizontal-ish start? No, that's not smooth.
        // Simple smoothing: use midpoints as end of quadratic curve, control point is the point itself?
        // Better: Catmull-Rom spline converted to Cubic Bezier is standard but complex.
        
        // Let's use a simpler cubic bezier strategy:
        // C (cp1x, cp1y) (cp2x, cp2y) (x, y)
        // Control points generally extend horizontally from the point to smooth out the curve.
        
        $cp1x = $p0['x'] + ($step / 2); // extend forward 50% of step
        $cp1y = $p0['y'];
        
        $cp2x = $p1['x'] - ($step / 2); // extend backward 50% of step
        $cp2y = $p1['y'];
        
        // This creates a horizontal tangent at each point (monotone cubic interpolation simplified)
        // Ideally we want the tangent to follow the slope, but horizontal tangent at peaks/valleys is "smooth" enough for sparklines.
        
        $path .= " C " . number_format($cp1x, 2) . " " . number_format($cp1y, 2) . ", " 
                       . number_format($cp2x, 2) . " " . number_format($cp2y, 2) . ", " 
                       . number_format($p1['x'], 2) . " " . number_format($p1['y'], 2);
    }
    
    return $path;
}

/**
 * Absolute path to a file under modules/sales/ (APP_BASE_PATH + optional company slug).
 */
function sales_module_url(string $relativePath, array $query = []): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $path = 'modules/sales/' . $relativePath;

    $slug = '';
    if (function_exists('getRequestedCompanySlug')) {
        $slug = strtolower(trim((string) getRequestedCompanySlug()));
    }
    if ($slug === '' && !empty($_SESSION['company_slug'])) {
        $slug = strtolower(trim((string) $_SESSION['company_slug']));
    }

    if ($slug !== '' && function_exists('company_url')) {
        $url = company_url($path);
    } else {
        $url = sales_app_url($path);
    }

    if ($query !== []) {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($query);
    }

    return $url;
}

/**
 * App base path helper (works even when includes/functions.php was not loaded yet).
 */
function sales_app_url(string $path = '/'): string
{
    if (function_exists('app_url')) {
        return app_url($path);
    }
    $base = defined('APP_BASE_PATH') ? rtrim((string) APP_BASE_PATH, '/') : '';
    $p = '/' . ltrim(str_replace('\\', '/', $path), '/');
    return ($base !== '' ? $base : '') . $p;
}

/**
 * Load stock image helpers (resolve paths on disk) when available.
 */
function sales_load_stock_image_helpers()
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $stockFns = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'stock' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'functions.php';
    if (is_file($stockFns)) {
        require_once $stockFns;
    }
}

/**
 * Product image URL for sales screens (uses stock/product_image.php when needed).
 */
function sales_product_image_url($productId, $filename = null, $size = 'medium')
{
    sales_load_stock_image_helpers();
    $placeholder = sales_app_url('stock/assets/images/no-image.png');

    if ($productId < 1) {
        return $placeholder;
    }

    $filename = trim((string) $filename);
    $size = in_array($size, array('thumbnail', 'medium', 'large', 'original'), true) ? $size : 'medium';

    $slug = '';
    $companyId = 0;
    if (function_exists('stock_image_company_context')) {
        $ctx = stock_image_company_context();
        $slug = $ctx['slug'];
        $companyId = (int) $ctx['company_id'];
    } elseif (!empty($_SESSION['company_slug'])) {
        $slug = strtolower(trim((string) $_SESSION['company_slug']));
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
    }

    if (function_exists('stock_resolve_product_image_file')) {
        $disk = stock_resolve_product_image_file($productId, $size, $filename, $slug, $companyId);
        if (($disk === null || !is_file($disk)) && $filename !== '') {
            $disk = stock_resolve_product_image_file($productId, $size, '', $slug, $companyId);
            if ($disk !== null && is_file($disk)) {
                $filename = '';
            }
        }
        if ($disk !== null && is_file($disk)) {
            $rel = str_replace('\\', '/', $disk);
            $stockRoot = str_replace('\\', '/', realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'stock') ?: '');
            if ($stockRoot !== '' && strpos($rel, $stockRoot) === 0) {
                $rel = ltrim(substr($rel, strlen($stockRoot)), '/');
                if (strpos($rel, 'uploads/') === 0) {
                    return sales_app_url('stock/' . $rel);
                }
            }
        }
    }

    $params = array('product_id' => (int) $productId, 'size' => $size);
    if ($filename !== '') {
        $params['file'] = basename($filename);
    }
    if ($slug !== '') {
        $params['company_slug'] = $slug;
    }

    return sales_app_url('stock/product_image.php') . '?' . http_build_query($params);
}

/**
 * Candidate image filenames for a product (main_image column + product_images gallery).
 *
 * @return list<string>
 */
function sales_product_image_candidates(array $item, PDO $pdo = null)
{
    $candidates = array();
    $add = function ($name) use (&$candidates) {
        $name = basename(str_replace('\\', '/', trim((string) $name)));
        if ($name !== '' && $name !== '.' && !in_array($name, $candidates, true)) {
            $candidates[] = $name;
        }
    };
    $add($item['main_image'] ?? '');
    $add($item['image'] ?? '');
    $pid = (int) ($item['product_id'] ?? 0);
    if ($pid > 0 && $pdo instanceof PDO) {
        try {
            $st = $pdo->prepare('SELECT image_name FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC');
            $st->execute(array($pid));
            while (($row = $st->fetchColumn()) !== false) {
                $add($row);
            }
        } catch (Throwable $e) {
        }
    }

    return $candidates;
}

/**
 * Resolve image filename for a sales line item (first name that exists on disk, else any gallery name).
 *
 * @param array<string,mixed> $item
 */
function sales_order_item_image_name(array $item, PDO $pdo = null)
{
    sales_load_stock_image_helpers();
    $pid = (int) ($item['product_id'] ?? 0);
    if ($pid < 1) {
        return trim((string) ($item['main_image'] ?? ''));
    }
    $slug = '';
    $companyId = 0;
    if (function_exists('stock_image_company_context')) {
        $ctx = stock_image_company_context();
        $slug = $ctx['slug'];
        $companyId = (int) $ctx['company_id'];
    }
    foreach (sales_product_image_candidates($item, $pdo) as $name) {
        if (function_exists('stock_resolve_product_image_file')) {
            $disk = stock_resolve_product_image_file($pid, 'medium', $name, $slug, $companyId);
            if ($disk !== null && is_file($disk)) {
                return $name;
            }
        } else {
            return $name;
        }
    }
    if (function_exists('stock_resolve_product_image_file')) {
        $disk = stock_resolve_product_image_file($pid, 'medium', '', $slug, $companyId);
        if ($disk !== null && is_file($disk)) {
            return '';
        }
    }

    $candidates = sales_product_image_candidates($item, $pdo);

    return !empty($candidates) ? $candidates[0] : '';
}

/**
 * Fill main_image on each order line from DB + on-disk files (quotations, orders, print).
 *
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function sales_enrich_order_items_images(array $items, PDO $pdo = null)
{
    if ($pdo === null && function_exists('sales_pdo')) {
        $pdo = sales_pdo();
    }
    foreach ($items as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }
        $resolved = sales_order_item_image_name($row, $pdo);
        $items[$idx]['main_image'] = $resolved;
    }

    return $items;
}

/**
 * Image URL for one sales order / quotation line item.
 *
 * @param array<string,mixed> $item
 */
function sales_order_item_image_url(array $item, $size = 'medium')
{
    $pid = (int) ($item['product_id'] ?? 0);
    if ($pid < 1) {
        return sales_product_image_url(0, null, $size);
    }
    $name = trim((string) ($item['main_image'] ?? ''));

    return sales_product_image_url($pid, $name, $size);
}

function sales_catalogue_url($doc = 'quote', $returnUrl = null)
{
    if ($returnUrl === null || $returnUrl === '') {
        $returnUrl = $_SERVER['REQUEST_URI'] ?? '';
    }
    if ($returnUrl === '') {
        $returnUrl = $doc === 'invoice'
            ? sales_module_url('invoices/create.php')
            : sales_module_url('orders/create.php', ['mode' => 'new']);
    }

    return sales_module_url('catalogue.php', ['doc' => $doc, 'return' => $returnUrl]);
}

/**
 * Customer picker catalogue (same UX as product sales catalogue).
 */
function sales_customer_catalogue_url($doc = 'quote', $returnUrl = null)
{
    if ($returnUrl === null || $returnUrl === '') {
        $returnUrl = $_SERVER['REQUEST_URI'] ?? '';
    }
    $doc = strtolower(trim($doc));
    if ($returnUrl === '') {
        $returnUrl = $doc === 'invoice'
            ? sales_module_url('invoices/create.php')
            : sales_module_url('orders/create.php', ['mode' => 'new']);
    }
    if ($doc === '') {
        $doc = 'quote';
    }

    return sales_module_url('customers/catalogue.php', ['doc' => $doc, 'return' => $returnUrl]);
}

/**
 * Highest sequence for legacy invoice numbers INV-YYYY-NNNN.
 */
function sales_legacy_invoice_max_sequence(PDO $db, int $year): int
{
    $like = 'INV-' . $year . '-%';
    try {
        $stmt = $db->prepare('SELECT invoice_number FROM invoices WHERE invoice_number LIKE ?');
        $stmt->execute([$like]);
        $max = 0;
        $pattern = '/^INV-' . preg_quote((string) $year, '/') . '-(\d+)$/i';
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $num) {
            if (preg_match($pattern, (string) $num, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Next invoice number, continuing INV-YYYY-NNNN when that is the active format.
 */
function sales_next_invoice_number(?PDO $salesDb = null, $companyId = null): string
{
    $db = $salesDb instanceof PDO ? $salesDb : (function_exists('sales_pdo') ? sales_pdo() : $GLOBALS['pdo']);
    $yr = (int) date('Y');
    $cid = (int) ($companyId ?? (function_exists('currentCompanyId') ? currentCompanyId() : 0));
    $legacyMax = sales_legacy_invoice_max_sequence($db, $yr);
    $legacyCount = 0;
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM invoices WHERE invoice_number LIKE ?');
        $stmt->execute(['INV-' . $yr . '-%']);
        $legacyCount = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $legacyCount = 0;
    }

    global $pdo;
    $controlPdo = ($pdo instanceof PDO) ? $pdo : null;
    if (
        $legacyCount === 0
        && $cid > 0
        && $controlPdo instanceof PDO
        && function_exists('tableExists')
        && tableExists('document_sequences', $controlPdo)
        && function_exists('nextDocumentNumber')
    ) {
        return nextDocumentNumber('invoice', 'INV', $cid, $yr);
    }

    $next = $legacyMax + 1;
    if ($next <= 0) {
        try {
            $next = ((int) $db->query('SELECT COALESCE(MAX(id), 0) FROM invoices')->fetchColumn()) + 1;
        } catch (Throwable $e) {
            $next = 1;
        }
    }

    return 'INV-' . $yr . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

/**
 * Rename mis-numbered invoices created when document_sequences was missing (random INV/YYYY/NNN).
 */
function sales_fixup_random_invoice_numbers(?PDO $salesDb = null): int
{
    $db = $salesDb instanceof PDO ? $salesDb : (function_exists('sales_pdo') ? sales_pdo() : null);
    if (!$db instanceof PDO) {
        return 0;
    }
    $fixed = 0;
    try {
        $stmt = $db->query("SELECT id, invoice_number FROM invoices WHERE invoice_number LIKE 'INV/%' ORDER BY id ASC");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            $newNumber = sales_next_invoice_number($db);
            $upd = $db->prepare('UPDATE invoices SET invoice_number = ? WHERE id = ? AND invoice_number = ?');
            $upd->execute([$newNumber, (int) $row['id'], (string) $row['invoice_number']]);
            if ($upd->rowCount() > 0) {
                $fixed++;
            }
        }
    } catch (Throwable $e) {
        error_log('sales_fixup_random_invoice_numbers: ' . $e->getMessage());
    }

    return $fixed;
}

/**
 * When /{slug}/sales routes use a tenant DB without sales tables, align global $pdo with control DB
 * so legacy sales pages that query $pdo directly still work.
 */
function sales_bootstrap_connection()
{
    $salesConn = sales_find_database_pdo();
    if ($salesConn instanceof PDO) {
        $GLOBALS['sales_pdo'] = $salesConn;
    }
}

/**
 * Whether an invoice can be removed (unpaid test/mistake — no recorded payments).
 */
function sales_invoice_is_deletable(array $invoice, PDO $salesDb): array
{
    $amountPaid = (float) ($invoice['amount_paid'] ?? 0);
    if ($amountPaid > 0.009) {
        return ['ok' => false, 'message' => 'This invoice has payments recorded and cannot be deleted.'];
    }

    $invoiceId = (int) ($invoice['id'] ?? 0);
    if ($invoiceId <= 0) {
        return ['ok' => false, 'message' => 'Invalid invoice.'];
    }

    if (sales_connection_has_table($salesDb, 'sales_payments')) {
        $st = $salesDb->prepare('SELECT COUNT(*) FROM sales_payments WHERE invoice_id = ?');
        $st->execute([$invoiceId]);
        if ((int) $st->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'This invoice has payment records and cannot be deleted.'];
        }
    }

    if (sales_connection_has_table($salesDb, 'payments')) {
        $st = $salesDb->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id = ?');
        $st->execute([$invoiceId]);
        if ((int) $st->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'This invoice has payment records and cannot be deleted.'];
        }
    }

    if (sales_connection_has_table($salesDb, 'revenue_entries')) {
        $reCols = $salesDb->query('SHOW COLUMNS FROM revenue_entries')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('source_invoice_id', $reCols, true)) {
            $st = $salesDb->prepare('SELECT total_paid FROM revenue_entries WHERE source_invoice_id = ? LIMIT 1');
            $st->execute([$invoiceId]);
            $paid = $st->fetchColumn();
            if ($paid !== false && (float) $paid > 0.009) {
                return ['ok' => false, 'message' => 'This invoice is linked to collected revenue and cannot be deleted.'];
            }
        }
    }

    $status = strtolower(trim((string) ($invoice['status'] ?? '')));
    if ($status === 'paid') {
        return ['ok' => false, 'message' => 'Paid invoices cannot be deleted.'];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Delete a mistaken/test sales invoice (admin). Removes ledger links; restores stock when the linked order is removed.
 */
function sales_delete_invoice(PDO $salesDb, int $invoiceId, int $companyId = 0): array
{
    if ($invoiceId <= 0) {
        return ['ok' => false, 'message' => 'Invalid invoice id.'];
    }

    try {
        $sql = 'SELECT * FROM invoices WHERE id = ?';
        $params = [$invoiceId];
        if ($companyId > 0 && function_exists('columnExists') && columnExists('invoices', 'company_id', $salesDb)) {
            $sql .= ' AND company_id = ?';
            $params[] = $companyId;
        }
        $st = $salesDb->prepare($sql);
        $st->execute($params);
        $invoice = $st->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            return ['ok' => false, 'message' => 'Invoice not found.'];
        }

        $check = sales_invoice_is_deletable($invoice, $salesDb);
        if (!$check['ok']) {
            return $check;
        }

        $invoiceNumber = (string) ($invoice['invoice_number'] ?? ('#' . $invoiceId));
        $orderId = (int) ($invoice['order_id'] ?? 0);

        $salesDb->beginTransaction();

        if (sales_connection_has_table($salesDb, 'sales_payments')) {
            $salesDb->prepare('DELETE FROM sales_payments WHERE invoice_id = ?')->execute([$invoiceId]);
        }
        if (sales_connection_has_table($salesDb, 'payments')) {
            $salesDb->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$invoiceId]);
        }
        if (sales_connection_has_table($salesDb, 'invoice_items')) {
            $salesDb->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);
        }
        if (sales_connection_has_table($salesDb, 'revenue_ledger')) {
            $salesDb->prepare("DELETE FROM revenue_ledger WHERE source_type = 'invoice' AND source_id = ?")->execute([$invoiceId]);
        }
        if (sales_connection_has_table($salesDb, 'revenue_entries')) {
            $reCols = $salesDb->query('SHOW COLUMNS FROM revenue_entries')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (in_array('source_invoice_id', $reCols, true)) {
                $salesDb->prepare('DELETE FROM revenue_entries WHERE source_invoice_id = ?')->execute([$invoiceId]);
            }
        }

        $salesDb->prepare('DELETE FROM invoices WHERE id = ?')->execute([$invoiceId]);

        if ($orderId > 0) {
            $stOther = $salesDb->prepare('SELECT COUNT(*) FROM invoices WHERE order_id = ?');
            $stOther->execute([$orderId]);
            $remainingInvoices = (int) $stOther->fetchColumn();
            if ($remainingInvoices === 0 && sales_connection_has_table($salesDb, 'sales_orders')) {
                if (function_exists('restoreStockForOrder')) {
                    restoreStockForOrder($orderId);
                }
                if (sales_connection_has_table($salesDb, 'sales_order_items')) {
                    $salesDb->prepare('DELETE FROM sales_order_items WHERE order_id = ?')->execute([$orderId]);
                }
                $delOrderSql = 'DELETE FROM sales_orders WHERE id = ?';
                $delOrderParams = [$orderId];
                if ($companyId > 0 && function_exists('columnExists') && columnExists('sales_orders', 'company_id', $salesDb)) {
                    $delOrderSql .= ' AND company_id = ?';
                    $delOrderParams[] = $companyId;
                }
                $salesDb->prepare($delOrderSql)->execute($delOrderParams);
            }
        }

        $salesDb->commit();

        return [
            'ok' => true,
            'message' => 'Invoice deleted.',
            'invoice_number' => $invoiceNumber,
            'id' => $invoiceId,
        ];
    } catch (Throwable $e) {
        if ($salesDb->inTransaction()) {
            $salesDb->rollBack();
        }
        error_log('sales_delete_invoice: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not delete invoice. ' . $e->getMessage()];
    }
}

sales_bootstrap_connection();
