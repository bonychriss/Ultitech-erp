<?php
/**
 * Stock Purchase Payment Desk � shared backend helpers for React API + shell.
 */

declare(strict_types=1);

function sppdBootstrap(): PDO
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 3) . '/includes/functions.php';
        $balancesDbPath = dirname(__DIR__, 2) . '/balances/config/database.php';
        if (is_file($balancesDbPath)) {
            require_once $balancesDbPath;
        } else {
            $balancesFnsPath = dirname(__DIR__, 2) . '/balances/functions.php';
            if (is_file($balancesFnsPath)) {
                require_once $balancesFnsPath;
            }
        }
        $booted = true;
    }

    global $pdo;
    if (function_exists('balancesSyncGlobalPdo')) {
        $balancesPdo = balancesSyncGlobalPdo();
        if ($balancesPdo instanceof PDO) {
            $pdo = $balancesPdo;
        }
    }

    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    sppdEnsurePurchaseOrderPaymentSchema($pdo);

    return $pdo;
}

function sppdRequireAccess(): void
{
    sppdBootstrap();
    if (function_exists('requireFinanceOrAdmin')) {
        requireFinanceOrAdmin();
    } else {
        requireLogin();
    }
}

/**
 * Web path prefix for the finance desk shell (directory containing stock-purchase-payment-desk.php).
 */
function sppdDeskShellScriptSuffix(): string
{
    return '/stock-purchase-payment-desk.php';
}

function sppdDeskWebBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $suffix = sppdDeskShellScriptSuffix();
    if ($script !== '' && substr($script, -strlen($suffix)) === $suffix) {
        return rtrim(dirname($script), '/');
    }

    if (function_exists('app_url')) {
        return app_url('');
    }

    return rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
}

/**
 * Build a browser URL for desk UI assets/API relative to the shell script directory.
 */
function sppdDeskPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $suffix = sppdDeskShellScriptSuffix();
    if ($script !== '' && substr($script, -strlen($suffix)) === $suffix) {
        return rtrim(dirname($script), '/') . '/' . $relativePath;
    }

    if (function_exists('app_url')) {
        return app_url('modules/finance/' . $relativePath);
    }

    return $relativePath;
}

function sppdValidTabs(): array
{
    return [
        'awaiting_po' => 'purchases to be paid',
        'needs_classification' => 'purchases to be paid',
    ];
}

function sppdIsUnpaidPurchaseOrderTab(string $tab): bool
{
    return in_array($tab, ['awaiting_po', 'needs_classification'], true);
}

function sppdNormalizeTab(string $tab): string
{
    $tab = strtolower(trim($tab));
    $valid = sppdValidTabs();
    return isset($valid[$tab]) ? $tab : 'awaiting_po';
}

function sppdPurchaseOrderDeskRealId(int $deskPoId): int
{
    return $deskPoId >= 1000000 ? ($deskPoId - 1000000) : $deskPoId;
}

function sppdSupplierPaymentsSumSql(string $purchaseOrderIdExpr): string
{
    return "(
        SELECT COALESCE(SUM(sp.amount), 0)
        FROM supplier_payments sp
        WHERE sp.purchase_order_id = {$purchaseOrderIdExpr}
           OR sp.purchase_order_id = {$purchaseOrderIdExpr} + 1000000
    )";
}

function sppdUnpaidPurchaseOrderWhereSql(PDO $pdo, string $alias = 'po'): string
{
    $amountExpr = sppdPurchaseOrderAmountExpr($alias);

    if (sppdTableExists($pdo, 'supplier_payments')) {
        $amountPaidExpr = sppdSupplierPaymentsSumSql($alias . '.id');

        return "({$amountExpr} - {$amountPaidExpr}) > 0.009";
    }

    if (sppdPurchaseOrderHasPaymentStatus($pdo)) {
        return "LOWER(TRIM(COALESCE({$alias}.payment_status, ''))) NOT IN ('paid')";
    }

    return '1=1';
}

function sppdPurchaseOrderPaymentStatusSelectSql(PDO $pdo, string $alias = 'po'): string
{
    if (sppdPurchaseOrderHasPaymentStatus($pdo)) {
        return "{$alias}.payment_status";
    }

    return "'unpaid' AS payment_status";
}

function sppdPurchaseOrderAmountExpr(string $alias = 'po'): string
{
    return "COALESCE(NULLIF({$alias}.total_amount, 0), (
        SELECT COALESCE(SUM(pi.qty_ordered * pi.unit_cost), 0)
        FROM stocks_po_items pi
        WHERE pi.po_id = {$alias}.id
    ), 0)";
}

function sppdAmountPaidSelectSql(PDO $pdo, string $poAlias = 'po'): string
{
    if (!sppdTableExists($pdo, 'supplier_payments')) {
        return '0 AS amount_paid';
    }

    return sppdSupplierPaymentsSumSql("{$poAlias}.id") . ' AS amount_paid';
}

function sppdSumPaymentsForPo(PDO $pdo, int $poId): float
{
    if ($poId <= 0) {
        return 0.0;
    }

    $isLegacy = ($poId >= 1000000);
    $realId = $isLegacy ? ($poId - 1000000) : $poId;

    if ($isLegacy) {
        if (!sppdTableExists($pdo, 'payment_vouchers')) {
            return 0.0;
        }
        try {
            $stmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM payment_vouchers WHERE linked_stock_po_id = ? AND is_paid = 1');
            $stmt->execute([$realId]);
            return (float) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    if (!sppdTableExists($pdo, 'supplier_payments')) {
        return 0.0;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE purchase_order_id IN (?, ?)',
        );
        $stmt->execute([$realId, $realId + 1000000]);

        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function sppdPurchaseOrderTotalAmount(array $po, PDO $pdo): float
{
    $total = (float) ($po['total_amount'] ?? 0);
    if ($total > 0) {
        return $total;
    }

    $poId = (int) ($po['id'] ?? 0);
    if ($poId <= 0) {
        return 0.0;
    }

    $isLegacy = ($poId >= 1000000);
    $realId = $isLegacy ? ($poId - 1000000) : $poId;

    try {
        if ($isLegacy) {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(quantity * unit_price), 0) FROM purchase_items WHERE purchase_id = ?'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(qty_ordered * unit_cost), 0) FROM stocks_po_items WHERE po_id = ?'
            );
        }
        $stmt->execute([$realId]);

        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function sppdResolvePaymentStatus(float $totalAmount, float $amountPaid, string $dbStatus = ''): string
{
    $totalAmount = round($totalAmount, 2);
    $amountPaid = round($amountPaid, 2);
    $balanceDue = max(0.0, round($totalAmount - $amountPaid, 2));

    if ($balanceDue <= 0.009 && $amountPaid > 0) {
        return 'paid';
    }
    if ($amountPaid > 0.009) {
        return 'partially_paid';
    }

    $normalized = strtolower(trim(str_replace([' ', '-'], '_', $dbStatus)));
    if ($normalized === 'partially_paid' || str_contains($normalized, 'partial')) {
        return 'partially_paid';
    }
    if ($normalized === 'paid') {
        return 'paid';
    }

    return 'unpaid';
}

function sppdTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array<int, string>
 */
function sppdPurchaseOrderColumns(PDO $pdo, bool $refresh = false): array
{
    static $cache = null;
    if ($refresh) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }

    if (!sppdTableExists($pdo, 'stocks_purchase_orders')) {
        $cache = [];

        return $cache;
    }

    try {
        $cache = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $cache = [];
    }

    return $cache;
}

function sppdPurchaseOrderHasPaymentStatus(PDO $pdo): bool
{
    return in_array('payment_status', sppdPurchaseOrderColumns($pdo), true);
}

function sppdEnsurePurchaseOrderPaymentSchema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    if (!sppdTableExists($pdo, 'stocks_purchase_orders')) {
        return;
    }

    if (!sppdPurchaseOrderHasPaymentStatus($pdo)) {
        try {
            $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid' AFTER status");
            sppdPurchaseOrderColumns($pdo, true);
        } catch (Throwable $e) {
            // Fall back to supplier_payments-derived status when ALTER is not permitted.
        }
    }

    if (!sppdTableExists($pdo, 'supplier_payments')) {
        return;
    }

    try {
        $paymentCols = $pdo->query('SHOW COLUMNS FROM supplier_payments')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('purchase_order_id', $paymentCols, true)) {
            $after = in_array('vendor_bill_id', $paymentCols, true) ? ' AFTER vendor_bill_id' : '';
            $pdo->exec('ALTER TABLE supplier_payments ADD COLUMN purchase_order_id INT NULL DEFAULT NULL' . $after);
        }
    } catch (Throwable $e) {
        // Non-fatal; payment history linking may be unavailable on older schemas.
    }
}

function sppdUserDisplayExpr(string $userAlias = 'u'): string
{
    static $exprByAlias = [];
    if (isset($exprByAlias[$userAlias])) {
        return $exprByAlias[$userAlias];
    }

    $exprByAlias[$userAlias] = "TRIM(COALESCE({$userAlias}.username, ''))";
    return $exprByAlias[$userAlias];
}

function sppdResolveUserDisplayExpr(PDO $pdo, string $userAlias = 'u'): string
{
    static $cache = [];
    if (isset($cache[$userAlias])) {
        return $cache[$userAlias];
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $cache[$userAlias] = sppdUserDisplayExpr($userAlias);
        return $cache[$userAlias];
    }

    $hasFullName = in_array('full_name', $cols, true);
    $hasName = in_array('name', $cols, true);
    if ($hasFullName && $hasName) {
        $cache[$userAlias] = "TRIM(COALESCE(NULLIF(TRIM({$userAlias}.full_name), ''), NULLIF(TRIM({$userAlias}.name), ''), {$userAlias}.username, ''))";
    } elseif ($hasFullName) {
        $cache[$userAlias] = "TRIM(COALESCE(NULLIF(TRIM({$userAlias}.full_name), ''), {$userAlias}.username, ''))";
    } elseif ($hasName) {
        $cache[$userAlias] = "TRIM(COALESCE(NULLIF(TRIM({$userAlias}.name), ''), {$userAlias}.username, ''))";
    } else {
        $cache[$userAlias] = sppdUserDisplayExpr($userAlias);
    }

    return $cache[$userAlias];
}

function sppdPaidBySelectSql(PDO $pdo): string
{
    if (!sppdTableExists($pdo, 'supplier_payments') || !sppdTableExists($pdo, 'users')) {
        return "'' AS paid_by_name";
    }

    $userExpr = sppdResolveUserDisplayExpr($pdo, 'u');

    return "(
        SELECT {$userExpr}
        FROM supplier_payments sp
        LEFT JOIN users u ON u.id = sp.created_by
        WHERE sp.purchase_order_id = po.id
        ORDER BY sp.id DESC
        LIMIT 1
    ) AS paid_by_name";
}

/**
 * @return array<string, string>
 */
function sppdDefaultFilters(): array
{
    return [
        'q' => '',
        'date_from' => '',
        'date_to' => '',
        'payee' => '',
        'amount_min' => '',
        'amount_max' => '',
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, string>
 */
function sppdParseFilters(array $input): array
{
    $filters = sppdDefaultFilters();
    foreach (array_keys($filters) as $key) {
        if (isset($input[$key])) {
            $filters[$key] = trim((string) $input[$key]);
        }
    }
    return $filters;
}

function sppdPurchaseOrderEffectiveDateSql(string $alias = 'po', string $column = 'po_number'): string
{
    return "CASE
        WHEN {$alias}.{$column} REGEXP '^PUR-[0-9]{8}-'
        THEN STR_TO_DATE(SUBSTRING({$alias}.{$column}, 5, 8), '%Y%m%d')
        ELSE DATE({$alias}.created_at)
    END";
}

/**
 * @param array<string, string> $filters
 * @return array<int, array<string, mixed>>
 */
function sppdFetchPurchaseOrders(PDO $pdo, array $filters, bool $unpaidOnly = false): array
{
    if (!sppdTableExists($pdo, 'stocks_purchase_orders') && !sppdTableExists($pdo, 'purchases')) {
        return [];
    }

    $modernRows = [];
    if (sppdTableExists($pdo, 'stocks_purchase_orders')) {
        $poParams = [];
        $poWhere = ['1=1'];
        if ($unpaidOnly) {
            $poWhere[] = sppdUnpaidPurchaseOrderWhereSql($pdo, 'po');
        }
        $amountExpr = sppdPurchaseOrderAmountExpr('po');
        $paymentStatusSql = sppdPurchaseOrderPaymentStatusSelectSql($pdo, 'po');

        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $poWhere[] = '(po.po_number LIKE ? OR ss.name LIKE ?)';
            $poParams[] = $like;
            $poParams[] = $like;
        }

        if ($filters['date_from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
            $poWhere[] = sppdPurchaseOrderEffectiveDateSql('po') . ' >= ?';
            $poParams[] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
            $poWhere[] = sppdPurchaseOrderEffectiveDateSql('po') . ' <= ?';
            $poParams[] = $filters['date_to'];
        }

        if ($filters['payee'] !== '') {
            $poWhere[] = 'ss.name = ?';
            $poParams[] = $filters['payee'];
        }

        if ($filters['amount_min'] !== '' && is_numeric($filters['amount_min'])) {
            $poWhere[] = $amountExpr . ' >= ?';
            $poParams[] = (float) $filters['amount_min'];
        }
        if ($filters['amount_max'] !== '' && is_numeric($filters['amount_max'])) {
            $poWhere[] = $amountExpr . ' <= ?';
            $poParams[] = (float) $filters['amount_max'];
        }

        $paidBySql = sppdPaidBySelectSql($pdo);
        $amountPaidSql = sppdAmountPaidSelectSql($pdo);

        $poSql = '
            SELECT po.id, po.po_number, po.created_at, po.currency, po.status, ' . $paymentStatusSql . ',
                   ' . $amountExpr . ' as total_amount,
                   ss.name as payee_name,
                   ' . $paidBySql . ',
                   ' . $amountPaidSql . ',
                   ' . sppdPurchaseOrderEffectiveDateSql('po') . ' AS effective_date
            FROM stocks_purchase_orders po
            LEFT JOIN stocks_suppliers ss ON po.supplier_id = ss.id
            WHERE ' . implode(' AND ', $poWhere) . '
            ORDER BY effective_date DESC, po.id DESC'
            . ($unpaidOnly ? '' : ' LIMIT 500') . '
        ';

        try {
            $poStmt = $pdo->prepare($poSql);
            $poStmt->execute($poParams);
            $modernRows = $poStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $modernRows = [];
        }
    }

    $legacyRows = [];
    if (sppdTableExists($pdo, 'purchases')) {
        $legacyParams = [];
        $legacyWhere = ['p.status IN ("Approved", "Received")'];
        $legacyAmountExpr = "COALESCE(NULLIF(p.total_amount, 0), (
            SELECT COALESCE(SUM(pi.quantity * pi.unit_price), 0)
            FROM purchase_items pi
            WHERE pi.purchase_id = p.id
        ), 0)";
        
        if ($unpaidOnly) {
            $legacyWhere[] = "p.id NOT IN (
                SELECT COALESCE(pv.linked_stock_po_id, 0)
                FROM payment_vouchers pv
                WHERE pv.is_paid = 1
            ) AND COALESCE(
                (
                    SELECT SUM(sp.amount)
                    FROM supplier_payments sp
                    WHERE sp.purchase_order_id = p.id + 1000000
                ),
                0
            ) < " . $legacyAmountExpr . " - 0.009";
        }
        
        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $legacyWhere[] = '(p.purchase_no LIKE ? OR ss.name LIKE ?)';
            $legacyParams[] = $like;
            $legacyParams[] = $like;
        }

        if ($filters['date_from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
            $legacyWhere[] = sppdPurchaseOrderEffectiveDateSql('p', 'purchase_no') . ' >= ?';
            $legacyParams[] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
            $legacyWhere[] = sppdPurchaseOrderEffectiveDateSql('p', 'purchase_no') . ' <= ?';
            $legacyParams[] = $filters['date_to'];
        }

        if ($filters['payee'] !== '') {
            $legacyWhere[] = 'ss.name = ?';
            $legacyParams[] = $filters['payee'];
        }

        if ($filters['amount_min'] !== '' && is_numeric($filters['amount_min'])) {
            $legacyWhere[] = $legacyAmountExpr . ' >= ?';
            $legacyParams[] = (float) $filters['amount_min'];
        }
        if ($filters['amount_max'] !== '' && is_numeric($filters['amount_max'])) {
            $legacyWhere[] = $legacyAmountExpr . ' <= ?';
            $legacyParams[] = (float) $filters['amount_max'];
        }

        $legacySql = "
            SELECT p.id, p.purchase_no AS po_number, p.created_at, p.currency, p.status,
                   (
                       SELECT CASE WHEN (
                           EXISTS (
                               SELECT 1 FROM payment_vouchers pv
                               WHERE pv.linked_stock_po_id = p.id AND pv.is_paid = 1
                           )
                           OR
                           COALESCE(
                               (
                                   SELECT SUM(sp.amount)
                                   FROM supplier_payments sp
                                   WHERE sp.purchase_order_id = p.id + 1000000
                               ),
                               0
                           ) >= " . $legacyAmountExpr . " - 0.009
                       ) THEN 'paid' ELSE 'unpaid' END
                   ) AS payment_status,
                   " . $legacyAmountExpr . " AS total_amount,
                   ss.name AS payee_name,
                   '' AS paid_by_name,
                   (
                       COALESCE(
                           (
                               SELECT SUM(pv.total_amount)
                               FROM payment_vouchers pv
                               WHERE pv.linked_stock_po_id = p.id AND pv.is_paid = 1
                           ),
                           0
                       )
                       +
                       COALESCE(
                           (
                               SELECT SUM(sp.amount)
                               FROM supplier_payments sp
                               WHERE sp.purchase_order_id = p.id + 1000000
                           ),
                           0
                       )
                   ) AS amount_paid,
                   " . sppdPurchaseOrderEffectiveDateSql('p', 'purchase_no') . " AS effective_date
            FROM purchases p
            LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id
            WHERE " . implode(' AND ', $legacyWhere) . "
            ORDER BY effective_date DESC, p.id DESC"
            . ($unpaidOnly ? '' : ' LIMIT 500') . "
        ";
        
        try {
            $legacyStmt = $pdo->prepare($legacySql);
            $legacyStmt->execute($legacyParams);
            $legacyRows = $legacyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($legacyRows as &$row) {
                $row['id'] = (int)$row['id'] + 1000000;
            }
            unset($row);
        } catch (Throwable $e) {
            $legacyRows = [];
        }
    }

    $merged = array_merge($modernRows, $legacyRows);
    usort($merged, static function (array $a, array $b): int {
        $da = $a['effective_date'] ?: $a['created_at'];
        $db = $b['effective_date'] ?: $b['created_at'];
        $cmp = strcmp((string)$db, (string)$da);
        if ($cmp !== 0) {
            return $cmp;
        }
        return (int)$b['id'] - (int)$a['id'];
    });

    return $merged;
}

function sppdCountUnpaidPurchaseOrders(PDO $pdo): int
{
    if (!sppdTableExists($pdo, 'stocks_purchase_orders') && !sppdTableExists($pdo, 'purchases')) {
        return 0;
    }

    try {
        return count(sppdMapUnpaidPurchaseOrders(sppdFetchPurchaseOrders($pdo, sppdDefaultFilters(), true)));
    } catch (Throwable $e) {
        return 0;
    }
}

function sppdSumUnpaidPurchaseOrders(PDO $pdo): float
{
    $sum = 0.0;
    if (sppdTableExists($pdo, 'stocks_purchase_orders')) {
        $amountExpr = sppdPurchaseOrderAmountExpr('po');
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(' . $amountExpr . '), 0) FROM stocks_purchase_orders po WHERE ' . sppdUnpaidPurchaseOrderWhereSql($pdo, 'po')
        );
        $stmt->execute();
        $sum += (float) $stmt->fetchColumn();
    }
    if (sppdTableExists($pdo, 'purchases')) {
        $legacyAmountExpr = "COALESCE(NULLIF(p.total_amount, 0), (
            SELECT COALESCE(SUM(pi.quantity * pi.unit_price), 0)
            FROM purchase_items pi
            WHERE pi.purchase_id = p.id
        ), 0)";
        $sql = "SELECT COALESCE(SUM({$legacyAmountExpr}), 0)
                FROM purchases p
                WHERE p.status IN ('Approved', 'Received')
                  AND p.id NOT IN (
                      SELECT COALESCE(pv.linked_stock_po_id, 0)
                      FROM payment_vouchers pv
                      WHERE pv.is_paid = 1
                  )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $sum += (float) $stmt->fetchColumn();
    }
    return $sum;
}

function sppdOverduePayableConditionSql(PDO $pdo, string $alias = 'po'): string
{
    static $cols = null;
    if ($cols === null) {
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $cols = [];
        }
    }

    foreach (['due_date', 'payment_due_date', 'pay_by_date'] as $column) {
        if (in_array($column, $cols, true)) {
            return "{$alias}.{$column} IS NOT NULL AND DATE({$alias}.{$column}) < CURDATE()";
        }
    }

    return "DATE({$alias}.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
}

/**
 * @return array{amount: float, count: int, currency: string}
 */
function sppdFetchOverduePayables(PDO $pdo): array
{
    $overdueAmount = 0.0;
    $overdueCount = 0;
    
    if (sppdTableExists($pdo, 'stocks_purchase_orders')) {
        $amountExpr = sppdPurchaseOrderAmountExpr('po');
        $unpaid = sppdUnpaidPurchaseOrderWhereSql($pdo, 'po');
        $overdue = sppdOverduePayableConditionSql($pdo, 'po');

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS overdue_count, COALESCE(SUM({$amountExpr}), 0) AS overdue_amount
             FROM stocks_purchase_orders po
             WHERE {$unpaid} AND ({$overdue})"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $overdueAmount += (float) ($row['overdue_amount'] ?? 0);
        $overdueCount += (int) ($row['overdue_count'] ?? 0);
    }
    
    if (sppdTableExists($pdo, 'purchases')) {
        $legacyAmountExpr = "COALESCE(NULLIF(p.total_amount, 0), (
            SELECT COALESCE(SUM(pi.quantity * pi.unit_price), 0)
            FROM purchase_items pi
            WHERE pi.purchase_id = p.id
        ), 0)";
        $sql = "SELECT COUNT(*) AS overdue_count, COALESCE(SUM({$legacyAmountExpr}), 0) AS overdue_amount
                FROM purchases p
                WHERE p.status IN ('Approved', 'Received')
                  AND p.id NOT IN (
                      SELECT COALESCE(pv.linked_stock_po_id, 0)
                      FROM payment_vouchers pv
                      WHERE pv.is_paid = 1
                  )
                  AND DATE(p.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $overdueAmount += (float) ($row['overdue_amount'] ?? 0);
        $overdueCount += (int) ($row['overdue_count'] ?? 0);
    }

    return [
        'amount' => $overdueAmount,
        'count' => $overdueCount,
        'currency' => 'TZS',
    ];
}

/**
 * @param array<int, array<string, mixed>> $accounts
 * @return array<string, mixed>|null
 */
function sppdPickAccountsPayableAccount(array $accounts): ?array
{
    $best = null;
    $bestScore = -1;

    foreach ($accounts as $acc) {
        $name = strtolower(trim((string) ($acc['name'] ?? '')));
        $type = strtolower(trim((string) ($acc['type'] ?? '')));
        $isLiability = in_array($type, ['liability', 'current_liability', 'payable', 'current liabilities'], true)
            || str_contains($type, 'liabil')
            || str_contains($type, 'payable');

        if (!$isLiability) {
            continue;
        }

        $score = 0;
        if ($name === 'accounts payable') {
            $score = 100;
        } elseif (str_contains($name, 'accounts payable')) {
            $score = 90;
        } elseif (str_contains($name, 'account payable')) {
            $score = 85;
        } elseif (str_contains($name, 'payable')) {
            $score = 50;
        } else {
            continue;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $acc;
        }
    }

    return $best;
}

/**
 * @return array{balance: float, currency: string, source: string, accountId?: int, accountName?: string, ledgerSource?: string}
 */
function sppdFetchAccountsPayableBalance(PDO $pdo): array
{
    $currency = 'TZS';
    $accounts = [];

    if (function_exists('balancesFetchAccountsWithLiveBalance')) {
        try {
            $accounts = balancesFetchAccountsWithLiveBalance($pdo, true);
        } catch (Throwable $e) {
            $accounts = [];
        }
    }

    if ($accounts === [] && function_exists('getBalances')) {
        $accounts = getBalances();
    }

    $match = sppdPickAccountsPayableAccount($accounts);
    if ($match !== null) {
        $balance = isset($match['live_balance'])
            ? (float) $match['live_balance']
            : (float) ($match['current_balance'] ?? 0);

        return [
            'balance' => $balance,
            'currency' => trim((string) ($match['currency'] ?? 'TZS')) ?: 'TZS',
            'source' => 'ledger',
            'accountId' => (int) ($match['id'] ?? 0),
            'accountName' => trim((string) ($match['name'] ?? 'Accounts Payable')),
            'ledgerSource' => 'balances module live balance',
        ];
    }

    try {
        $stmt = $pdo->query("
            SELECT fa.id, fa.name, fa.currency, fa.type, fa.current_balance, fa.opening_balance
            FROM financial_accounts fa
            WHERE LOWER(TRIM(fa.name)) LIKE '%accounts payable%'
               OR LOWER(TRIM(fa.name)) LIKE '%account payable%'
            ORDER BY
                CASE
                    WHEN LOWER(TRIM(fa.name)) = 'accounts payable' THEN 0
                    WHEN LOWER(TRIM(fa.name)) LIKE '%accounts payable%' THEN 1
                    ELSE 2
                END,
                fa.id ASC
            LIMIT 1
        ");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (is_array($row)) {
            return [
                'balance' => (float) ($row['current_balance'] ?? $row['opening_balance'] ?? 0),
                'currency' => trim((string) ($row['currency'] ?? 'TZS')) ?: 'TZS',
                'source' => 'ledger',
                'accountId' => (int) ($row['id'] ?? 0),
                'accountName' => trim((string) ($row['name'] ?? 'Accounts Payable')),
                'ledgerSource' => 'financial_accounts.current_balance',
            ];
        }
    } catch (Throwable $e) {
        // fall through to unpaid PO estimate
    }

    return [
        'balance' => sppdSumUnpaidPurchaseOrders($pdo),
        'currency' => $currency,
        'source' => 'unpaid_pos',
        'ledgerSource' => 'estimated from unpaid PO totals',
    ];
}

/**
 * @return array{field: string, description: string, sql: string}
 */
function sppdDescribeOverdueRule(PDO $pdo): array
{
    foreach (['due_date', 'payment_due_date', 'pay_by_date'] as $column) {
        if (in_array($column, sppdPurchaseOrderColumns($pdo), true)) {
            return [
                'field' => $column,
                'description' => "Purchase order {$column} is before today",
                'sql' => "{$column} IS NOT NULL AND DATE({$column}) < CURDATE()",
            ];
        }
    }

    return [
        'field' => 'created_at',
        'description' => 'Purchase order created more than 30 days ago (used when no due-date column exists)',
        'sql' => 'DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
    ];
}

/**
 * @return array<int, array{label: string, value: string}>
 */
function sppdDescribeUnpaidCriteria(PDO $pdo): array
{
    $lines = [
        ['label' => 'Primary table', 'value' => 'stocks_purchase_orders'],
        ['label' => 'Supplier name', 'value' => 'LEFT JOIN stocks_suppliers ON supplier_id'],
        ['label' => 'PO amount', 'value' => 'COALESCE(total_amount, SUM(stocks_po_items.qty_ordered × unit_cost))'],
        ['label' => 'Display date', 'value' => 'PO number date (PUR-YYYYMMDD) when present, otherwise created_at'],
    ];

    if (sppdTableExists($pdo, 'supplier_payments')) {
        $lines[] = ['label' => 'Included when', 'value' => 'PO amount minus supplier_payments is greater than zero'];
        $lines[] = ['label' => 'Payments recorded in', 'value' => 'supplier_payments (summed per purchase_order_id)'];
    } elseif (sppdPurchaseOrderHasPaymentStatus($pdo)) {
        $lines[] = ['label' => 'Included when', 'value' => "payment_status is not 'paid'"];
    } else {
        $lines[] = ['label' => 'Included when', 'value' => 'All PO rows (payment_status column not present on this database)'];
    }

    return $lines;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function sppdMapPurchaseOrderTraceItem(array $row, float $contribution, string $note = ''): array
{
    $mapped = sppdMapPurchaseOrder($row);

    return [
        'id' => (int) ($mapped['id'] ?? 0),
        'poNumber' => (string) ($mapped['poNumber'] ?? ''),
        'payeeName' => (string) ($mapped['payeeName'] ?? ''),
        'createdAt' => (string) ($mapped['createdAt'] ?? ''),
        'currency' => (string) ($mapped['currency'] ?? 'TZS'),
        'amountToPay' => (float) ($mapped['amountToPay'] ?? 0),
        'amountPaid' => (float) ($mapped['amountPaid'] ?? 0),
        'balanceDue' => (float) ($mapped['balanceDue'] ?? 0),
        'paymentStatus' => (string) ($mapped['paymentStatus'] ?? ''),
        'contribution' => round($contribution, 2),
        'note' => $note,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function sppdFetchUnpaidPurchaseOrderTraceRows(PDO $pdo): array
{
    return sppdFetchPurchaseOrders($pdo, sppdDefaultFilters(), true);
}

/**
 * @return array<int, array<string, mixed>>
 */
function sppdFetchOverduePurchaseOrderTraceRows(PDO $pdo): array
{
    if (!sppdTableExists($pdo, 'stocks_purchase_orders') && !sppdTableExists($pdo, 'purchases')) {
        return [];
    }

    $modernRows = [];
    if (sppdTableExists($pdo, 'stocks_purchase_orders')) {
        $amountExpr = sppdPurchaseOrderAmountExpr('po');
        $unpaid = sppdUnpaidPurchaseOrderWhereSql($pdo, 'po');
        $overdue = sppdOverduePayableConditionSql($pdo, 'po');
        $paidBySql = sppdPaidBySelectSql($pdo);
        $amountPaidSql = sppdAmountPaidSelectSql($pdo);
        $paymentStatusSql = sppdPurchaseOrderPaymentStatusSelectSql($pdo, 'po');

        $sql = '
            SELECT po.id, po.po_number, po.created_at, po.currency, po.status, ' . $paymentStatusSql . ',
                   ' . $amountExpr . ' as total_amount,
                   ss.name as payee_name,
                   ' . $paidBySql . ',
                   ' . $amountPaidSql . '
            FROM stocks_purchase_orders po
            LEFT JOIN stocks_suppliers ss ON po.supplier_id = ss.id
            WHERE ' . $unpaid . ' AND (' . $overdue . ')
            ORDER BY po.created_at ASC, po.id ASC
            LIMIT 500
        ';

        try {
            $stmt = $pdo->query($sql);
            $modernRows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            $modernRows = [];
        }
    }

    $legacyRows = [];
    if (sppdTableExists($pdo, 'purchases')) {
        $legacyAmountExpr = "COALESCE(NULLIF(p.total_amount, 0), (
            SELECT COALESCE(SUM(pi.quantity * pi.unit_price), 0)
            FROM purchase_items pi
            WHERE pi.purchase_id = p.id
        ), 0)";

        $sql = "
            SELECT p.id, p.purchase_no AS po_number, p.created_at, p.currency, p.status,
                   (
                       SELECT CASE WHEN COUNT(*) > 0 THEN 'paid' ELSE 'unpaid' END
                       FROM payment_vouchers pv
                       WHERE pv.linked_stock_po_id = p.id AND pv.is_paid = 1
                   ) AS payment_status,
                   " . $legacyAmountExpr . " AS total_amount,
                   ss.name AS payee_name,
                   '' AS paid_by_name,
                   (
                       SELECT COALESCE(SUM(pv.total_amount), 0)
                       FROM payment_vouchers pv
                       WHERE pv.linked_stock_po_id = p.id AND pv.is_paid = 1
                   ) AS amount_paid
            FROM purchases p
            LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id
            WHERE p.status IN ('Approved', 'Received')
              AND p.id NOT IN (
                  SELECT COALESCE(pv.linked_stock_po_id, 0)
                  FROM payment_vouchers pv
                  WHERE pv.is_paid = 1
              )
              AND DATE(p.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY p.created_at ASC, p.id ASC
            LIMIT 500
        ";
        
        try {
            $stmt = $pdo->query($sql);
            $legacyRows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($legacyRows as &$row) {
                $row['id'] = (int)$row['id'] + 1000000;
            }
            unset($row);
        } catch (Throwable $e) {
            $legacyRows = [];
        }
    }

    $merged = array_merge($modernRows, $legacyRows);
    usort($merged, static function (array $a, array $b): int {
        $da = $a['created_at'];
        $db = $b['created_at'];
        $cmp = strcmp((string)$da, (string)$db);
        if ($cmp !== 0) {
            return $cmp;
        }
        return (int)$a['id'] - (int)$b['id'];
    });

    return array_slice($merged, 0, 500);
}

/**
 * @return array<string, mixed>
 */
function sppdBuildSummaryTraces(PDO $pdo): array
{
    $unpaidRows = sppdFetchUnpaidPurchaseOrderTraceRows($pdo);
    $overdueRows = sppdFetchOverduePurchaseOrderTraceRows($pdo);
    $accountsPayable = sppdFetchAccountsPayableBalance($pdo);
    $overdueTotals = sppdFetchOverduePayables($pdo);
    $overdueRule = sppdDescribeOverdueRule($pdo);
    $unpaidCriteria = sppdDescribeUnpaidCriteria($pdo);

    $unpaidItems = array_map(
        static fn(array $row): array => sppdMapPurchaseOrderTraceItem($row, 1.0, 'Counted as 1 unpaid PO'),
        $unpaidRows
    );

    $overdueItems = array_map(
        static fn(array $row): array => sppdMapPurchaseOrderTraceItem(
            $row,
            (float) ($row['total_amount'] ?? 0),
            'PO total amount included in overdue sum'
        ),
        $overdueRows
    );

    $apItems = [];
    $apCriteria = [
        ['label' => 'Lookup order', 'value' => '1) Balances live A/P account → 2) financial_accounts → 3) unpaid PO estimate'],
    ];
    $apMethod = 'Ledger balance from Accounts Payable account';
    $apSource = 'financial_accounts / balances module';
    $apFootnote = '';

    if (($accountsPayable['source'] ?? '') === 'unpaid_pos') {
        $apMethod = 'Estimated sum of unpaid purchase order totals';
        $apSource = 'stocks_purchase_orders';
        $apCriteria = array_merge($apCriteria, $unpaidCriteria);
        $apCriteria[] = ['label' => 'Amount summed', 'value' => 'PO total amount (not balance due after partial payments)'];
        $apFootnote = 'No Accounts Payable ledger account was found, so this KPI falls back to unpaid PO totals.';
        $apItems = array_map(
            static fn(array $row): array => sppdMapPurchaseOrderTraceItem(
                $row,
                (float) ($row['total_amount'] ?? 0),
                'PO total added to accounts payable estimate'
            ),
            $unpaidRows
        );
    } else {
        $apCriteria[] = ['label' => 'Account', 'value' => trim((string) ($accountsPayable['accountName'] ?? 'Accounts Payable'))];
        if (!empty($accountsPayable['accountId'])) {
            $apCriteria[] = ['label' => 'Account id', 'value' => (string) $accountsPayable['accountId']];
        }
        $apCriteria[] = ['label' => 'Balance field', 'value' => (string) ($accountsPayable['ledgerSource'] ?? 'ledger')];
        $apItems = [[
            'id' => (int) ($accountsPayable['accountId'] ?? 0),
            'poNumber' => '—',
            'payeeName' => trim((string) ($accountsPayable['accountName'] ?? 'Accounts Payable')),
            'createdAt' => '',
            'currency' => (string) ($accountsPayable['currency'] ?? 'TZS'),
            'amountToPay' => (float) ($accountsPayable['balance'] ?? 0),
            'amountPaid' => 0.0,
            'balanceDue' => (float) ($accountsPayable['balance'] ?? 0),
            'paymentStatus' => 'ledger',
            'contribution' => (float) ($accountsPayable['balance'] ?? 0),
            'note' => 'Ledger account balance',
        ]];
    }

    return [
        'unpaidPurchaseOrders' => [
            'title' => 'Unpaid purchase orders',
            'headline' => (string) count($unpaidItems),
            'source' => 'stocks_purchase_orders',
            'method' => 'Count of purchase orders that are not fully paid',
            'criteria' => $unpaidCriteria,
            'items' => $unpaidItems,
            'footnote' => 'Only stock purchase orders are listed. Rows with zero balance due after payments are hidden.',
        ],
        'accountsPayable' => [
            'title' => 'Account payables',
            'headline' => (string) ((float) ($accountsPayable['balance'] ?? 0)),
            'currency' => (string) ($accountsPayable['currency'] ?? 'TZS'),
            'source' => $apSource,
            'method' => $apMethod,
            'criteria' => $apCriteria,
            'items' => $apItems,
            'footnote' => $apFootnote,
        ],
        'overduePayables' => [
            'title' => 'Overdue payables',
            'headline' => (string) ((float) ($overdueTotals['amount'] ?? 0)),
            'currency' => (string) ($overdueTotals['currency'] ?? 'TZS'),
            'source' => 'stocks_purchase_orders',
            'method' => 'Sum of PO totals that are unpaid and overdue',
            'criteria' => array_merge($unpaidCriteria, [
                ['label' => 'Overdue rule', 'value' => $overdueRule['description']],
                ['label' => 'Overdue field', 'value' => $overdueRule['field']],
                ['label' => 'Amount summed', 'value' => 'PO total amount'],
            ]),
            'items' => $overdueItems,
            'footnote' => 'Overdue POs must satisfy both the unpaid filter and the overdue date rule.',
        ],
    ];
}

/**
 * @return array<int, string>
 */
function sppdFetchPayeeOptions(PDO $pdo): array
{
    $options = [];
    try {
        $sups = $pdo->query('SELECT DISTINCT name FROM stocks_suppliers WHERE name IS NOT NULL AND TRIM(name) != \'\' ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $options = array_map('strval', $sups);
    } catch (Throwable $e) {
        $options = [];
    }
    return $options;
}

/**
 * @return array<int, array<string, mixed>>
 */
function sppdFetchFinancialAccounts(): array
{
    if (!function_exists('getBalances')) {
        return [];
    }
    $accounts = getBalances();
    $out = [];
    foreach ($accounts as $acc) {
        $type = strtolower(trim((string) ($acc['type'] ?? '')));
        if (!in_array($type, ['bank', 'cash', 'mobile', 'asset'], true)) {
            continue;
        }

        $name = strtolower(trim((string) ($acc['name'] ?? '')));
        // Filter out parent asset category accounts and Accounts Receivable
        if (strpos($name, 'receivable') !== false || $name === '1000 - assets') {
            continue;
        }

        $balance = isset($acc['live_balance']) ? (float) $acc['live_balance'] : (float) ($acc['current_balance'] ?? 0);
        $out[] = [
            'id' => (int) ($acc['id'] ?? 0),
            'name' => (string) ($acc['name'] ?? 'Account'),
            'currency' => (string) ($acc['currency'] ?? 'TZS'),
            'balance' => $balance,
            'type' => (string) ($acc['type'] ?? ''),
        ];
    }
    return $out;
}

/**
 * @return array<int, string>|null
 */
function sppdAccountTypesForPaymentMethod(string $method): ?array
{
    $normalized = strtolower(trim($method));
    if ($normalized === '') {
        return null;
    }

    if ($normalized === 'cash') {
        return ['cash'];
    }

    if ($normalized === 'mobile money') {
        return ['mobile'];
    }

    if (
        $normalized === 'bank transfer'
        || $normalized === 'rtgs / swift'
        || $normalized === 'cheque'
        || str_contains($normalized, 'bank')
        || str_contains($normalized, 'swift')
        || str_contains($normalized, 'rtgs')
        || str_contains($normalized, 'cheque')
    ) {
        return ['bank'];
    }

    if (str_contains($normalized, 'mobile')) {
        return ['mobile'];
    }

    if (str_contains($normalized, 'cash')) {
        return ['cash'];
    }

    return null;
}

/**
 * @return array<int, string>
 */
function sppdStockRootPathCandidates(): array
{
    $seen = [];
    $add = static function (?string $path) use (&$seen): void {
        if ($path === null || $path === '') {
            return;
        }
        $resolved = realpath($path);
        $path = is_string($resolved) ? $resolved : $path;
        if (!is_dir($path)) {
            return;
        }
        $key = strtolower(str_replace('\\', '/', $path));
        $seen[$key] = $path;
    };

    $moduleRoot = dirname(__DIR__, 3);
    $add($moduleRoot . '/stock');
    $add($moduleRoot . '/ultimate/stock');

    $parent = realpath($moduleRoot . '/..');
    if (is_string($parent)) {
        $add($parent . '/stock');
        $add($parent . '/ultimate/stock');
    }

    foreach ([
        $moduleRoot . '/stock/modules/purchases',
        $moduleRoot . '/ultimate/stock/modules/purchases',
    ] as $purchaseDir) {
        if (is_dir($purchaseDir)) {
            $add(dirname($purchaseDir, 2));
        }
    }

    return array_values($seen);
}

/**
 * @return array{exists:bool,resolved:?string}
 */
function sppdResolveStockRelativePath(string $relativePath): array
{
    $relative = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
    if ($relative === '') {
        return ['exists' => false, 'resolved' => null];
    }

    $candidates = [];
    foreach (sppdStockRootPathCandidates() as $stockRoot) {
        $candidates[] = $stockRoot . '/' . $relative;
    }

    $publicRoots = [dirname(__DIR__, 3)];
    $parent = realpath(dirname(__DIR__, 3) . '/..');
    if (is_string($parent)) {
        $publicRoots[] = $parent;
    }
    foreach ($publicRoots as $publicRoot) {
        $resolvedPublic = realpath($publicRoot);
        if (!is_string($resolvedPublic)) {
            continue;
        }
        $candidates[] = $resolvedPublic . '/' . $relative;
        $candidates[] = $resolvedPublic . '/assets/' . preg_replace('#^uploads/#i', 'uploads/', $relative);
    }

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if (is_string($real) && is_file($real)) {
            return ['exists' => true, 'resolved' => $real];
        }
        if (is_file($candidate)) {
            return ['exists' => true, 'resolved' => $candidate];
        }
    }

    return ['exists' => false, 'resolved' => $candidates[0] ?? null];
}

function sppdViewPoUrl(int $poId): string
{
    $base = function_exists('app_url')
        ? app_url('/stock/modules/purchases/view_po.php?id=')
        : '/stock/modules/purchases/view_po.php?id=';
    return $base . sppdPurchaseOrderDeskRealId($poId);
}

function sppdStockPurchasesUrl(string $path): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $base = function_exists('app_url')
        ? app_url('/stock/modules/purchases/')
        : '/stock/modules/purchases/';

    return $base . $path;
}

function sppdStockAssetUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $base = function_exists('app_url')
        ? app_url('/stock/')
        : '/stock/';

    return $base . $relativePath;
}

function sppdEditPoUrl(int $poId): string
{
    $realId = sppdPurchaseOrderDeskRealId($poId);
    if ($realId <= 0) {
        return '';
    }

    $base = function_exists('app_url')
        ? app_url('/stock/modules/purchases/edit.php?id=')
        : '/stock/modules/purchases/edit.php?id=';
    return $base . $realId;
}

function sppdPurchaseOrderDeskCanEdit(string $paymentStatus, float $balanceDue): bool
{
    if (strtolower(trim($paymentStatus)) === 'paid') {
        return false;
    }

    return $balanceDue > 0.009;
}

function sppdPublicAssetUrl(string $path): string
{
    $path = ltrim($path, '/');
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $proxyBase = function_exists('app_url') ? app_url('/proxy_pdf.php') : '/proxy_pdf.php';

    return $proxyBase . '?file=' . rawurlencode($path);
}

/**
 * @return array<int, array<string, mixed>>
 */
function sppdFetchSupplierPaymentsForPo(PDO $pdo, int $poId, bool $isLegacy = false): array
{
    if ($poId <= 0) {
        return [];
    }

    if ($isLegacy) {
        $vouchers = [];
        if (sppdTableExists($pdo, 'payment_vouchers')) {
            // Fetch paid payment vouchers linked to the legacy PO
            $sql = "
                SELECT pv.id, pv.voucher_no AS payment_number, pv.date_created AS payment_date,
                       pv.total_amount AS amount, pv.currency, 1.0 AS exchange_rate,
                       'Voucher' AS payment_method, '' AS reference_no, pv.swift_document,
                       CASE WHEN pv.is_paid = 1 THEN 'posted' ELSE 'approved' END AS status,
                       pv.created_at, '' AS account_name, '' AS journal_entry_number,
                       pv.description AS transaction_description,
                       pv.prepared_by AS paid_by_name
                FROM payment_vouchers pv
                WHERE pv.linked_stock_po_id = ?
                  AND pv.is_paid = 1
                ORDER BY pv.date_created DESC, pv.id DESC
            ";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$poId]);
                $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $vouchers = [];
            }
        }

        $suppPayments = [];
        if (sppdTableExists($pdo, 'supplier_payments')) {
            $shiftedId = $poId + 1000000;
            $userExpr = sppdTableExists($pdo, 'users') ? sppdResolveUserDisplayExpr($pdo, 'u') : "''";
            $hasAccounts = sppdTableExists($pdo, 'financial_accounts');
            $accountJoin = $hasAccounts ? 'LEFT JOIN financial_accounts fa ON fa.id = sp.bank_or_cash_account_id' : '';
            $accountSelect = $hasAccounts ? 'fa.name AS account_name' : "'' AS account_name";
            $hasJournal = sppdTableExists($pdo, 'erp_journal_entries');
            $journalJoin = $hasJournal ? 'LEFT JOIN erp_journal_entries je ON je.id = sp.journal_entry_id' : '';
            $journalSelect = $hasJournal ? 'je.entry_number AS journal_entry_number' : "'' AS journal_entry_number";
            $hasTransactions = sppdTableExists($pdo, 'account_transactions');
            $transactionJoin = $hasTransactions ? 'LEFT JOIN account_transactions atx ON atx.supplier_payment_id = sp.id' : '';
            $transactionSelect = $hasTransactions ? 'atx.description AS transaction_description' : "'' AS transaction_description";
            $userJoin = sppdTableExists($pdo, 'users') ? 'LEFT JOIN users u ON u.id = sp.created_by' : '';

            $suppSql = "
                SELECT sp.id, sp.payment_number, sp.payment_date, sp.amount, sp.currency, sp.exchange_rate,
                       sp.payment_method, sp.reference_no, sp.swift_document, sp.status, sp.created_at,
                       {$accountSelect}, {$journalSelect}, {$transactionSelect},
                       {$userExpr} AS paid_by_name
                FROM supplier_payments sp
                {$accountJoin}
                {$journalJoin}
                {$transactionJoin}
                {$userJoin}
                WHERE sp.purchase_order_id = ?
                ORDER BY sp.payment_date DESC, sp.id DESC
            ";
            try {
                $stmt = $pdo->prepare($suppSql);
                $stmt->execute([$shiftedId]);
                $suppPayments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $suppPayments = [];
            }
        }

        $merged = array_merge($vouchers, $suppPayments);
        usort($merged, static function ($a, $b) {
            return strcmp((string)$b['payment_date'], (string)$a['payment_date']);
        });
        return $merged;
    }

    if (!sppdTableExists($pdo, 'supplier_payments')) {
        return [];
    }

    $userExpr = sppdTableExists($pdo, 'users')
        ? sppdResolveUserDisplayExpr($pdo, 'u')
        : "''";

    $hasAccounts = sppdTableExists($pdo, 'financial_accounts');
    $accountJoin = $hasAccounts
        ? 'LEFT JOIN financial_accounts fa ON fa.id = sp.bank_or_cash_account_id'
        : '';
    $accountSelect = $hasAccounts ? 'fa.name AS account_name' : "'' AS account_name";

    $hasJournal = sppdTableExists($pdo, 'erp_journal_entries');
    $journalJoin = $hasJournal
        ? 'LEFT JOIN erp_journal_entries je ON je.id = sp.journal_entry_id'
        : '';
    $journalSelect = $hasJournal ? 'je.entry_number AS journal_entry_number' : "'' AS journal_entry_number";

    $hasTransactions = sppdTableExists($pdo, 'account_transactions');
    $transactionJoin = $hasTransactions
        ? 'LEFT JOIN account_transactions atx ON atx.supplier_payment_id = sp.id'
        : '';
    $transactionSelect = $hasTransactions ? 'atx.description AS transaction_description' : "'' AS transaction_description";

    $userJoin = sppdTableExists($pdo, 'users')
        ? 'LEFT JOIN users u ON u.id = sp.created_by'
        : '';

  $sql = "
        SELECT sp.id, sp.payment_number, sp.payment_date, sp.amount, sp.currency, sp.exchange_rate,
               sp.payment_method, sp.reference_no, sp.swift_document, sp.status, sp.created_at,
               {$accountSelect}, {$journalSelect}, {$transactionSelect},
               {$userExpr} AS paid_by_name
        FROM supplier_payments sp
        {$accountJoin}
        {$journalJoin}
        {$transactionJoin}
        {$userJoin}
        WHERE sp.purchase_order_id = ?
        ORDER BY sp.payment_date DESC, sp.id DESC
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$poId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array<string, mixed> $row
 * @param array<int, array<string, mixed>> $payments
 * @return array<string, mixed>
 */
function sppdMapPaymentRecord(array $row): array
{
    $swiftDocument = trim((string) ($row['swift_document'] ?? ''));
    $description = trim((string) ($row['transaction_description'] ?? ''));
    $notes = '';
    if ($description !== '' && preg_match('/\(([^)]+)\)\s*$/', $description, $matches)) {
        $notes = trim((string) ($matches[1] ?? ''));
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'paymentNumber' => (string) ($row['payment_number'] ?? ''),
        'paymentDate' => (string) ($row['payment_date'] ?? ''),
        'amount' => (float) ($row['amount'] ?? 0),
        'currency' => trim((string) ($row['currency'] ?? 'TZS')) ?: 'TZS',
        'exchangeRate' => (float) ($row['exchange_rate'] ?? 1),
        'paymentMethod' => (string) ($row['payment_method'] ?? ''),
        'referenceNo' => (string) ($row['reference_no'] ?? ''),
        'accountName' => (string) ($row['account_name'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'paidByName' => trim((string) ($row['paid_by_name'] ?? '')),
        'notes' => $notes,
        'journalEntryNumber' => (string) ($row['journal_entry_number'] ?? ''),
        'proofUrl' => $swiftDocument !== '' ? sppdPublicAssetUrl($swiftDocument) : '',
        'createdAt' => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function sppdFetchPurchaseOrderDetails(PDO $pdo, int $poId): ?array
{
    if ($poId <= 0) {
        return null;
    }

    $isLegacy = ($poId >= 1000000);
    $realId = $isLegacy ? ($poId - 1000000) : $poId;

    if ($isLegacy) {
        if (!sppdTableExists($pdo, 'purchases')) {
            return null;
        }
        $legacyAmountExpr = "COALESCE(NULLIF(p.total_amount, 0), (
            SELECT COALESCE(SUM(pi.quantity * pi.unit_price), 0)
            FROM purchase_items pi
            WHERE pi.purchase_id = p.id
        ), 0)";

        $sql = "
            SELECT p.id, p.purchase_no AS po_number, p.created_at, p.currency, p.status,
                   (
                       SELECT CASE WHEN COUNT(*) > 0 THEN 'paid' ELSE 'unpaid' END
                       FROM payment_vouchers pv
                       WHERE pv.linked_stock_po_id = p.id AND pv.is_paid = 1
                   ) AS payment_status,
                   " . $legacyAmountExpr . " AS total_amount,
                   ss.name AS payee_name,
                   '' AS paid_by_name,
                   (
                       SELECT COALESCE(SUM(pv.total_amount), 0)
                       FROM payment_vouchers pv
                       WHERE pv.linked_stock_po_id = p.id AND pv.is_paid = 1
                   ) AS amount_paid,
                   " . sppdPurchaseOrderEffectiveDateSql('p', 'purchase_no') . " AS effective_date
            FROM purchases p
            LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id
            WHERE p.id = ?
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$realId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = $poId; // keep shifted ID
    } else {
        if (!sppdTableExists($pdo, 'stocks_purchase_orders')) {
            return null;
        }
        $amountExpr = sppdPurchaseOrderAmountExpr('po');
        $paidBySql = sppdPaidBySelectSql($pdo);
        $amountPaidSql = sppdAmountPaidSelectSql($pdo);
        $paymentStatusSql = sppdPurchaseOrderPaymentStatusSelectSql($pdo, 'po');

        $sql = '
            SELECT po.id, po.po_number, po.created_at, po.currency, po.status, ' . $paymentStatusSql . ',
                   ' . $amountExpr . ' as total_amount,
                   ss.name as payee_name,
                   ' . $paidBySql . ',
                   ' . $amountPaidSql . ',
                   ' . sppdPurchaseOrderEffectiveDateSql('po') . ' AS effective_date
            FROM stocks_purchase_orders po
            LEFT JOIN stocks_suppliers ss ON po.supplier_id = ss.id
            WHERE po.id = ?
            LIMIT 1
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$realId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
    }

    $order = sppdMapPurchaseOrder($row);
    
    // Fetch dynamic description from items
    try {
        if ($isLegacy) {
            $descStmt = $pdo->prepare('
                SELECT DISTINCT pr.name
                FROM purchase_items pi
                JOIN products pr ON pi.product_id = pr.id
                WHERE pi.purchase_id = ?
            ');
        } else {
            $descStmt = $pdo->prepare('
                SELECT DISTINCT i.name
                FROM stocks_po_items pi
                JOIN stocks_items i ON pi.item_id = i.id
                WHERE pi.po_id = ?
            ');
        }
        $descStmt->execute([$realId]);
        $itemNames = $descStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($itemNames)) {
            $order['description'] = implode(', ', $itemNames);
        }
    } catch (Throwable $e) {
        // Fallback to default
    }

    $payments = array_map('sppdMapPaymentRecord', sppdFetchSupplierPaymentsForPo($pdo, $realId, $isLegacy));
    $latestPayment = $payments[0] ?? null;

    if ($latestPayment !== null) {
        $order['paidByName'] = (string) ($latestPayment['paidByName'] ?? $order['paidByName']);
    }

    $attachments = array_map(
        'sppdMapPurchaseAttachment',
        sppdFetchPurchaseOrderAttachments($pdo, $realId, $isLegacy),
    );

    return [
        'order' => $order,
        'payments' => $payments,
        'latestPayment' => $latestPayment,
        'attachments' => $attachments,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function sppdFetchPurchaseOrderAttachments(PDO $pdo, int $realId, bool $isLegacy): array
{
    if ($realId <= 0) {
        return [];
    }

    $attachments = [];
    $invoicePath = '';

    try {
        if ($isLegacy && sppdTableExists($pdo, 'purchases')) {
            $stmt = $pdo->prepare('SELECT invoice_attachment FROM purchases WHERE id = ? LIMIT 1');
            $stmt->execute([$realId]);
            $invoicePath = trim((string) ($stmt->fetchColumn() ?: ''));
        } elseif (sppdTableExists($pdo, 'stocks_purchase_orders')) {
            $stmt = $pdo->prepare('SELECT invoice_attachment FROM stocks_purchase_orders WHERE id = ? LIMIT 1');
            $stmt->execute([$realId]);
            $invoicePath = trim((string) ($stmt->fetchColumn() ?: ''));
        }
    } catch (Throwable $e) {
        $invoicePath = '';
    }

    if ($invoicePath !== '') {
        $attachments[] = [
            'id' => -1,
            'name' => 'Supplier invoice',
            'url' => sppdStockPurchasesUrl('download_invoice.php?id=' . $realId),
            'file_type' => '',
            'file_size' => 0,
            'created_at' => '',
            'kind' => 'invoice',
        ];
    }

    if (!$isLegacy && sppdTableExists($pdo, 'stocks_purchase_attachments')) {
        $fkColumn = 'purchase_id';
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_attachments')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!in_array('purchase_id', $cols, true) && in_array('po_id', $cols, true)) {
                $fkColumn = 'po_id';
            }
        } catch (Throwable $e) {
            $fkColumn = 'purchase_id';
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT id, file_name, file_path, file_type, file_size, created_at
                 FROM stocks_purchase_attachments
                 WHERE {$fkColumn} = ?
                 ORDER BY id DESC",
            );
            $stmt->execute([$realId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $path = trim((string) ($row['file_path'] ?? ''));
                if ($path === '') {
                    continue;
                }
                $attachments[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['file_name'] ?? 'Document'),
                    'url' => sppdStockAssetUrl($path),
                    'file_type' => (string) ($row['file_type'] ?? ''),
                    'file_size' => (int) ($row['file_size'] ?? 0),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'kind' => 'attachment',
                ];
            }
        } catch (Throwable $e) {
            // Ignore attachment query failures and return invoice-only results.
        }
    }

    return $attachments;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function sppdMapPurchaseAttachment(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? 'Document'),
        'url' => (string) ($row['url'] ?? ''),
        'fileType' => (string) ($row['file_type'] ?? ''),
        'fileSize' => (int) ($row['file_size'] ?? 0),
        'createdAt' => (string) ($row['created_at'] ?? ''),
        'kind' => (string) ($row['kind'] ?? 'attachment'),
    ];
}

/**
 * @param array<string, mixed> $row
 */
function sppdIsMappedPurchaseOrderUnpaid(array $order): bool
{
    $paymentStatus = strtolower(trim((string) ($order['paymentStatus'] ?? '')));
    if ($paymentStatus === 'paid') {
        return false;
    }

    $balanceDue = (float) ($order['balanceDue'] ?? 0);
    $amountPaid = (float) ($order['amountPaid'] ?? 0);

    return $balanceDue > 0.009 || ($amountPaid > 0.009 && $paymentStatus === 'partially_paid');
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function sppdMapUnpaidPurchaseOrders(array $rows): array
{
    $orders = [];
    foreach ($rows as $row) {
        $mapped = sppdMapPurchaseOrder($row);
        if (sppdIsMappedPurchaseOrderUnpaid($mapped)) {
            $orders[] = $mapped;
        }
    }

    return $orders;
}

/**
 * @param array<string, mixed> $row
 */
function sppdMapPurchaseOrder(array $row): array
{
    $amount = (float) ($row['total_amount'] ?? 0);
    $amountPaid = (float) ($row['amount_paid'] ?? 0);
    $currency = trim((string) ($row['currency'] ?? 'TZS')) ?: 'TZS';
    $id = (int) ($row['id'] ?? 0);
    $dbStatus = (string) ($row['payment_status'] ?? 'unpaid');
    $paymentStatus = sppdResolvePaymentStatus($amount, $amountPaid, $dbStatus);
    $balanceDue = max(0.0, round($amount - $amountPaid, 2));

    if ($paymentStatus === 'paid') {
        $balanceDue = 0.0;
    }

    $effectiveDate = (string) ($row['effective_date'] ?? '');
    if ($effectiveDate === '') {
        if (preg_match('/^PUR-(\d{8})-/', (string) ($row['po_number'] ?? ''), $matches)) {
            $parsed = DateTime::createFromFormat('Ymd', $matches[1]);
            if ($parsed instanceof DateTime) {
                $effectiveDate = $parsed->format('Y-m-d');
            }
        }
        if ($effectiveDate === '') {
            $effectiveDate = (string) ($row['created_at'] ?? '');
        }
    }

    return [
        'id' => $id,
        'poNumber' => (string) ($row['po_number'] ?? ''),
        'payeeName' => (string) ($row['payee_name'] ?? ''),
        'description' => '',
        'currency' => $currency,
        'amountToPay' => $amount,
        'amountPaid' => round($amountPaid, 2),
        'balanceDue' => $balanceDue,
        'status' => (string) ($row['status'] ?? ''),
        'paymentStatus' => $paymentStatus,
        'paidByName' => trim((string) ($row['paid_by_name'] ?? '')),
        'createdAt' => $effectiveDate,
        'viewUrl' => sppdViewPoUrl($id),
        'editUrl' => sppdPurchaseOrderDeskCanEdit($paymentStatus, $balanceDue)
            ? sppdEditPoUrl($id)
            : '',
        'isLegacyDeskId' => $id >= 1000000,
    ];
}

/**
 * @param array<string, mixed> $data
 * @return array{success: bool, payment_number?: string, message?: string, error?: string}
 */
function sppdPayPurchaseOrder(PDO $pdo, array $data, ?array $uploadedFile = null): array
{
    $poId = (int) ($data['po_id'] ?? $data['voucher_id'] ?? 0);
    $amount = (float) ($data['payment_amount'] ?? 0);
    $bankAccountId = (int) ($data['account_id'] ?? 0);
    $paymentMethod = trim((string) ($data['payment_method'] ?? ''));
    $referenceNo = trim((string) ($data['payment_reference_no'] ?? ''));
    $paymentDate = trim((string) ($data['payment_date'] ?? date('Y-m-d')));
    $notes = trim((string) ($data['payment_notes'] ?? ''));

    if ($poId <= 0 || $amount <= 0 || $bankAccountId <= 0 || $paymentMethod === '') {
        return ['success' => false, 'error' => 'Please fill in all required fields.'];
    }

    if ($uploadedFile === null || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'SWIFT or bank payment proof is required.'];
    }

    try {
        $isLegacy = ($poId >= 1000000);
        $realId = $isLegacy ? ($poId - 1000000) : $poId;

        if ($isLegacy) {
            $stmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ?');
            $stmt->execute([$realId]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($po) {
                $po['po_number'] = $po['purchase_no'];
            }
        } else {
            $stmt = $pdo->prepare('SELECT * FROM stocks_purchase_orders WHERE id = ?');
            $stmt->execute([$realId]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$po) {
            return ['success' => false, 'error' => 'Purchase Order not found.'];
        }

        $totalAmount = sppdPurchaseOrderTotalAmount($po, $pdo);
        $alreadyPaid = sppdSumPaymentsForPo($pdo, $poId);
        $balanceDue = max(0.0, round($totalAmount - $alreadyPaid, 2));

        if ($balanceDue <= 0.009) {
            return ['success' => false, 'error' => 'This Purchase Order has already been paid.'];
        }

        if ($amount > $balanceDue + 0.009) {
            return [
                'success' => false,
                'error' => 'Amount to pay cannot exceed the balance due of ' . number_format($balanceDue, 2) . '.',
            ];
        }

        $stmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE id = ?');
        $stmt->execute([$bankAccountId]);
        $bank = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bank) {
            return ['success' => false, 'error' => 'Selected bank/cash account not found.'];
        }

        $allowedTypes = sppdAccountTypesForPaymentMethod($paymentMethod);
        if ($allowedTypes !== null) {
            $accountType = strtolower(trim((string) ($bank['type'] ?? '')));
            if (!in_array($accountType, $allowedTypes, true)) {
                return ['success' => false, 'error' => 'Selected payment account does not match the payment method.'];
            }
        }

        $uploadDir = dirname(__DIR__, 3) . '/assets/uploads/vouchers/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $ext = pathinfo((string) ($uploadedFile['name'] ?? ''), PATHINFO_EXTENSION);
        $safeName = 'proof_' . time() . '_' . rand(1000, 9999) . ($ext !== '' ? '.' . $ext : '');
        if (!move_uploaded_file((string) $uploadedFile['tmp_name'], $uploadDir . $safeName)) {
            return ['success' => false, 'error' => 'Failed to upload payment proof.'];
        }
        $swiftDocument = 'assets/uploads/vouchers/' . $safeName;

        $pdo->beginTransaction();

        $companyId = (int) (currentCompanyId() ?? 0);
        $poCompanyId = (int) ($po['company_id'] ?? $companyId);
        $userId = (int) ($_SESSION['user_id'] ?? 1);

        $year = date('Y');
        $payCount = (int) $pdo->query("SELECT COUNT(*) FROM supplier_payments WHERE YEAR(payment_date) = $year")->fetchColumn() + 1;
        $paymentNumber = sprintf('PAY-%s-%04d', $year, $payCount);

        if ($referenceNo === '') {
            $referenceNo = (string) ($po['po_number'] ?? '');
        }
        if ($referenceNo === '') {
            $referenceNo = $paymentNumber;
        }

        $newPaidTotal = round($alreadyPaid + $amount, 2);
        $markFullyPaid = $newPaidTotal >= ($totalAmount - 0.009);
        $newPaymentStatus = $markFullyPaid ? 'paid' : 'partially_paid';

        if ($isLegacy) {
            if ($markFullyPaid && sppdTableExists($pdo, 'payment_vouchers')) {
                $stmtVb = $pdo->prepare('UPDATE payment_vouchers SET is_paid = 1, paid_at = NOW(), paid_by = ? WHERE linked_stock_po_id = ? AND is_paid = 0');
                $stmtVb->execute([$userId, $realId]);
            }
        } else {
            if (sppdPurchaseOrderHasPaymentStatus($pdo)) {
                $stmt = $pdo->prepare('UPDATE stocks_purchase_orders SET payment_status = ? WHERE id = ?');
                $stmt->execute([$newPaymentStatus, $realId]);
            }
        }

        $stmt = $pdo->prepare('UPDATE financial_accounts SET current_balance = current_balance - ? WHERE id = ?');
        $stmt->execute([$amount, $bankAccountId]);

        $stmt = $pdo->prepare('
            INSERT INTO supplier_payments
            (company_id, payment_number, supplier_id, purchase_order_id, payment_date, amount, currency, exchange_rate, bank_or_cash_account_id, payment_method, reference_no, swift_document, status, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'posted\', ?, NOW(), NOW())
        ');
        $exchangeRate = (float) ($po['exchange_rate'] ?? 1.0);
        if ($exchangeRate <= 0) {
            $exchangeRate = 1.0;
        }
        $stmt->execute([
            $poCompanyId,
            $paymentNumber,
            $po['supplier_id'],
            $poId,
            $paymentDate,
            $amount,
            $po['currency'] ?: 'USD',
            $exchangeRate,
            $bankAccountId,
            $paymentMethod,
            $referenceNo,
            $swiftDocument,
            $userId,
        ]);
        $supplierPaymentId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('
            INSERT INTO account_transactions
            (company_id, account_id, transaction_date, type, amount, reference_type, reference_id, description, created_by, created_at, supplier_payment_id)
            VALUES (?, ?, ?, \'credit\', ?, \'purchase_order\', ?, ?, ?, NOW(), ?)
        ');
        $desc = 'Supplier Payment: ' . $paymentNumber . ' for PO #' . $po['po_number'];
        if ($notes !== '') {
            $desc .= ' (' . $notes . ')';
        }
        $stmt->execute([
            $poCompanyId,
            $bankAccountId,
            $paymentDate . ' ' . date('H:i:s'),
            $amount,
            $poId,
            $desc,
            $userId,
            $supplierPaymentId,
        ]);

        $debitAccId = 0;
        $stmt = $pdo->prepare("SELECT id FROM erp_accounts WHERE code = '2100' LIMIT 1");
        $stmt->execute();
        $debitAccId = (int) $stmt->fetchColumn();
        if ($debitAccId <= 0) {
            $stmt = $pdo->prepare("SELECT id FROM erp_accounts WHERE code = '2000' LIMIT 1");
            $stmt->execute();
            $debitAccId = (int) $stmt->fetchColumn();
        }
        if ($debitAccId <= 0) {
            $stmt = $pdo->prepare("SELECT id FROM erp_accounts WHERE code = '5001' LIMIT 1");
            $stmt->execute();
            $debitAccId = (int) $stmt->fetchColumn();
        }

        $creditAccId = (int) ($bank['gl_account_id'] ?? 0);
        if ($creditAccId <= 0) {
            $fallbackCode = (strtolower((string) ($bank['type'] ?? '')) === 'bank') ? '1002' : '1001';
            $stmt = $pdo->prepare('SELECT id FROM erp_accounts WHERE code = ? LIMIT 1');
            $stmt->execute([$fallbackCode]);
            $creditAccId = (int) $stmt->fetchColumn();
        }

        if ($debitAccId > 0 && $creditAccId > 0) {
            $jeCount = (int) $pdo->query("SELECT COUNT(*) FROM erp_journal_entries WHERE YEAR(date) = $year")->fetchColumn() + 1;
            $entryNumber = sprintf('JE-%s-%04d', $year, $jeCount);

            $stmt = $pdo->prepare('
                INSERT INTO erp_journal_entries
                (entry_number, date, description, status, created_by, reference)
                VALUES (?, ?, ?, \'posted\', ?, ?)
            ');
            $stmt->execute([
                $entryNumber,
                $paymentDate,
                'Supplier Payment for PO #' . $po['po_number'] . ' via ' . $bank['name'],
                $userId,
                $po['po_number'],
            ]);
            $journalEntryId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO erp_journal_items (journal_id, account_id, debit, credit) VALUES (?, ?, ?, 0)');
            $stmt->execute([$journalEntryId, $debitAccId, $amount]);

            $stmt = $pdo->prepare('INSERT INTO erp_journal_items (journal_id, account_id, debit, credit) VALUES (?, ?, 0, ?)');
            $stmt->execute([$journalEntryId, $creditAccId, $amount]);

            $stmt = $pdo->prepare('UPDATE supplier_payments SET journal_entry_id = ? WHERE id = ?');
            $stmt->execute([$journalEntryId, $supplierPaymentId]);
        }

        $pdo->commit();

        $message = $markFullyPaid
            ? 'Purchase Order payment registered successfully as ' . $paymentNumber . '.'
            : 'Partial payment of ' . number_format($amount, 2) . ' registered as ' . $paymentNumber
                . '. Remaining balance: ' . number_format(max(0, $totalAmount - $newPaidTotal), 2) . '.';

        return [
            'success' => true,
            'payment_number' => $paymentNumber,
            'message' => $message,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('sppdPayPurchaseOrder: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Error: ' . $e->getMessage()];
    }
}
