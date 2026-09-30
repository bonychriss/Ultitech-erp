<?php
/**
 * Ultitech AI Agent  Phase 1 (receivables monitor + daily briefing).
 * Read-only. Every query stays on the signed-in company's database.
 */

declare(strict_types=1);

const AI_AGENT_DUE_SOON_DAYS = 7;
const AI_AGENT_STALLED_DAYS = 3;
const AI_AGENT_LIST_LIMIT = 8;

function aiAgentBootstrap(): void
{
    if (!function_exists('sales_pdo')) {
        $salesFunctions = dirname(__DIR__, 3) . '/modules/sales/functions.php';
        if (is_file($salesFunctions)) {
            require_once $salesFunctions;
        }
    }
}

function aiAgentPageUrl(): string
{
    if (function_exists('company_url')) {
        return company_url('modules/ai-agent/index.php');
    }
    return function_exists('app_url') ? app_url('/modules/ai-agent/index.php') : '/modules/ai-agent/index.php';
}

function aiAgentApiUrl(): string
{
    if (function_exists('company_url')) {
        return company_url('modules/ai-agent/api.php');
    }
    return function_exists('app_url') ? app_url('/modules/ai-agent/api.php') : '/modules/ai-agent/api.php';
}

function aiAgentFirstName(): string
{
    $full = trim((string) ($_SESSION['full_name'] ?? ''));
    if ($full === '') {
        return 'there';
    }
    $parts = preg_split('/\s+/', $full) ?: [];
    $first = trim((string) ($parts[0] ?? ''));
    return $first !== '' ? $first : 'there';
}

function aiAgentGreeting(): string
{
    $hour = (int) date('G');
    if ($hour < 12) {
        $hello = 'Good morning';
    } elseif ($hour < 17) {
        $hello = 'Good afternoon';
    } else {
        $hello = 'Good evening';
    }
    return $hello . ', ' . aiAgentFirstName();
}

function aiAgentFormatMoney(float $amount, string $currency, bool $compact = false): string
{
    $currency = $currency !== '' ? $currency : 'TZS';
    if ($compact && abs($amount) >= 1000000) {
        $millions = $amount / 1000000;
        $text = number_format($millions, 1);
        $text = rtrim(rtrim($text, '0'), '.');
        return $currency . ' ' . $text . 'M';
    }
    return $currency . ' ' . number_format($amount, 0);
}

/**
 * @return array{user_id:int,company_id:int,company_name:string,db:PDO,currency:string,shared:bool,db_name:string}
 */
function aiAgentContext(): array
{
    aiAgentBootstrap();

    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        throw new RuntimeException('Not authorized.');
    }
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('Not authorized.');
    }

    $companyId = (int) (function_exists('currentCompanyId') ? (currentCompanyId() ?? 0) : 0);
    if ($companyId <= 0) {
        throw new RuntimeException('Company context is missing.');
    }

    if (!function_exists('sales_pdo')) {
        throw new RuntimeException('Sales data is not available.');
    }

    $db = sales_pdo();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Company data is not available.');
    }

    $expected = aiAgentCompanyDatabaseName($companyId);
    $actual = '';
    try {
        $actual = trim((string) $db->query('SELECT DATABASE()')->fetchColumn());
    } catch (Throwable $e) {
        $actual = '';
    }
    if ($expected === '' || $actual === '' || strcasecmp($expected, $actual) !== 0) {
        throw new RuntimeException('Company data connection does not match the signed-in company.');
    }

    return [
        'user_id' => $userId,
        'company_id' => $companyId,
        'company_name' => trim((string) ($_SESSION['company_name'] ?? '')),
        'db' => $db,
        'currency' => aiAgentCurrency($db, $companyId),
        'shared' => aiAgentDatabaseIsShared($expected),
        'db_name' => $actual,
    ];
}

function aiAgentCompanyDatabaseName(int $companyId): string
{
    global $control_pdo, $pdo;
    $meta = ($control_pdo instanceof PDO) ? $control_pdo : $pdo;
    if (!$meta instanceof PDO || $companyId <= 0 || !function_exists('tableExists') || !tableExists('companies', $meta)) {
        return '';
    }
    try {
        $stmt = $meta->prepare("SELECT db_name FROM companies WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$companyId]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function aiAgentDatabaseIsShared(string $dbName): bool
{
    if ($dbName === '') {
        return true;
    }
    if (function_exists('isSharedTrialDatabaseName') && isSharedTrialDatabaseName($dbName)) {
        return true;
    }
    global $control_pdo, $pdo;
    $meta = ($control_pdo instanceof PDO) ? $control_pdo : $pdo;
    if (!$meta instanceof PDO || !function_exists('tableExists') || !tableExists('companies', $meta)) {
        return true;
    }
    try {
        $stmt = $meta->prepare("SELECT COUNT(*) FROM companies WHERE db_name = ? AND status = 'active'");
        $stmt->execute([$dbName]);
        return ((int) $stmt->fetchColumn()) > 1;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Company filter for shared databases. Exclusive tenant databases are already one company.
 *
 * @return array{0:string,1:array<int,int>}
 */
function aiAgentScopeSql(PDO $db, string $table, string $alias, int $companyId, bool $shared): array
{
    if (!$shared || $companyId <= 0 || !function_exists('columnExists') || !columnExists($table, 'company_id', $db)) {
        return ['', []];
    }
    $safeAlias = preg_replace('/[^a-z0-9_]/i', '', $alias);
    $col = ($safeAlias !== '' ? $safeAlias . '.' : '') . 'company_id';
    return [' AND ' . $col . ' = ?', [$companyId]];
}

function aiAgentCanSeeAllVouchers(): bool
{
    if (function_exists('isAdmin') && isAdmin()) {
        return true;
    }
    if (function_exists('isFinance') && isFinance()) {
        return true;
    }
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($role, ['manager', 'department_manager', 'owner'], true);
}

function aiAgentCurrency(PDO $db, int $companyId): string
{
    if (!function_exists('tableExists') || !tableExists('sales_settings', $db) || !columnExists('sales_settings', 'default_currency', $db)) {
        return 'TZS';
    }
    try {
        if ($companyId > 0 && columnExists('sales_settings', 'company_id', $db)) {
            $stmt = $db->prepare("SELECT default_currency FROM sales_settings WHERE company_id = ? AND default_currency IS NOT NULL AND default_currency <> '' LIMIT 1");
            $stmt->execute([$companyId]);
            $currency = strtoupper(trim((string) ($stmt->fetchColumn() ?: '')));
            if ($currency !== '') {
                return $currency;
            }
        }
        $currency = strtoupper(trim((string) $db->query("SELECT default_currency FROM sales_settings WHERE default_currency IS NOT NULL AND default_currency <> '' LIMIT 1")->fetchColumn()));
        return $currency !== '' ? $currency : 'TZS';
    } catch (Throwable $e) {
        return 'TZS';
    }
}

function aiAgentInvoicesReadable(PDO $db, bool $shared): array
{
    $missing = [];
    if (!tableExists('invoices', $db)) {
        return ['ok' => false, 'reason' => 'The invoices table is not in this company database.'];
    }
    foreach (['balance_due', 'due_date', 'status', 'total_amount', 'invoice_number', 'customer_id'] as $column) {
        if (!columnExists('invoices', $column, $db)) {
            $missing[] = $column;
        }
    }
    if ($missing !== []) {
        return ['ok' => false, 'reason' => 'Invoice fields are missing: ' . implode(', ', $missing) . '.'];
    }
    if ($shared && !columnExists('invoices', 'company_id', $db)) {
        return ['ok' => false, 'reason' => 'Invoices in this shared database are not tagged with a company, so the agent will not read them.'];
    }
    return ['ok' => true, 'reason' => ''];
}

function aiAgentInvoiceViewUrl(int $invoiceId): string
{
    if ($invoiceId <= 0) {
        return aiAgentInvoicesUrl();
    }
    if (function_exists('sales_module_url')) {
        return sales_module_url('invoices/view.php', ['id' => $invoiceId, 'module' => 'sales']);
    }
    $base = function_exists('company_url')
        ? company_url('modules/sales/invoices/view.php')
        : '/modules/sales/invoices/view.php';
    return $base . (strpos($base, '?') !== false ? '&' : '?') . 'id=' . $invoiceId . '&module=sales';
}

function aiAgentInvoicesUrl(): string
{
    if (function_exists('sales_module_url')) {
        return sales_module_url('invoices/index.php', ['module' => 'sales']);
    }
    return function_exists('company_url') ? company_url('sales/invoices') : '/sales/invoices';
}

function aiAgentVouchersUrl(): string
{
    $path = aiAgentCanSeeAllVouchers() ? 'admin/all-vouchers.php' : 'employee/my-vouchers.php';
    $url = function_exists('company_url') ? company_url($path) : $path;
    return $url . (strpos($url, '?') !== false ? '&' : '?') . 'module=voucher';
}

function aiAgentStockUrl(): string
{
    return function_exists('company_url') ? company_url('stock/dashboard.php') : '/stock/dashboard.php';
}

function aiAgentProcurementUrl(): string
{
    return function_exists('company_url') ? company_url('stock/modules/purchases/index.php') : '/stock/modules/purchases/index.php';
}

/**
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function aiAgentReceivablesSummary(array $ctx): array
{
    $currency = (string) $ctx['currency'];
    $empty = [
        'available' => false,
        'reason' => '',
        'currency' => $currency,
        'outstanding_amount' => null,
        'overdue_amount' => null,
        'due_soon_amount' => null,
        'overdue_count' => null,
        'due_soon_count' => null,
        'within_days' => AI_AGENT_DUE_SOON_DAYS,
    ];

    $readable = aiAgentInvoicesReadable($ctx['db'], (bool) $ctx['shared']);
    if (!$readable['ok']) {
        $empty['reason'] = $readable['reason'];
        return $empty;
    }

    $db = $ctx['db'];
    $scope = aiAgentScopeSql($db, 'invoices', 'i', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $sql = "SELECT
            COALESCE(SUM(CASE WHEN i.status NOT IN ('cancelled','draft') AND i.balance_due > 0.009 THEN i.balance_due ELSE 0 END), 0) AS outstanding_amount,
            COALESCE(SUM(CASE WHEN i.status NOT IN ('cancelled','draft','paid') AND i.balance_due > 0.009 AND ((i.due_date IS NOT NULL AND i.due_date < CURDATE()) OR i.status = 'overdue') THEN i.balance_due ELSE 0 END), 0) AS overdue_amount,
            COALESCE(SUM(CASE WHEN i.status NOT IN ('cancelled','draft','paid') AND i.balance_due > 0.009 AND i.due_date IS NOT NULL AND i.due_date >= CURDATE() AND i.due_date <= DATE_ADD(CURDATE(), INTERVAL " . (int) AI_AGENT_DUE_SOON_DAYS . " DAY) AND i.status <> 'overdue' THEN i.balance_due ELSE 0 END), 0) AS due_soon_amount,
            COALESCE(SUM(CASE WHEN i.status NOT IN ('cancelled','draft','paid') AND i.balance_due > 0.009 AND ((i.due_date IS NOT NULL AND i.due_date < CURDATE()) OR i.status = 'overdue') THEN 1 ELSE 0 END), 0) AS overdue_count,
            COALESCE(SUM(CASE WHEN i.status NOT IN ('cancelled','draft','paid') AND i.balance_due > 0.009 AND i.due_date IS NOT NULL AND i.due_date >= CURDATE() AND i.due_date <= DATE_ADD(CURDATE(), INTERVAL " . (int) AI_AGENT_DUE_SOON_DAYS . " DAY) AND i.status <> 'overdue' THEN 1 ELSE 0 END), 0) AS due_soon_count
        FROM invoices i
        WHERE 1=1" . $scope[0];

    $stmt = $db->prepare($sql);
    $stmt->execute($scope[1]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'available' => true,
        'reason' => '',
        'currency' => $currency,
        'outstanding_amount' => (float) ($row['outstanding_amount'] ?? 0),
        'overdue_amount' => (float) ($row['overdue_amount'] ?? 0),
        'due_soon_amount' => (float) ($row['due_soon_amount'] ?? 0),
        'overdue_count' => (int) ($row['overdue_count'] ?? 0),
        'due_soon_count' => (int) ($row['due_soon_count'] ?? 0),
        'within_days' => AI_AGENT_DUE_SOON_DAYS,
    ];
}

/**
 * @param array<string,mixed> $ctx
 * @return list<array<string,mixed>>
 */
function aiAgentInvoiceRows(array $ctx, string $which, int $limit = AI_AGENT_LIST_LIMIT): array
{
    $readable = aiAgentInvoicesReadable($ctx['db'], (bool) $ctx['shared']);
    if (!$readable['ok']) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $db = $ctx['db'];
    $scope = aiAgentScopeSql($db, 'invoices', 'i', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $customerScope = aiAgentScopeSql($db, 'customers', 'c', (int) $ctx['company_id'], (bool) $ctx['shared']);

    if ($which === 'due_soon') {
        $where = "i.status NOT IN ('cancelled','draft','paid')
            AND i.balance_due > 0.009
            AND i.due_date IS NOT NULL
            AND i.due_date >= CURDATE()
            AND i.due_date <= DATE_ADD(CURDATE(), INTERVAL " . (int) AI_AGENT_DUE_SOON_DAYS . " DAY)
            AND i.status <> 'overdue'";
        $order = 'i.due_date ASC, i.balance_due DESC';
    } elseif ($which === 'month') {
        $where = "i.status <> 'cancelled'
            AND i.invoice_date >= ?
            AND i.invoice_date < ?";
        $order = 'i.invoice_date DESC, i.id DESC';
    } elseif ($which === 'recent') {
        $where = "i.status <> 'cancelled'";
        $order = columnExists('invoices', 'created_at', $db) ? 'i.created_at DESC, i.id DESC' : 'i.id DESC';
    } else {
        $where = "i.status NOT IN ('cancelled','draft','paid')
            AND i.balance_due > 0.009
            AND ((i.due_date IS NOT NULL AND i.due_date < CURDATE()) OR i.status = 'overdue')";
        $order = 'i.due_date ASC, i.balance_due DESC';
    }

    $paidSelect = columnExists('invoices', 'amount_paid', $db) ? 'i.amount_paid' : '0 AS amount_paid';
    $emailSelect = tableExists('customers', $db) && columnExists('customers', 'email', $db) ? 'c.email AS customer_email' : "'' AS customer_email";
    $dateSelect = columnExists('invoices', 'invoice_date', $db) ? 'i.invoice_date' : 'NULL AS invoice_date';
    $createdSelect = columnExists('invoices', 'created_at', $db) ? 'i.created_at' : 'NULL AS created_at';
    $sql = "SELECT i.id, i.invoice_number, {$dateSelect}, {$createdSelect}, i.due_date, i.total_amount, {$paidSelect}, i.balance_due, i.status,
            c.company_name AS customer_name, {$emailSelect},
            DATEDIFF(CURDATE(), i.due_date) AS days_overdue
        FROM invoices i
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE {$where}" . $scope[0] . $customerScope[0] . "
        ORDER BY {$order}
        LIMIT {$limit}";

    $params = [];
    if ($which === 'month') {
        $params[] = date('Y-m-01');
        $params[] = date('Y-m-01', strtotime('first day of next month'));
    }
    foreach ($scope[1] as $param) {
        $params[] = $param;
    }
    foreach ($customerScope[1] as $param) {
        $params[] = $param;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[] = aiAgentMapInvoice($row, (string) $ctx['currency']);
    }
    return $rows;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function aiAgentMapInvoice(array $row, string $currency): array
{
    $id = (int) ($row['id'] ?? 0);
    $days = isset($row['days_overdue']) ? (int) $row['days_overdue'] : 0;
    return [
        'id' => $id,
        'invoice_number' => (string) ($row['invoice_number'] ?? ''),
        'customer_name' => trim((string) ($row['customer_name'] ?? '')) !== '' ? (string) $row['customer_name'] : 'Customer',
        'customer_email' => trim((string) ($row['customer_email'] ?? '')),
        'invoice_date' => (string) ($row['invoice_date'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'due_date' => (string) ($row['due_date'] ?? ''),
        'total_amount' => (float) ($row['total_amount'] ?? 0),
        'amount_paid' => (float) ($row['amount_paid'] ?? 0),
        'balance_due' => (float) ($row['balance_due'] ?? 0),
        'status' => (string) ($row['status'] ?? ''),
        'days_overdue' => $days,
        'currency' => $currency,
        'view_url' => aiAgentInvoiceViewUrl($id),
    ];
}

/**
 * @param array<string,mixed> $ctx
 * @return list<array<string,mixed>>
 */
function aiAgentCustomerReceivables(array $ctx, int $limit = AI_AGENT_LIST_LIMIT): array
{
    $readable = aiAgentInvoicesReadable($ctx['db'], (bool) $ctx['shared']);
    if (!$readable['ok']) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $db = $ctx['db'];
    $scope = aiAgentScopeSql($db, 'invoices', 'i', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $customerScope = aiAgentScopeSql($db, 'customers', 'c', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $sql = "SELECT c.id AS customer_id, c.company_name AS customer_name,
            COUNT(*) AS invoice_count,
            COALESCE(SUM(i.balance_due), 0) AS overdue_amount,
            MAX(DATEDIFF(CURDATE(), i.due_date)) AS oldest_days
        FROM invoices i
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE i.status NOT IN ('cancelled','draft','paid')
          AND i.balance_due > 0.009
          AND ((i.due_date IS NOT NULL AND i.due_date < CURDATE()) OR i.status = 'overdue')"
        . $scope[0] . $customerScope[0] . "
        GROUP BY c.id, c.company_name
        ORDER BY overdue_amount DESC
        LIMIT {$limit}";
    $params = array_merge($scope[1], $customerScope[1]);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'customer_name' => trim((string) ($row['customer_name'] ?? '')) !== '' ? (string) $row['customer_name'] : 'Customer',
            'invoice_count' => (int) ($row['invoice_count'] ?? 0),
            'overdue_amount' => (float) ($row['overdue_amount'] ?? 0),
            'oldest_days' => (int) ($row['oldest_days'] ?? 0),
            'currency' => (string) $ctx['currency'],
        ];
    }
    return $out;
}

/**
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>|null
 */
function aiAgentInvoiceById(array $ctx, int $invoiceId): ?array
{
    if ($invoiceId <= 0) {
        return null;
    }
    $readable = aiAgentInvoicesReadable($ctx['db'], (bool) $ctx['shared']);
    if (!$readable['ok']) {
        return null;
    }
    $db = $ctx['db'];
    $scope = aiAgentScopeSql($db, 'invoices', 'i', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $paidSelect = columnExists('invoices', 'amount_paid', $db) ? 'i.amount_paid' : '0 AS amount_paid';
    $emailSelect = tableExists('customers', $db) && columnExists('customers', 'email', $db) ? 'c.email AS customer_email' : "'' AS customer_email";
    $dateSelect = columnExists('invoices', 'invoice_date', $db) ? 'i.invoice_date' : 'NULL AS invoice_date';
    $sql = "SELECT i.id, i.invoice_number, {$dateSelect}, i.due_date, i.total_amount, {$paidSelect}, i.balance_due, i.status,
            c.company_name AS customer_name, {$emailSelect},
            DATEDIFF(CURDATE(), i.due_date) AS days_overdue
        FROM invoices i
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE i.id = ?" . $scope[0] . ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$invoiceId], $scope[1]));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return aiAgentMapInvoice($row, (string) $ctx['currency']);
}

/**
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function aiAgentMonthReceivables(array $ctx): array
{
    $readable = aiAgentInvoicesReadable($ctx['db'], (bool) $ctx['shared']);
    if (!$readable['ok'] || !columnExists('invoices', 'invoice_date', $ctx['db'])) {
        return ['available' => false, 'reason' => $readable['reason'] !== '' ? $readable['reason'] : 'Invoice date is not available.'];
    }
    $db = $ctx['db'];
    $scope = aiAgentScopeSql($db, 'invoices', 'i', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $start = date('Y-m-01');
    $end = date('Y-m-01', strtotime('first day of next month'));
    $sql = "SELECT COUNT(*) AS invoice_count,
            COALESCE(SUM(i.total_amount), 0) AS invoiced_amount,
            COALESCE(SUM(CASE WHEN i.balance_due > 0.009 THEN i.balance_due ELSE 0 END), 0) AS outstanding_amount
        FROM invoices i
        WHERE i.status <> 'cancelled'
          AND i.invoice_date >= ?
          AND i.invoice_date < ?" . $scope[0];
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$start, $end], $scope[1]));
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'available' => true,
        'reason' => '',
        'period_label' => date('F Y'),
        'invoice_count' => (int) ($row['invoice_count'] ?? 0),
        'invoiced_amount' => (float) ($row['invoiced_amount'] ?? 0),
        'outstanding_amount' => (float) ($row['outstanding_amount'] ?? 0),
        'currency' => (string) $ctx['currency'],
    ];
}

/**
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function aiAgentPendingApprovals(array $ctx): array
{
    $db = $ctx['db'];
    if (!tableExists('payment_vouchers', $db) || !columnExists('payment_vouchers', 'status', $db)) {
        return [
            'available' => false,
            'reason' => 'Payment vouchers are not in this company database.',
            'pending_count' => null,
            'url' => '',
        ];
    }
    $scope = aiAgentScopeSql($db, 'payment_vouchers', 'pv', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $sql = "SELECT COUNT(*) FROM payment_vouchers pv
        WHERE pv.status IN ('pending','confirming')
          AND IFNULL(pv.is_paid, 0) = 0" . $scope[0];
    $params = $scope[1];
    if (!aiAgentCanSeeAllVouchers() && columnExists('payment_vouchers', 'created_by', $db)) {
        $sql .= ' AND pv.created_by = ?';
        $params[] = (int) $ctx['user_id'];
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return [
        'available' => true,
        'reason' => '',
        'pending_count' => (int) ($stmt->fetchColumn() ?: 0),
        'url' => aiAgentVouchersUrl(),
        'scope_label' => aiAgentCanSeeAllVouchers() ? 'company' : 'your vouchers',
    ];
}

/**
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function aiAgentStockAlerts(array $ctx): array
{
    $db = $ctx['db'];
    if (!tableExists('products', $db) || !tableExists('stock', $db) || !columnExists('products', 'reorder_level', $db) || !columnExists('stock', 'quantity', $db)) {
        return [
            'available' => false,
            'reason' => 'Stock quantity and reorder level are not both available in this company database.',
            'low_stock_count' => null,
            'url' => '',
        ];
    }
    $scope = aiAgentScopeSql($db, 'products', 'p', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $sql = "SELECT COUNT(*) FROM products p
        LEFT JOIN stock s ON p.id = s.product_id
        WHERE COALESCE(s.quantity, 0) <= p.reorder_level
          AND COALESCE(s.quantity, 0) > 0" . $scope[0];
    $stmt = $db->prepare($sql);
    $stmt->execute($scope[1]);
    return [
        'available' => true,
        'reason' => '',
        'low_stock_count' => (int) ($stmt->fetchColumn() ?: 0),
        'url' => aiAgentStockUrl(),
        'rule' => 'Quantity is above zero and at or below the product reorder level. This matches the stock dashboard low-stock count.',
    ];
}

/**
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function aiAgentStalledProcurement(array $ctx): array
{
    $db = $ctx['db'];
    $table = '';
    if (tableExists('stocks_purchase_orders', $db) && columnExists('stocks_purchase_orders', 'status', $db) && columnExists('stocks_purchase_orders', 'created_at', $db)) {
        $table = 'stocks_purchase_orders';
    } elseif (tableExists('purchases', $db) && columnExists('purchases', 'status', $db) && columnExists('purchases', 'created_at', $db)) {
        $table = 'purchases';
    }
    if ($table === '') {
        return [
            'available' => false,
            'reason' => 'No purchase-order table is available in this company database.',
            'stalled_count' => null,
            'oldest_wait_days' => null,
            'url' => '',
        ];
    }
    $scope = aiAgentScopeSql($db, $table, 'p', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $sql = "SELECT COUNT(*) AS stalled_count,
            COALESCE(MAX(DATEDIFF(CURDATE(), DATE(p.created_at))), 0) AS oldest_wait_days
        FROM {$table} p
        WHERE p.status IN ('Pending','Pending Approval','Pending Supplier')
          AND p.created_at <= DATE_SUB(NOW(), INTERVAL " . (int) AI_AGENT_STALLED_DAYS . " DAY)" . $scope[0];
    $stmt = $db->prepare($sql);
    $stmt->execute($scope[1]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'available' => true,
        'reason' => '',
        'stalled_count' => (int) ($row['stalled_count'] ?? 0),
        'oldest_wait_days' => (int) ($row['oldest_wait_days'] ?? 0),
        'url' => aiAgentProcurementUrl(),
        'source' => $table,
    ];
}

/**
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function aiAgentBalanceAnomalies(array $ctx): array
{
    $readable = aiAgentInvoicesReadable($ctx['db'], (bool) $ctx['shared']);
    if (!$readable['ok'] || !columnExists('invoices', 'amount_paid', $ctx['db'])) {
        return ['available' => false, 'reason' => 'Invoice paid amounts are not available for an anomaly check.', 'count' => null];
    }
    $db = $ctx['db'];
    $scope = aiAgentScopeSql($db, 'invoices', 'i', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $sql = "SELECT COUNT(*) FROM invoices i
        WHERE i.status <> 'cancelled'
          AND (
            (i.status = 'paid' AND i.balance_due > 0.01)
            OR ABS((i.total_amount - i.amount_paid) - i.balance_due) > 1
          )" . $scope[0];
    $stmt = $db->prepare($sql);
    $stmt->execute($scope[1]);
    return [
        'available' => true,
        'reason' => '',
        'count' => (int) ($stmt->fetchColumn() ?: 0),
        'url' => aiAgentInvoicesUrl(),
    ];
}

/**
 * @param array<string,mixed>|null $ctx
 * @return array<string,mixed>
 */
function aiAgentGenerateDailyBriefing(?array $ctx = null): array
{
    $ctx = $ctx ?? aiAgentContext();
    $summary = aiAgentReceivablesSummary($ctx);
    $oldest = $summary['available'] ? (aiAgentInvoiceRows($ctx, 'overdue', 1)[0] ?? null) : null;
    $customers = $summary['available'] ? aiAgentCustomerReceivables($ctx, 1) : [];
    $approvals = aiAgentPendingApprovals($ctx);
    $stock = aiAgentStockAlerts($ctx);
    $procurement = aiAgentStalledProcurement($ctx);
    $anomalies = aiAgentBalanceAnomalies($ctx);

    $attention = 0;
    if ($summary['available']) {
        $attention += (int) $summary['overdue_count'];
    }
    if ($approvals['available']) {
        $attention += (int) $approvals['pending_count'];
    }
    if ($stock['available']) {
        $attention += (int) $stock['low_stock_count'];
    }
    if ($procurement['available']) {
        $attention += (int) $procurement['stalled_count'];
    }
    if ($anomalies['available'] && (int) $anomalies['count'] > 0) {
        $attention += (int) $anomalies['count'];
    }

    $currency = (string) $ctx['currency'];
    $narrative = aiAgentReceivablesNarrative($summary, $oldest, $customers, $currency);

    return [
        'date' => date('Y-m-d'),
        'date_label' => date('j F Y'),
        'greeting' => aiAgentGreeting(),
        'user_name' => aiAgentFirstName(),
        'company_id' => (int) $ctx['company_id'],
        'company_name' => (string) $ctx['company_name'],
        'currency' => $currency,
        'total_attention_items' => $attention,
        'receivables' => $summary,
        'narrative' => $narrative,
        'oldest_overdue' => $oldest,
        'approvals' => $approvals,
        'stock' => $stock,
        'procurement' => $procurement,
        'anomalies' => $anomalies,
        'urls' => [
            'agent' => aiAgentPageUrl(),
            'invoices' => aiAgentInvoicesUrl(),
            'vouchers' => $approvals['url'] ?? aiAgentVouchersUrl(),
            'stock' => aiAgentStockUrl(),
            'procurement' => aiAgentProcurementUrl(),
        ],
    ];
}

/**
 * @param array<string,mixed> $summary
 * @param array<string,mixed>|null $oldest
 * @param list<array<string,mixed>> $customers
 * @return array{facts:string,analysis:string}
 */
function aiAgentReceivablesNarrative(array $summary, ?array $oldest, array $customers, string $currency): array
{
    if (empty($summary['available'])) {
        return [
            'facts' => 'Receivables figures are unavailable. ' . (string) ($summary['reason'] ?? ''),
            'analysis' => '',
        ];
    }
    $count = (int) $summary['overdue_count'];
    $overdue = (float) $summary['overdue_amount'];
    $outstanding = (float) $summary['outstanding_amount'];
    if ($count <= 0) {
        $facts = 'No customer invoices are overdue. Outstanding receivables are ' . aiAgentFormatMoney($outstanding, $currency) . '.';
        return ['facts' => $facts, 'analysis' => 'Nothing in the overdue list needs a collection follow-up today.'];
    }
    $invoiceWord = $count === 1 ? 'invoice is' : 'invoices are';
    $facts = $count . ' customer ' . $invoiceWord . ' overdue, totaling ' . aiAgentFormatMoney($overdue, $currency, true) . '.';
    if ($oldest && (int) ($oldest['days_overdue'] ?? 0) > 0) {
        $facts .= ' The oldest overdue invoice is ' . (int) $oldest['days_overdue'] . ' days past its due date.';
    }
    $analysis = 'Review the oldest overdue invoices first.';
    $top = $customers[0] ?? null;
    if ($top && $overdue > 0) {
        $share = ((float) $top['overdue_amount'] / $overdue) * 100;
        if ($share >= 50) {
            $analysis = (string) $top['customer_name'] . ' accounts for ' . number_format($share, 0) . '% of the overdue balance. Review that customer first.';
        }
    }
    return ['facts' => $facts, 'analysis' => $analysis];
}

/**
 * @param array<string,mixed> $invoice
 * @return array<string,mixed>
 */
function aiAgentFollowUpDraft(array $invoice, string $companyName): array
{
    $amount = aiAgentFormatMoney((float) $invoice['balance_due'], (string) $invoice['currency']);
    $days = (int) ($invoice['days_overdue'] ?? 0);
    $due = (string) ($invoice['due_date'] ?? '');
    $dueLabel = $due !== '' ? date('j F Y', strtotime($due)) : 'the due date';
    $customer = (string) $invoice['customer_name'];
    $number = (string) $invoice['invoice_number'];
    $sender = trim((string) ($_SESSION['full_name'] ?? ''));
    $lines = [
        'Hello ' . $customer . ',',
        '',
        'This is a reminder that invoice ' . $number . ' for ' . $amount . ' was due on ' . $dueLabel
            . ($days > 0 ? ' and is now ' . $days . ' days overdue.' : '.'),
        '',
        'Please let us know when we can expect payment.',
        '',
        $sender !== '' ? $sender : 'Accounts',
    ];
    if ($companyName !== '') {
        $lines[] = $companyName;
    }
    return [
        'invoice' => $invoice,
        'draft' => implode("\n", $lines),
        'email_on_file' => (string) ($invoice['customer_email'] ?? ''),
        'delivery' => 'not_sent',
        'future' => 'Email and WhatsApp sending are future integrations. This draft is only prepared inside the ERP and is not sent.',
    ];
}

/**
 * @return array<string,mixed>
 */
/**
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function aiAgentRecentInvoiceReply(array $ctx): array
{
    $invoice = aiAgentInvoiceRows($ctx, 'recent', 1)[0] ?? null;
    if ($invoice === null) {
        return [
            'text' => 'No customer invoice has been created for this company.',
            'facts' => '',
            'analysis' => '',
            'invoices' => [],
            'actions' => [['label' => 'Open invoices', 'url' => aiAgentInvoicesUrl()]],
            'follow_up' => null,
        ];
    }
    $when = trim((string) ($invoice['created_at'] ?? ''));
    if ($when === '') {
        $when = trim((string) ($invoice['invoice_date'] ?? ''));
    }
    $stamp = $when !== '' ? strtotime($when) : false;
    $whenLabel = $stamp ? date('j M Y', $stamp) : $when;
    $text = (string) $invoice['invoice_number'] . ' for ' . $invoice['customer_name'] . ' is the most recent invoice';
    if ($whenLabel !== '') {
        $text .= ', dated ' . $whenLabel;
    }
    $text .= ', status ' . (string) $invoice['status'] . ', balance ' . aiAgentFormatMoney((float) $invoice['balance_due'], (string) $ctx['currency']) . '.';
    return [
        'text' => $text,
        'facts' => 'This is the latest customer invoice on the company records. Nothing was changed.',
        'analysis' => '',
        'invoices' => [$invoice],
        'actions' => [['label' => 'View invoice', 'url' => (string) $invoice['view_url']]],
        'follow_up' => null,
    ];
}

function aiAgentAnswer(string $message): array
{
    $ctx = aiAgentContext();
    $q = strtolower(trim($message));
    $q = preg_replace('/\s+/', ' ', $q) ?? '';

    if ($q === '') {
        return aiAgentReplyUnknown();
    }
    if (preg_match('/follow[- ]?up/', $q)) {
        $invoice = null;
        if (preg_match('/\binv[- ]?(\d+)\b/i', $message, $m)) {
            $rows = aiAgentInvoiceRows($ctx, 'overdue', 30);
            foreach ($rows as $row) {
                if (strcasecmp((string) $row['invoice_number'], 'INV-' . $m[1]) === 0 || strcasecmp((string) $row['invoice_number'], $m[0]) === 0) {
                    $invoice = $row;
                    break;
                }
            }
        }
        if ($invoice === null) {
            $invoice = aiAgentInvoiceRows($ctx, 'overdue', 1)[0] ?? null;
        }
        if ($invoice === null) {
            return [
                'text' => 'There is no overdue invoice to draft a follow-up for.',
                'facts' => '',
                'analysis' => '',
                'invoices' => [],
                'actions' => [['label' => 'Review receivables', 'url' => aiAgentInvoicesUrl()]],
                'follow_up' => null,
            ];
        }
        return [
            'text' => (string) $invoice['customer_name'] . ' has invoice ' . $invoice['invoice_number'] . ' of ' . aiAgentFormatMoney((float) $invoice['balance_due'], (string) $ctx['currency']) . ' that is ' . (int) $invoice['days_overdue'] . ' days overdue.',
            'facts' => 'Suggested action: follow up with the customer. The message below is a draft only.',
            'analysis' => '',
            'invoices' => [$invoice],
            'actions' => [['label' => 'View invoice', 'url' => (string) $invoice['view_url']]],
            'follow_up' => aiAgentFollowUpDraft($invoice, (string) $ctx['company_name']),
        ];
    }
    if (preg_match('/created recently|recently created|most recent|latest invoice|newest invoice|last invoice|just created/', $q)) {
        return aiAgentRecentInvoiceReply($ctx);
    }
    if (preg_match('/oldest/', $q)) {
        $invoice = aiAgentInvoiceRows($ctx, 'overdue', 1)[0] ?? null;
        if ($invoice === null) {
            $summary = aiAgentReceivablesSummary($ctx);
            return [
                'text' => empty($summary['available'])
                    ? 'I don\'t have enough ERP data to answer that yet.'
                    : 'No customer invoice is overdue.',
                'facts' => (string) ($summary['reason'] ?? ''),
                'analysis' => '',
                'invoices' => [],
                'actions' => [['label' => 'Review receivables', 'url' => aiAgentInvoicesUrl()]],
                'follow_up' => null,
            ];
        }
        return [
            'text' => (string) $invoice['invoice_number'] . ' for ' . $invoice['customer_name'] . ' is the oldest overdue invoice, ' . (int) $invoice['days_overdue'] . ' days past due, with ' . aiAgentFormatMoney((float) $invoice['balance_due'], (string) $ctx['currency']) . ' still outstanding.',
            'facts' => 'Due date ' . (string) $invoice['due_date'] . '.',
            'analysis' => 'This is the invoice to review first.',
            'invoices' => [$invoice],
            'actions' => [['label' => 'View invoice', 'url' => (string) $invoice['view_url']]],
            'follow_up' => null,
        ];
    }
    if (preg_match('/due soon|due within/', $q)) {
        $rows = aiAgentInvoiceRows($ctx, 'due_soon', AI_AGENT_LIST_LIMIT);
        $summary = aiAgentReceivablesSummary($ctx);
        if (empty($summary['available'])) {
            return aiAgentReplyUnknown();
        }
        $text = (int) $summary['due_soon_count'] === 0
            ? 'No customer invoices are due within the next ' . AI_AGENT_DUE_SOON_DAYS . ' days.'
            : (int) $summary['due_soon_count'] . ' customer invoices are due within ' . AI_AGENT_DUE_SOON_DAYS . ' days, totaling ' . aiAgentFormatMoney((float) $summary['due_soon_amount'], (string) $ctx['currency']) . '.';
        return [
            'text' => $text,
            'facts' => '',
            'analysis' => '',
            'invoices' => $rows,
            'actions' => [['label' => 'Review receivables', 'url' => aiAgentInvoicesUrl()]],
            'follow_up' => null,
        ];
    }
    if (preg_match('/this month|receivables for/', $q)) {
        $month = aiAgentMonthReceivables($ctx);
        if (empty($month['available'])) {
            return aiAgentReplyUnknown();
        }
        return [
            'text' => 'In ' . $month['period_label'] . ', ' . (int) $month['invoice_count'] . ' customer invoices were issued for ' . aiAgentFormatMoney((float) $month['invoiced_amount'], (string) $ctx['currency']) . '. ' . aiAgentFormatMoney((float) $month['outstanding_amount'], (string) $ctx['currency']) . ' of that is still outstanding.',
            'facts' => 'Issued amount and outstanding balance are calculated from invoices dated this month. Cancelled invoices are excluded.',
            'analysis' => '',
            'invoices' => aiAgentInvoiceRows($ctx, 'month', 5),
            'actions' => [['label' => 'Review receivables', 'url' => aiAgentInvoicesUrl()]],
            'follow_up' => null,
        ];
    }
    if (preg_match('/which customers|customers have overdue|who owes|who is overdue/', $q)) {
        $customers = aiAgentCustomerReceivables($ctx, AI_AGENT_LIST_LIMIT);
        $summary = aiAgentReceivablesSummary($ctx);
        if (empty($summary['available'])) {
            return aiAgentReplyUnknown();
        }
        if ($customers === []) {
            return [
                'text' => 'No customers have an overdue invoice.',
                'facts' => '',
                'analysis' => '',
                'invoices' => [],
                'customers' => [],
                'actions' => [['label' => 'Review receivables', 'url' => aiAgentInvoicesUrl()]],
                'follow_up' => null,
            ];
        }
        return [
            'text' => 'The largest overdue balance is ' . $customers[0]['customer_name'] . ' at ' . aiAgentFormatMoney((float) $customers[0]['overdue_amount'], (string) $ctx['currency']) . '.',
            'facts' => 'The list shows the customers with the highest overdue balances in this company.',
            'analysis' => 'Review the largest overdue balance first.',
            'invoices' => [],
            'customers' => $customers,
            'actions' => [['label' => 'Review receivables', 'url' => aiAgentInvoicesUrl()]],
            'follow_up' => null,
        ];
    }
    if (preg_match('/owe us|currently owe|outstanding|how much do customers|how much is outstanding/', $q)) {
        $summary = aiAgentReceivablesSummary($ctx);
        if (empty($summary['available'])) {
            return aiAgentReplyUnknown();
        }
        $overdueCount = (int) $summary['overdue_count'];
        return [
            'text' => 'Customers currently owe ' . aiAgentFormatMoney((float) $summary['outstanding_amount'], (string) $ctx['currency']) . '. Of that, ' . aiAgentFormatMoney((float) $summary['overdue_amount'], (string) $ctx['currency']) . ' is overdue across ' . $overdueCount . ' ' . ($overdueCount === 1 ? 'invoice' : 'invoices') . '.',
            'facts' => 'Outstanding is the sum of invoice balance due, excluding drafts and cancelled invoices.',
            'analysis' => (int) $summary['overdue_count'] > 0 ? 'The overdue portion is the part that needs attention first.' : '',
            'invoices' => [],
            'actions' => [['label' => 'Review receivables', 'url' => aiAgentInvoicesUrl()]],
            'follow_up' => null,
        ];
    }
    if (preg_match('/overdue/', $q)) {
        $summary = aiAgentReceivablesSummary($ctx);
        if (empty($summary['available'])) {
            return aiAgentReplyUnknown();
        }
        $narrative = aiAgentReceivablesNarrative($summary, aiAgentInvoiceRows($ctx, 'overdue', 1)[0] ?? null, aiAgentCustomerReceivables($ctx, 1), (string) $ctx['currency']);
        return [
            'text' => $narrative['facts'],
            'facts' => '',
            'analysis' => $narrative['analysis'],
            'invoices' => aiAgentInvoiceRows($ctx, 'overdue', AI_AGENT_LIST_LIMIT),
            'actions' => [['label' => 'Review receivables', 'url' => aiAgentInvoicesUrl()]],
            'follow_up' => null,
        ];
    }
    if (preg_match('/attention today|needs my attention|today.?s briefing|daily briefing|briefing/', $q)) {
        $briefing = aiAgentGenerateDailyBriefing($ctx);
        return [
            'text' => $briefing['greeting'] . '. You have ' . (int) $briefing['total_attention_items'] . ' items that need attention today.',
            'facts' => (string) ($briefing['narrative']['facts'] ?? ''),
            'analysis' => (string) ($briefing['narrative']['analysis'] ?? ''),
            'invoices' => [],
            'briefing' => $briefing,
            'actions' => [
                ['label' => 'Review receivables', 'url' => aiAgentInvoicesUrl()],
                ['label' => 'Open briefing', 'url' => aiAgentPageUrl()],
            ],
            'follow_up' => null,
        ];
    }

    return aiAgentReplyUnknown();
}

/**
 * @return array<string,mixed>
 */
function aiAgentReplyUnknown(): array
{
    return [
        'text' => 'I could not match that to a company record. Try the latest invoice, overdue invoices, what customers owe, or today\'s briefing.',
        'facts' => '',
        'analysis' => '',
        'invoices' => [],
        'actions' => [],
        'follow_up' => null,
    ];
}

/**
 * @return array<string,mixed>
 */
/**
 * Outstanding and overdue balances for customers whose name matches the signed-in company.
 *
 * @param array<string,mixed> $ctx
 * @return list<array<string,mixed>>
 */
function aiAgentCustomersMatching(array $ctx, string $name, int $limit = 8): array
{
    $readable = aiAgentInvoicesReadable($ctx['db'], (bool) $ctx['shared']);
    $name = trim($name);
    if (!$readable['ok'] || $name === '' || !tableExists('customers', $ctx['db'])) {
        return [];
    }
    $limit = max(1, min(20, $limit));
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name) . '%';
    $db = $ctx['db'];
    $scope = aiAgentScopeSql($db, 'invoices', 'i', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $customerScope = aiAgentScopeSql($db, 'customers', 'c', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $sql = "SELECT c.id AS customer_id, c.company_name AS customer_name,
            COALESCE(SUM(CASE WHEN i.status NOT IN ('cancelled','draft') AND i.balance_due > 0.009 THEN i.balance_due ELSE 0 END), 0) AS outstanding_amount,
            COALESCE(SUM(CASE WHEN i.status NOT IN ('cancelled','draft','paid') AND i.balance_due > 0.009 AND ((i.due_date IS NOT NULL AND i.due_date < CURDATE()) OR i.status = 'overdue') THEN i.balance_due ELSE 0 END), 0) AS overdue_amount,
            COALESCE(SUM(CASE WHEN i.status NOT IN ('cancelled','draft','paid') AND i.balance_due > 0.009 AND ((i.due_date IS NOT NULL AND i.due_date < CURDATE()) OR i.status = 'overdue') THEN 1 ELSE 0 END), 0) AS overdue_count,
            COALESCE(MAX(CASE WHEN i.status NOT IN ('cancelled','draft','paid') AND i.balance_due > 0.009 AND i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date) ELSE 0 END), 0) AS oldest_days
        FROM customers c
        LEFT JOIN invoices i ON i.customer_id = c.id
        WHERE c.company_name LIKE ? ESCAPE '\\\\'" . $customerScope[0] . $scope[0] . "
        GROUP BY c.id, c.company_name
        ORDER BY overdue_amount DESC, outstanding_amount DESC
        LIMIT {$limit}";
    $params = array_merge([$like], $customerScope[1], $scope[1]);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'outstanding_amount' => (float) ($row['outstanding_amount'] ?? 0),
            'overdue_amount' => (float) ($row['overdue_amount'] ?? 0),
            'overdue_count' => (int) ($row['overdue_count'] ?? 0),
            'invoice_count' => (int) ($row['overdue_count'] ?? 0),
            'oldest_days' => (int) ($row['oldest_days'] ?? 0),
            'currency' => (string) $ctx['currency'],
        ];
    }
    return $out;
}

/**
 * @param array<string,mixed> $ctx
 * @return list<array{id:int,name:string}>
 */
function aiAgentFindNamedRows(PDO $db, string $table, string $nameColumn, string $name, array $scope): array
{
    $name = trim($name);
    if ($name === '' || !tableExists($table, $db) || !columnExists($table, $nameColumn, $db)) {
        return [];
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name) . '%';
    $sql = "SELECT id, {$nameColumn} AS name FROM {$table} WHERE {$nameColumn} LIKE ? ESCAPE '\\\\'" . $scope[0] . " ORDER BY {$nameColumn} LIMIT 8";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$like], $scope[1]));
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[] = ['id' => (int) ($row['id'] ?? 0), 'name' => (string) ($row['name'] ?? '')];
    }
    return $rows;
}

/**
 * @param list<array{id:int,name:string}> $rows
 * @return array{id:int,name:string}|null
 */
function aiAgentPickNamedRow(array $rows, string $name): ?array
{
    $wanted = strtolower(trim($name));
    foreach ($rows as $row) {
        if (strtolower($row['name']) === $wanted) {
            return $row;
        }
    }
    return count($rows) === 1 ? $rows[0] : null;
}

/**
 * Create one customer invoice through the sales invoice flow.
 *
 * @param array<string,mixed> $ctx
 * @return array{ok:bool,message:string,invoices?:list<array<string,mixed>>}
 */
function aiAgentCreateCustomerInvoice(array $ctx, string $customerName, float $amount, string $description): array
{
    $customerName = trim($customerName);
    $description = trim($description);
    if ($customerName === '' || $amount <= 0) {
        return ['ok' => false, 'message' => 'I need the customer and the amount before I can create an invoice.'];
    }
    $db = $ctx['db'];
    $customerScope = aiAgentScopeSql($db, 'customers', 'customers', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $customers = aiAgentFindNamedRows($db, 'customers', 'company_name', $customerName, $customerScope);
    $customer = aiAgentPickNamedRow($customers, $customerName);
    if ($customer === null) {
        if ($customers === []) {
            return ['ok' => false, 'message' => 'No customer in this company matches that name.'];
        }
        $names = implode(', ', array_map(static fn (array $row): string => $row['name'], $customers));
        return ['ok' => false, 'message' => 'More than one customer matches. Which one should I use: ' . $names . '?'];
    }
    $items = [];
    $productName = '';
    if ($description !== '') {
        $productScope = aiAgentScopeSql($db, 'products', 'products', (int) $ctx['company_id'], (bool) $ctx['shared']);
        $products = aiAgentFindNamedRows($db, 'products', 'name', $description, $productScope);
        $product = aiAgentPickNamedRow($products, $description);
        if ($product === null && count($products) > 1) {
            $names = implode(', ', array_map(static fn (array $row): string => $row['name'], $products));
            return ['ok' => false, 'message' => 'More than one product matches. Which one should I use: ' . $names . '?'];
        }
        if ($product !== null) {
            $productName = $product['name'];
            $items[] = [
                'product_id' => $product['id'],
                'quantity' => 1,
                'unit_price' => $amount,
                'discount' => 0,
                'description' => $description,
            ];
        }
    }

    require_once dirname(__DIR__, 2) . '/sales/includes/invoice-direct-create.php';
    $today = date('Y-m-d');
    $input = [
        'customer_id' => $customer['id'],
        'invoice_date' => $today,
        'due_date' => date('Y-m-d', strtotime('+30 days')),
        'order_type' => 'spare',
        'currency' => (string) $ctx['currency'],
        'subtotal' => $amount,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'shipping_charges' => 0,
        'total_amount' => $amount,
        'items' => $items,
    ];
    try {
        $created = sales_process_direct_invoice_create($input);
    } catch (Throwable $e) {
        global $pdo;
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('ai-agent create invoice: ' . $e->getMessage());
        $message = $e instanceof RuntimeException && $e->getMessage() !== ''
            ? $e->getMessage()
            : 'I could not create that invoice.';
        return ['ok' => false, 'message' => $message];
    }
    $invoiceId = (int) ($created['invoice_id'] ?? 0);
    $number = '';
    if ($invoiceId > 0) {
        $stmt = $db->prepare('SELECT invoice_number FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $number = (string) $stmt->fetchColumn();
    }
    $label = $number !== '' ? $number : ('invoice ' . $invoiceId);
    $money = aiAgentFormatMoney($amount, (string) $ctx['currency']);
    $message = 'Created ' . $label . ' for ' . $customer['name'] . ' for ' . $money . '.';
    if ($productName !== '') {
        $message = 'Created ' . $label . ' for ' . $customer['name'] . ' for ' . $money . ', product ' . $productName . '.';
    }
    return [
        'ok' => true,
        'message' => $message,
        'invoices' => [[
            'id' => $invoiceId,
            'invoice_number' => $label,
            'customer_name' => $customer['name'],
            'balance_due' => $amount,
            'currency' => (string) $ctx['currency'],
            'view_url' => aiAgentInvoiceViewUrl($invoiceId),
        ]],
    ];
}

/**
 * Invoice ids that are overdue for this company, using the same rules as the receivables list.
 *
 * @param array<string,mixed> $ctx
 * @return list<int>
 */
function aiAgentOverdueInvoiceIds(array $ctx): array
{
    $readable = aiAgentInvoicesReadable($ctx['db'], (bool) $ctx['shared']);
    if (!$readable['ok']) {
        return [];
    }
    $db = $ctx['db'];
    $scope = aiAgentScopeSql($db, 'invoices', 'i', (int) $ctx['company_id'], (bool) $ctx['shared']);
    $sql = "SELECT i.id
        FROM invoices i
        WHERE i.status NOT IN ('cancelled','draft','paid')
            AND i.balance_due > 0.009
            AND ((i.due_date IS NOT NULL AND i.due_date < CURDATE()) OR i.status = 'overdue')"
        . $scope[0] . ' ORDER BY i.id';
    $stmt = $db->prepare($sql);
    $stmt->execute($scope[1]);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * @return list<int>
 */
function aiAgentBellRecipientIds(PDO $db): array
{
    if (!function_exists('tableExists') || !tableExists('users', $db) || !function_exists('columnExists') || !columnExists('users', 'role', $db)) {
        return [];
    }
    $roles = ['admin', 'finance', 'manager', 'department_manager', 'owner'];
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $sql = "SELECT id FROM users WHERE LOWER(role) IN ($placeholders)";
    if (columnExists('users', 'is_active', $db)) {
        $sql .= ' AND is_active = 1';
    }
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($roles);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    } catch (Throwable $e) {
        return [];
    }
}

function aiAgentWriteOverdueNotice(int $userId, int $count, string $link): void
{
    global $pdo;
    if ($userId <= 0 || $count <= 0 || !($pdo instanceof PDO)) {
        return;
    }
    if (function_exists('ensureNotificationsTable')) {
        ensureNotificationsTable();
    }
    $title = $count === 1 ? '1 invoice is overdue' : $count . ' invoices are overdue';
    $message = 'Open receivables to review the overdue customer invoices.';
    $existing = $pdo->prepare(
        "SELECT id FROM system_notifications WHERE user_id = ? AND is_read = 0 AND link LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $existing->execute([$userId, '%#ai-receivables%']);
    $existingId = (int) $existing->fetchColumn();
    if ($existingId > 0) {
        $update = $pdo->prepare(
            'UPDATE system_notifications SET title = ?, message = ?, link = ?, type = ?, created_at = NOW() WHERE id = ? AND user_id = ?'
        );
        $update->execute([$title, $message, $link, 'warning', $existingId, $userId]);
        return;
    }
    $insert = $pdo->prepare(
        'INSERT INTO system_notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute([$userId, $title, $message, $link, 'warning']);
}

/**
 * Once a day, put one overdue card on the notifications list when this company has overdue invoices.
 */
function aiAgentSurfaceNewOverdueAlert(): void
{
    if (!function_exists('isLoggedIn') || !isLoggedIn() || !function_exists('currentCompanyId')) {
        return;
    }
    $companyId = (int) (currentCompanyId() ?? 0);
    if ($companyId <= 0 || !function_exists('getCompanySetting') || !function_exists('setCompanySetting')) {
        return;
    }
    $flag = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'ultitech-overdue-notice-' . $companyId . '-' . date('Y-m-d') . '.flag';
    $handle = @fopen($flag, 'x');
    if ($handle === false) {
        return;
    }
    fclose($handle);

    try {
        $ctx = aiAgentContext();
        $ids = aiAgentOverdueInvoiceIds($ctx);
        $count = count($ids);
        if ($count > 0) {
            $link = aiAgentPageUrl() . '#ai-receivables';
            $me = (int) $ctx['user_id'];
            $recipients = array_values(array_unique(array_filter(array_merge(
                $me > 0 ? [$me] : [],
                aiAgentBellRecipientIds($ctx['db'])
            ))));
            foreach ($recipients as $userId) {
                try {
                    aiAgentWriteOverdueNotice((int) $userId, $count, $link);
                } catch (Throwable $e) {
                    error_log('ai-agent overdue notice for user ' . (int) $userId . ': ' . $e->getMessage());
                }
            }
        }
        $saved = setCompanySetting('ai_agent_known_overdue_ids', json_encode(array_values($ids)));
        if ($saved !== true) {
            @unlink($flag);
        }
    } catch (Throwable $e) {
        @unlink($flag);
        error_log('ai-agent overdue bell: ' . $e->getMessage());
    }
}

function aiAgentAlertPayload(): array
{
    $ctx = aiAgentContext();
    $summary = aiAgentReceivablesSummary($ctx);
    $count = !empty($summary['available']) ? (int) $summary['overdue_count'] : 0;
    return [
        'show' => $count > 0,
        'company_id' => (int) $ctx['company_id'],
        'date' => date('Y-m-d'),
        'overdue_count' => $count,
        'overdue_amount' => !empty($summary['available']) ? (float) $summary['overdue_amount'] : 0,
        'currency' => (string) $ctx['currency'],
        'amount_label' => !empty($summary['available'])
            ? aiAgentFormatMoney((float) $summary['overdue_amount'], (string) $ctx['currency'])
            : '',
        'review_url' => aiAgentPageUrl() . '#ai-receivables',
    ];
}

/**
 * Data for the Laravel + React AI Agent page.
 *
 * @return array<string,mixed>
 */
function aiAgentPagePayload(string $csrf): array
{
    $empty = [
        'ok' => false,
        'loadError' => 'I could not read ERP data for this company.',
        'greeting' => function_exists('aiAgentGreeting') ? aiAgentGreeting() : 'AI Agent',
        'attentionLabel' => '',
        'currency' => 'TZS',
        'summary' => [],
        'metrics' => [],
        'facts' => '',
        'analysis' => '',
        'overdue' => [],
        'dueSoon' => [],
        'withinDays' => 7,
        'dateLabel' => date('j F Y'),
        'blocks' => [],
        'anomaly' => null,
        'urls' => [],
        'apiUrl' => function_exists('aiAgentApiUrl') ? aiAgentApiUrl() : '',
        'csrf' => $csrf,
    ];
    try {
        $ctx = aiAgentContext();
        $briefing = aiAgentGenerateDailyBriefing($ctx);
        $currency = (string) ($briefing['currency'] ?? 'TZS');
        $summary = is_array($briefing['receivables'] ?? null) ? $briefing['receivables'] : [];
        $attention = (int) ($briefing['total_attention_items'] ?? 0);
        $available = !empty($summary['available']);
        $metric = static function (string $key) use ($summary, $currency, $available): string {
            if (!$available || !isset($summary[$key]) || $summary[$key] === null) {
                return 'Unavailable';
            }
            return aiAgentFormatMoney((float) $summary[$key], $currency);
        };
        $overdueCount = (int) ($summary['overdue_count'] ?? 0);
        $blocks = [
            [
                'tone' => 'urgent',
                'title' => 'Receivables',
                'show' => $available,
                'lines' => $available
                    ? [
                        $overdueCount . ' customer ' . ($overdueCount === 1 ? 'invoice is' : 'invoices are') . ' overdue',
                        aiAgentFormatMoney((float) ($summary['overdue_amount'] ?? 0), $currency, true) . ' overdue',
                    ]
                    : [(string) ($summary['reason'] ?? '')],
                'url' => (string) ($briefing['urls']['invoices'] ?? '#'),
                'label' => 'Review receivables',
            ],
            [
                'tone' => 'attention',
                'title' => 'Approvals',
                'show' => !empty($briefing['approvals']['available']),
                'lines' => !empty($briefing['approvals']['available'])
                    ? [((int) $briefing['approvals']['pending_count']) . ' payment ' . ((int) $briefing['approvals']['pending_count'] === 1 ? 'voucher is' : 'vouchers are') . ' waiting for approval']
                    : [(string) ($briefing['approvals']['reason'] ?? 'Unavailable')],
                'url' => (string) ($briefing['approvals']['url'] ?? '#'),
                'label' => 'Review approvals',
            ],
            [
                'tone' => 'warn',
                'title' => 'Stock',
                'show' => !empty($briefing['stock']['available']),
                'lines' => !empty($briefing['stock']['available'])
                    ? [((int) $briefing['stock']['low_stock_count']) . ((int) $briefing['stock']['low_stock_count'] === 1 ? ' product is' : ' products are') . ' below the minimum level']
                    : [(string) ($briefing['stock']['reason'] ?? 'Unavailable')],
                'url' => (string) ($briefing['stock']['url'] ?? '#'),
                'label' => 'View stock',
            ],
            [
                'tone' => 'info',
                'title' => 'Procurement',
                'show' => !empty($briefing['procurement']['available']),
                'lines' => !empty($briefing['procurement']['available'])
                    ? [((int) $briefing['procurement']['stalled_count']) . ((int) $briefing['procurement']['stalled_count'] === 1 ? ' request has' : ' requests have') . ' been waiting'
                        . ((int) ($briefing['procurement']['oldest_wait_days'] ?? 0) > 0
                            ? ', the oldest for ' . (int) $briefing['procurement']['oldest_wait_days'] . ' days'
                            : '')]
                    : [(string) ($briefing['procurement']['reason'] ?? 'Unavailable')],
                'url' => (string) ($briefing['procurement']['url'] ?? '#'),
                'label' => 'Review procurement',
            ],
        ];
        $anomaly = null;
        if (!empty($briefing['anomalies']['available']) && (int) ($briefing['anomalies']['count'] ?? 0) > 0) {
            $anomaly = [
                'count' => (int) $briefing['anomalies']['count'],
                'url' => (string) ($briefing['anomalies']['url'] ?? '#'),
            ];
        }
        $mapInvoice = static function (array $invoice) use ($currency): array {
            return [
                'id' => (int) ($invoice['id'] ?? 0),
                'customer_name' => (string) ($invoice['customer_name'] ?? ''),
                'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
                'amount' => aiAgentFormatMoney((float) ($invoice['balance_due'] ?? 0), $currency),
                'days_overdue' => (int) ($invoice['days_overdue'] ?? 0),
                'due_date' => (string) ($invoice['due_date'] ?? ''),
                'view_url' => (string) ($invoice['view_url'] ?? '#'),
            ];
        };

        return [
            'ok' => true,
            'loadError' => '',
            'greeting' => (string) ($briefing['greeting'] ?? aiAgentGreeting()),
            'attentionLabel' => $attention === 1 ? '1 item needs attention today.' : $attention . ' items need attention today.',
            'currency' => $currency,
            'summaryAvailable' => $available,
            'summaryReason' => (string) ($summary['reason'] ?? ''),
            'metrics' => [
                ['key' => 'outstanding', 'label' => 'Outstanding', 'value' => $metric('outstanding_amount'), 'tone' => ''],
                ['key' => 'overdue', 'label' => 'Overdue', 'value' => $metric('overdue_amount'), 'tone' => 'urgent'],
                ['key' => 'due_soon', 'label' => 'Due soon', 'value' => $metric('due_soon_amount'), 'tone' => 'soon', 'hint' => 'Next ' . (int) ($summary['within_days'] ?? 7) . ' days'],
                ['key' => 'count', 'label' => 'Overdue invoices', 'value' => $available ? (string) $overdueCount : 'Unavailable', 'tone' => ''],
            ],
            'facts' => (string) ($briefing['narrative']['facts'] ?? ''),
            'analysis' => (string) ($briefing['narrative']['analysis'] ?? ''),
            'overdue' => array_map($mapInvoice, aiAgentInvoiceRows($ctx, 'overdue', 8)),
            'dueSoon' => array_map($mapInvoice, aiAgentInvoiceRows($ctx, 'due_soon', 5)),
            'withinDays' => (int) ($summary['within_days'] ?? 7),
            'dateLabel' => (string) ($briefing['date_label'] ?? date('j F Y')),
            'blocks' => $blocks,
            'anomaly' => $anomaly,
            'urls' => is_array($briefing['urls'] ?? null) ? $briefing['urls'] : [],
            'apiUrl' => aiAgentApiUrl(),
            'csrf' => $csrf,
        ];
    } catch (Throwable $e) {
        error_log('ai-agent page payload: ' . $e->getMessage());
        return $empty;
    }
}
