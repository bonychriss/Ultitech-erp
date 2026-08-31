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
 * Find the MySQL database that contains sales_orders (may differ from voucher tenant DB).
 */
function sales_find_database_pdo()
{
    static $resolved = null;
    if ($resolved instanceof PDO) {
        return $resolved;
    }

    global $pdo, $control_pdo;

    $tryConnections = array();
    if ($pdo instanceof PDO) {
        $tryConnections[] = $pdo;
    }
    if ($control_pdo instanceof PDO && $control_pdo !== $pdo) {
        $tryConnections[] = $control_pdo;
    }
    foreach ($tryConnections as $conn) {
        if (sales_connection_has_sales_orders_schema($conn)) {
            $resolved = $conn;
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
                if (preg_match('/ultimate|trading|voucher|sales/i', $dbName)) {
                    $dbCandidates[] = $dbName;
                }
            }
        } catch (Throwable $e) {
        }
    }

    $dbCandidates[] = 'new_trading_voucher_db';
    $dbCandidates[] = 'ultimate_trading_voucher';

    $dbCandidates = array_values(array_unique(array_filter($dbCandidates)));
    foreach ($dbCandidates as $dbName) {
        if ($dbName === '' || !function_exists('connectToTenantDatabase')) {
            continue;
        }
        $tenantPdo = connectToTenantDatabase($dbName);
        if ($tenantPdo instanceof PDO && sales_connection_has_sales_orders_schema($tenantPdo)) {
            $resolved = $tenantPdo;
            $GLOBALS['sales_database_name'] = $dbName;
            if (!defined('SALES_ON_CONTROL_DB')) {
                define('SALES_ON_CONTROL_DB', true);
            }
            error_log('sales_find_database_pdo: using database ' . $dbName);
            return $resolved;
        }
    }

    $resolved = ($pdo instanceof PDO) ? $pdo : $control_pdo;
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
    if (defined('SALES_ON_CONTROL_DB') && SALES_ON_CONTROL_DB) {
        return [" AND ({$col} = ? OR {$col} IS NULL OR {$col} = 0)", [$cid]];
    }
    return [" AND {$col} = ?", [$cid]];
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
 * Get most outgoing (best-selling) products by quantity sold.
 * Uses sales_order_items from confirmed/invoiced/shipped/paid/delivered orders.
 * @param int $limit Max number of products to return
 * @param int $days Look back period in days (default 30)
 * @return array
 */
function getMostOutgoingProducts($limit = 5, $days = 30) {
    $pdo = sales_pdo();
    $limit = (int) $limit;
    $days = (int) $days;
    $scopeSo2 = salesCompanyScopeSql('sales_orders', 'so2');
    $scopeSo = salesCompanyScopeSql('sales_orders', 'so');
    $sql = "SELECT soi.product_id, COALESCE(p.name, soi.description) AS product_name, COALESCE(p.main_image, p.image) AS main_image, COALESCE(SUM(soi.quantity), 0) AS total_qty,
            (
                SELECT c.company_name 
                FROM sales_order_items soi2 
                JOIN sales_orders so2 ON so2.id = soi2.order_id 
                JOIN customers c ON c.id = so2.customer_id 
                WHERE soi2.product_id = soi.product_id 
                AND so2.status IN ('confirmed','invoiced','shipped','paid','delivered')
                {$scopeSo2[0]}
                GROUP BY c.id, c.company_name
                ORDER BY SUM(soi2.quantity) DESC 
                LIMIT 1
            ) AS top_customer_name
            FROM sales_order_items soi
            JOIN sales_orders so ON so.id = soi.order_id
            LEFT JOIN products p ON p.id = soi.product_id
            WHERE so.status IN ('confirmed','invoiced','shipped','paid','delivered')
              AND soi.product_id IS NOT NULL
              AND soi.product_id != 17
              AND DATE(so.created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              {$scopeSo[0]}
            GROUP BY soi.product_id, p.name, COALESCE(p.main_image, p.image), soi.description
            ORDER BY total_qty DESC
            LIMIT " . $limit;
    try {
        $stmt = $pdo->prepare($sql);
        $params = array_merge($scopeSo2[1], [$days], $scopeSo[1]);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
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

sales_bootstrap_connection();
