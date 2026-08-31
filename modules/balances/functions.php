<?php
// modules/balances/functions.php

/**
 * PDO that has financial_accounts (tenant / revenue / sales DB on production).
 */
function balances_connection_has_financial_accounts($conn)
{
    if (!($conn instanceof PDO)) {
        return false;
    }
    try {
        if (function_exists('tableExists')) {
            return tableExists('financial_accounts', $conn);
        }
        $chk = $conn->query("SHOW TABLES LIKE 'financial_accounts'");
        return (bool) ($chk && $chk->fetch(PDO::FETCH_NUM));
    } catch (Throwable $e) {
        return false;
    }
}

function balances_connection_account_count($conn): int
{
    if (!balances_connection_has_financial_accounts($conn)) {
        return -1;
    }
    try {
        return (int) $conn->query('SELECT COUNT(*) FROM financial_accounts')->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
}

/**
 * @param array<int, PDO> $connections
 */
function balances_pick_best_pdo(array $connections): ?PDO
{
    global $pdo, $control_pdo;

    // Company tenant DB is already isolated — never jump to another company's DATA_DB.
    if (defined('IS_TENANT_DB') && IS_TENANT_DB && $pdo instanceof PDO) {
        return $pdo;
    }

    $preferredDb = '';
    try {
        $cid = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
        if ($cid > 0 && $control_pdo instanceof PDO) {
            $st = $control_pdo->prepare('SELECT db_name FROM companies WHERE id = ? LIMIT 1');
            $st->execute([$cid]);
            $preferredDb = trim((string) ($st->fetchColumn() ?: ''));
        }
        if ($preferredDb === '' && $pdo instanceof PDO) {
            $preferredDb = trim((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        }
    } catch (Throwable $e) {
        $preferredDb = '';
    }

    if ($preferredDb !== '') {
        foreach ($connections as $conn) {
            if (!($conn instanceof PDO)) {
                continue;
            }
            try {
                $dbName = trim((string) $conn->query('SELECT DATABASE()')->fetchColumn());
                if ($dbName !== '' && strcasecmp($dbName, $preferredDb) === 0) {
                    return $conn;
                }
            } catch (Throwable $e) {
                // try next
            }
        }
    }

    $best = null;
    $bestCount = -1;
    foreach ($connections as $conn) {
        if (!($conn instanceof PDO)) {
            continue;
        }
        $count = balances_connection_account_count($conn);
        if ($count > $bestCount) {
            $bestCount = $count;
            $best = $conn;
        }
    }

    return $best;
}

/**
 * @return array<int, PDO>
 */
function balances_collect_pdo_candidates(): array
{
    global $pdo, $control_pdo;

    $try = [];
    $seen = [];

    $add = static function ($conn) use (&$try, &$seen): void {
        if (!($conn instanceof PDO)) {
            return;
        }
        $id = spl_object_id($conn);
        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        $try[] = $conn;
    };

    if ($control_pdo instanceof PDO && function_exists('currentCompanyId') && function_exists('connectToTenantDatabase')) {
        try {
            $cid = (int) (currentCompanyId() ?? 0);
            if ($cid > 0 && function_exists('columnExists') && columnExists('companies', 'db_name', $control_pdo)) {
                $st = $control_pdo->prepare('SELECT db_name FROM companies WHERE id = ? LIMIT 1');
                $st->execute([$cid]);
                $rowDb = trim((string) ($st->fetchColumn() ?: ''));
                if ($rowDb !== '') {
                    $host = defined('DB_HOST') ? DB_HOST : 'localhost';
                    $add(connectToTenantDatabase($rowDb, $host));
                }
            }
        } catch (Throwable $e) {
        }
    }

    if (isset($GLOBALS['tenant_pdo']) && $GLOBALS['tenant_pdo'] instanceof PDO) {
        $add($GLOBALS['tenant_pdo']);
    }
    if ($pdo instanceof PDO) {
        $add($pdo);
    }

    if (is_file(__DIR__ . '/../../includes/revenue_sync.php')) {
        require_once __DIR__ . '/../../includes/revenue_sync.php';
    }
    if (function_exists('revenue_resolve_pdo')) {
        $rev = revenue_resolve_pdo();
        $add($rev);
    }

    $dbNames = [];
    if (defined('DATA_DB_NAME') && trim((string) DATA_DB_NAME) !== '') {
        $dbNames[] = trim((string) DATA_DB_NAME);
    }
    if (defined('SALES_DB_NAME') && trim((string) SALES_DB_NAME) !== '') {
        $dbNames[] = trim((string) SALES_DB_NAME);
    }
    if ($control_pdo instanceof PDO && function_exists('currentCompanyId')) {
        try {
            $cid = (int) (currentCompanyId() ?? 0);
            if ($cid > 0) {
                $st = $control_pdo->prepare('SELECT db_name FROM companies WHERE id = ? LIMIT 1');
                $st->execute([$cid]);
                $rowDb = trim((string) ($st->fetchColumn() ?: ''));
                if ($rowDb !== '') {
                    $dbNames[] = $rowDb;
                }
            }
        } catch (Throwable $e) {
        }
    }
    $dbNames[] = 'new_trading_voucher-35313030c7e2';

    if (function_exists('connectToTenantDatabase')) {
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        foreach (array_values(array_unique(array_filter($dbNames))) as $dbName) {
            $add(connectToTenantDatabase($dbName, $host));
        }
    }

    if ($control_pdo instanceof PDO) {
        $add($control_pdo);
    }

    return $try;
}

function balances_resolve_pdo()
{
    static $cached = null;
    if ($cached instanceof PDO) {
        return $cached;
    }

    $candidates = balances_collect_pdo_candidates();
    $best = balances_pick_best_pdo($candidates);
    if ($best instanceof PDO) {
        $cached = $best;
        return $cached;
    }

    global $pdo, $control_pdo;
    $cached = ($pdo instanceof PDO) ? $pdo : (($control_pdo instanceof PDO) ? $control_pdo : null);

    return $cached;
}

/**
 * Bank/cash accounts with live balances for Chart of Accounts and payment pickers.
 *
 * @return array<int, array<string, mixed>>
 */
function balancesFetchAccountsWithLiveBalance(PDO $pdo, bool $activeOnly = false): array
{
    try {
        $scoped = balancesUseCompanyScope($pdo);
        $companyId = (int) (currentCompanyId() ?? 0);
        $where = [];
        $params = [];

        if ($activeOnly) {
            $where[] = "fa.status = 'active'";
        }
        if ($scoped && $companyId > 0) {
            $where[] = '(fa.company_id IS NULL OR fa.company_id = 0 OR fa.company_id = ?)';
            $params[] = $companyId;
        }

        $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "
            SELECT
                fa.*,
                COALESCE(fa.opening_balance, 0) AS opening_balance_safe,
                COALESCE(tx.total_credits, 0) AS tx_credits,
                COALESCE(tx.total_debits, 0) AS tx_debits,
                (COALESCE(fa.opening_balance, 0) + COALESCE(tx.total_credits, 0) - COALESCE(tx.total_debits, 0)) AS live_balance
            FROM financial_accounts fa
            LEFT JOIN (
                SELECT
                    account_id,
                    SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS total_credits,
                    SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS total_debits
                FROM account_transactions
                GROUP BY account_id
            ) tx ON tx.account_id = fa.id
            {$whereSql}
            ORDER BY fa.type, fa.name
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('balancesFetchAccountsWithLiveBalance: ' . $e->getMessage());
        return [];
    }
}

/**
 * SQL WHERE fragment + params for company-scoped financial_accounts queries.
 *
 * @return array{0:string,1:array<int,mixed>}
 */
function balances_accounts_company_filter_sql(string $alias = '', bool $includeShared = true, ?PDO $pdo = null): array
{
    $prefix = $alias !== '' ? ($alias . '.') : '';
    if (!function_exists('balancesUseCompanyScope') || !balancesUseCompanyScope($pdo)) {
        return ['', []];
    }
    $companyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
    if ($companyId <= 0) {
        return ['', []];
    }

    if ($includeShared) {
        return [
            '(' . $prefix . 'company_id IS NULL OR ' . $prefix . 'company_id = 0 OR ' . $prefix . 'company_id = ?)',
            [$companyId],
        ];
    }

    return [$prefix . 'company_id = ?', [$companyId]];
}

/**
 * Ensure sub-accounts are included even when the live-balance query omits them.
 *
 * @param array<int, array<string, mixed>> $accounts
 * @return array<int, array<string, mixed>>
 */
function balances_merge_missing_sub_accounts(PDO $pdo, array $accounts, bool $activeOnly = true): array
{
    coa_ensure_parent_id_column($pdo);
    if (!function_exists('columnExists') || !columnExists('financial_accounts', 'parent_id', $pdo)) {
        return $accounts;
    }

    $byId = [];
    foreach ($accounts as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $row;
        }
    }

    $where = ['parent_id IS NOT NULL', 'parent_id > 0'];
    $params = [];
    if ($activeOnly) {
        $where[] = "status = 'active'";
    }
    [$companySql, $companyParams] = balances_accounts_company_filter_sql('', true, $pdo);
    if ($companySql !== '') {
        $where[] = $companySql;
        $params = array_merge($params, $companyParams);
    }

    try {
        $sql = 'SELECT * FROM financial_accounts WHERE ' . implode(' AND ', $where) . ' ORDER BY name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return array_values($byId);
    }

    foreach ($subs as $sub) {
        $id = (int) ($sub['id'] ?? 0);
        if ($id <= 0 || isset($byId[$id])) {
            continue;
        }
        $opening = (float) ($sub['opening_balance'] ?? 0);
        $byId[$id] = array_merge($sub, [
            'opening_balance_safe' => $opening,
            'live_balance' => (float) ($sub['current_balance'] ?? $opening),
        ]);
    }

    return array_values($byId);
}

/**
 * Count active sub-accounts for a parent (direct DB, not dependent on live fetch).
 */
function balances_count_child_accounts(PDO $pdo, int $parentId, bool $activeOnly = true): int
{
    if ($parentId <= 0 || !function_exists('columnExists') || !columnExists('financial_accounts', 'parent_id', $pdo)) {
        return 0;
    }

    $where = ['parent_id = ?'];
    $params = [$parentId];
    if ($activeOnly) {
        $where[] = "status = 'active'";
    }
    [$companySql, $companyParams] = balances_accounts_company_filter_sql('', true, $pdo);
    if ($companySql !== '') {
        $where[] = $companySql;
        $params = array_merge($params, $companyParams);
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM financial_accounts WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Build redirect URL for accounts.php preserving company slug when present.
 */
function balances_accounts_redirect_url(array $query = []): string
{
    $qs = http_build_query(array_filter($query, static function ($value) {
        return $value !== null && $value !== '';
    }));
    $path = 'accounts.php' . ($qs !== '' ? '?' . $qs : '');

    // If we are currently running accounts.php or inside the modules/balances folder,
    // return a relative URL to avoid domain/port/session cookie scope mismatches.
    $currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($currentScript, '/modules/balances/') !== false || basename($currentScript) === 'accounts.php') {
        return $path;
    }

    $slug = '';
    if (function_exists('getRequestedCompanySlug')) {
        $slug = strtolower(trim((string) getRequestedCompanySlug()));
    }
    if ($slug === '' && !empty($_SESSION['company_slug'])) {
        $slug = strtolower(trim((string) $_SESSION['company_slug']));
    }
    if ($slug !== '' && function_exists('company_url')) {
        return company_url('modules/balances/' . $path);
    }

    return $path;
}

/**
 * @return list<array<string, mixed>>
 */
function balances_fetch_raw_child_accounts(PDO $pdo, int $parentId, bool $activeOnly = true): array
{
    if ($parentId <= 0) {
        return [];
    }

    coa_ensure_parent_id_column($pdo);
    if (!function_exists('columnExists') || !columnExists('financial_accounts', 'parent_id', $pdo)) {
        return [];
    }

    $where = ['parent_id = ?'];
    $params = [$parentId];
    if ($activeOnly) {
        $where[] = "status = 'active'";
    }
    [$companySql, $companyParams] = balances_accounts_company_filter_sql('', true, $pdo);
    if ($companySql !== '') {
        $where[] = $companySql;
        $params = array_merge($params, $companyParams);
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE ' . implode(' AND ', $where) . ' ORDER BY name');
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Format a financial_accounts row for the Chart of Accounts UI.
 *
 * @param array<string, mixed> $acc
 * @return array<string, mixed>
 */
function balances_format_account_row_for_ui(array $acc): array
{
    $status = strtolower((string) ($acc['status'] ?? 'inactive'));
    $balance = isset($acc['live_balance']) ? (float) $acc['live_balance'] : (float) ($acc['current_balance'] ?? 0);

    $nameRaw = (string) ($acc['name'] ?? '');
    $code = '';
    $name = $nameRaw;
    if (preg_match('/^\s*([0-9]{3,10})\s*-\s*(.+)$/', $nameRaw, $m)) {
        $code = trim($m[1]);
        $name = trim($m[2]);
    }

    $typeRaw = strtolower((string) ($acc['type'] ?? ''));
    $typeLabelMap = [
        'asset' => 'Asset',
        'liability' => 'Liability',
        'equity' => 'Equity',
        'revenue' => 'Revenue',
        'expense' => 'Expense',
        'cash' => 'Asset',
        'bank' => 'Asset',
        'mobile' => 'Asset',
    ];
    $typeLabel = $typeLabelMap[$typeRaw] ?? ucfirst(str_replace('_', ' ', $typeRaw));
    $typeLabelDisplay = ucwords(strtolower((string) $typeLabel));
    $typeSlug = strtolower((string) $typeLabel);
    $normalBalance = in_array($typeSlug, ['liability', 'equity', 'revenue'], true) ? 'credit' : 'debit';
    $accountImagePath = trim((string) ($acc['account_image'] ?? ''));
    $accountImageUrl = $accountImagePath !== '' && function_exists('balancesAccountImageUrl')
        ? balancesAccountImageUrl($accountImagePath)
        : '';
    $description = function_exists('coa_account_description_for_code')
        ? coa_account_description_for_code(
            $code !== '' ? $code : '-',
            function_exists('coa_account_type_description') ? coa_account_type_description($typeSlug) : 'Chart of accounts category'
        )
        : (function_exists('coa_account_type_description') ? coa_account_type_description($typeSlug) : 'Chart of accounts category');
    $displayOrder = 999;
    if (function_exists('coa_default_accounts_catalog')) {
        foreach (coa_default_accounts_catalog() as $catalogParent) {
            if ((string) ($catalogParent['code'] ?? '') === $code) {
                $displayOrder = (int) ($catalogParent['display_order'] ?? 999);
                break;
            }
        }
    }

    return [
        'id' => (int) ($acc['id'] ?? 0),
        'code' => $code !== '' ? $code : '-',
        'name' => $name !== '' ? $name : '-',
        'type' => $typeSlug,
        'type_label' => $typeLabelDisplay,
        'description' => $description,
        'display_order' => $displayOrder,
        'parent_id' => (int) ($acc['parent_id'] ?? 0),
        'normal_balance' => $normalBalance,
        'normal_balance_short' => $normalBalance === 'credit' ? 'Cr' : 'Dr',
        'status' => $status === 'active' ? 'active' : 'inactive',
        'currency' => (string) ($acc['currency'] ?? 'TZS'),
        'balance' => $balance,
        'image_url' => $accountImageUrl,
        'is_system' => (
            (int) ($acc['is_system'] ?? 0) === 1
            || (function_exists('coa_account_is_required_system_parent') && coa_account_is_required_system_parent([
                'code' => $code,
                'name' => $name,
                'is_system' => (int) ($acc['is_system'] ?? 0),
            ]))
        ) ? 1 : 0,
        'raw' => $acc,
    ];
}

/**
 * Load sub-accounts for the detail panel directly from the database.
 *
 * @param array<int, array<string, mixed>> $accountRowsById
 * @return list<array<string, mixed>>
 */
function balances_fetch_child_rows_for_parent(PDO $pdo, int $parentId, array $accountRowsById = [], bool $activeOnly = true): array
{
    if ($parentId <= 0) {
        return [];
    }

    $rows = [];
    foreach (balances_fetch_raw_child_accounts($pdo, $parentId, $activeOnly) as $raw) {
        $id = (int) ($raw['id'] ?? 0);
        if ($id > 0 && isset($accountRowsById[$id])) {
            $rows[] = $accountRowsById[$id];
            continue;
        }
        $rows[] = balances_format_account_row_for_ui($raw);
    }

    return $rows;
}

/**
 * Map financial_accounts.type to cash / bank / mobile for dashboard statistics.
 */
function balancesAccountLiquidityBucket(string $type): string
{
    $t = strtolower(trim($type));

    static $cash = ['cash', 'cod', 'revenue', 'expense'];
    static $bank = [
        'bank', 'bank_transfer', 'wire_transfer', 'online_banking', 'cheque',
        'standing_order', 'direct_debit', 'debit_card', 'credit_card', 'prepaid_card',
        'asset', 'liability', 'equity',
        'installment', 'bnpl', 'invoice_postpaid',
    ];
    static $mobile = [
        'mobile', 'digital_wallet', 'qr_code', 'ussd', 'payment_gateway', 'crypto',
    ];

    if (in_array($t, $cash, true)) {
        return 'cash';
    }
    if (in_array($t, $mobile, true)) {
        return 'mobile';
    }
    if (in_array($t, $bank, true)) {
        return 'bank';
    }

    return 'bank';
}

/**
 * Public URL for a stored financial_accounts.account_image path.
 */
function balancesAccountImageUrl(string $storedPath): string
{
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '' || strpos($storedPath, '..') !== false) {
        return '';
    }
    if (function_exists('mediaUrlFromPath')) {
        return mediaUrlFromPath($storedPath, true);
    }
    $root = dirname(__DIR__, 2);
    $diskPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($storedPath, '/'));
    if (!is_file($diskPath)) {
        return '';
    }
    $webPath = ltrim($storedPath, '/');

    return function_exists('app_url') ? app_url('/' . $webPath) : '/' . $webPath;
}

/**
 * Ensure financial_accounts.parent_id exists for sub-account hierarchy.
 */
function coa_ensure_parent_id_column(PDO $pdo): void
{
    if (function_exists('columnExists') && !columnExists('financial_accounts', 'parent_id', $pdo)) {
        $pdo->exec('ALTER TABLE financial_accounts ADD COLUMN parent_id INT NULL DEFAULT NULL AFTER id');
        try {
            $pdo->exec('ALTER TABLE financial_accounts ADD INDEX idx_fa_parent_id (parent_id)');
        } catch (Throwable $e) {
        }
    }
}

/**
 * Fix sub-accounts missing company_id so they appear in company-scoped account lists.
 */
function coa_backfill_sub_account_company_ids(PDO $pdo): void
{
    if (!function_exists('balancesUseCompanyScope') || !balancesUseCompanyScope()) {
        return;
    }
    if (!function_exists('columnExists') || !columnExists('financial_accounts', 'company_id', $pdo)) {
        return;
    }

    coa_ensure_parent_id_column($pdo);

    $sessionCompanyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
    if ($sessionCompanyId <= 0) {
        return;
    }

    try {
        $stmt = $pdo->query(
            'SELECT c.id AS child_id, p.company_id AS parent_company_id
             FROM financial_accounts c
             INNER JOIN financial_accounts p ON p.id = c.parent_id
             WHERE c.parent_id IS NOT NULL AND c.parent_id > 0
               AND (c.company_id IS NULL OR c.company_id = 0)'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($rows === []) {
            return;
        }

        $upd = $pdo->prepare('UPDATE financial_accounts SET company_id = ? WHERE id = ?');
        foreach ($rows as $row) {
            $childId = (int) ($row['child_id'] ?? 0);
            if ($childId <= 0) {
                continue;
            }
            $targetCompanyId = (int) ($row['parent_company_id'] ?? 0);
            if ($targetCompanyId <= 0) {
                $targetCompanyId = $sessionCompanyId;
            }
            $upd->execute([$targetCompanyId, $childId]);
        }
    } catch (Throwable $e) {
    }
}

/**
 * Map stored account type to simplified COA category label.
 */
function coa_account_type_to_category_label(string $dbType): string
{
    $t = strtolower(trim($dbType));
    $map = [
        'asset' => 'Asset',
        'cash' => 'Asset',
        'bank' => 'Asset',
        'mobile' => 'Asset',
        'liability' => 'Liability',
        'equity' => 'Equity',
        'revenue' => 'Revenue',
        'expense' => 'Expense',
    ];

    return $map[$t] ?? 'Asset';
}

/**
 * @return array<string, array{account_type:string,account_category:string,reporting_group:string,financial_statement:string}>
 */
function coa_category_options_map(): array
{
    return [
        'Revenue' => [
            'account_type' => 'revenue',
            'account_category' => 'Revenue',
            'reporting_group' => 'Sales Revenue',
            'financial_statement' => 'Profit & Loss',
        ],
        'Expense' => [
            'account_type' => 'expense',
            'account_category' => 'Operating Expenses',
            'reporting_group' => 'Operating Expenses',
            'financial_statement' => 'Profit & Loss',
        ],
        'Cost of Goods Sold' => [
            'account_type' => 'expense',
            'account_category' => 'Cost of Goods Sold',
            'reporting_group' => 'Cost of Goods Sold',
            'financial_statement' => 'Profit & Loss',
        ],
        'Asset' => [
            'account_type' => 'asset',
            'account_category' => 'Current Assets',
            'reporting_group' => 'Current Assets',
            'financial_statement' => 'Balance Sheet',
        ],
        'Cost of Service' => [
            'account_type' => 'expense',
            'account_category' => 'Operating Expenses',
            'reporting_group' => 'Operating Expenses',
            'financial_statement' => 'Profit & Loss',
        ],
        'Liability' => [
            'account_type' => 'liability',
            'account_category' => 'Current Liabilities',
            'reporting_group' => 'Current Liabilities',
            'financial_statement' => 'Balance Sheet',
        ],
        'Equity' => [
            'account_type' => 'equity',
            'account_category' => 'Equity',
            'reporting_group' => 'Equity',
            'financial_statement' => 'Balance Sheet',
        ],
    ];
}

function coa_account_type_description(string $typeLabel): string
{
    $map = [
        'expense' => 'Account for expenses that impact profit and loss',
        'revenue' => 'Account for income or revenue',
        'asset' => 'Account for assets on the balance sheet',
        'liability' => 'Account for liabilities on the balance sheet',
        'equity' => 'Account for equity on the balance sheet',
        'cost of goods sold' => 'Account for direct costs of goods sold',
        'cost of service' => 'Account for direct costs of services',
    ];

    return $map[strtolower(trim($typeLabel))] ?? 'Chart of accounts category';
}

function coa_parse_account_name_parts($nameRaw)
{
    $nameRaw = trim((string) $nameRaw);
    if (preg_match('/^\s*([0-9]{3,10})\s*-\s*(.+)$/', $nameRaw, $m)) {
        return [trim($m[1]), trim($m[2])];
    }

    return ['', $nameRaw];
}

function coa_normal_balance_side_for_account_type(string $accountType): string
{
    $t = coa_normalize_ledger_type($accountType);

    return in_array($t, ['liability', 'equity', 'revenue'], true) ? 'credit' : 'debit';
}

function coa_normalize_account_display_name(string $name): string
{
    [, $displayName] = coa_parse_account_name_parts($name);

    return strtolower(trim($displayName));
}

/**
 * Find an account with the same display name and debit/credit side (case-insensitive name).
 *
 * @return array{id:int,name:string,type:string}|null
 */
function coa_find_account_by_name_and_balance_side(PDO $pdo, string $displayName, string $balanceSide, ?int $excludeId = null): ?array
{
    $displayName = trim($displayName);
    $balanceSide = strtolower(trim($balanceSide));
    if ($displayName === '' || !in_array($balanceSide, ['debit', 'credit'], true)) {
        return null;
    }

    $wantName = strtolower($displayName);
    $sql = 'SELECT id, name, type FROM financial_accounts';
    $params = [];
    if (function_exists('balancesUseCompanyScope') && balancesUseCompanyScope() && function_exists('currentCompanyId')) {
        $companyId = (int) (currentCompanyId() ?? 0);
        if ($companyId > 0) {
            $sql .= ' WHERE company_id = ?';
            $params[] = $companyId;
        }
    }

    try {
        $stmt = $params === [] ? $pdo->query($sql) : $pdo->prepare($sql);
        if ($params !== []) {
            $stmt->execute($params);
        }
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($excludeId !== null && $id === $excludeId) {
                continue;
            }
            if (coa_normalize_account_display_name((string) ($row['name'] ?? '')) !== $wantName) {
                continue;
            }
            $rowSide = coa_normal_balance_side_for_account_type((string) ($row['type'] ?? ''));
            if ($rowSide === $balanceSide) {
                return [
                    'id' => $id,
                    'name' => (string) ($row['name'] ?? ''),
                    'type' => (string) ($row['type'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) {
    }

    return null;
}

function coa_duplicate_account_message(string $displayName, string $balanceSide): string
{
    $sideLabel = $balanceSide === 'credit' ? 'credit' : 'debit';
    $otherSide = $balanceSide === 'credit' ? 'debit' : 'credit';

    return 'An account named "' . $displayName . '" with a ' . $sideLabel
        . ' balance already exists. You can still create "' . $displayName . '" as a ' . $otherSide . ' account.';
}

function coa_extract_leading_code_from_account_name($name)
{
    if (preg_match('/^\s*(\d{3,10})\s*[-\x{2013}\x{2014}]/u', $name, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/^\s*(\d{3,10})\s*$/', $name, $m)) {
        return (int) $m[1];
    }

    return null;
}

function coa_compute_next_account_code(PDO $pdo, $accountType)
{
    $range = null;
    if (function_exists('balances_account_type_code_range')) {
        $range = balances_account_type_code_range($pdo, (string) $accountType);
    }
    if ($range === null) {
        $t = strtolower((string) $accountType);
        $map = [
            'asset' => [1000, 1999],
            'liability' => [2000, 2999],
            'equity' => [3000, 3999],
            'revenue' => [4000, 4999],
            'expense' => [5000, 5999],
        ];
        $range = $map[$t] ?? [1000, 1999];
    }
    $min = (int) $range[0];
    $max = (int) $range[1];
    $maxFound = $min - 1;
    try {
        $stmt = $pdo->query('SELECT name FROM financial_accounts');
        $names = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($names as $n) {
            $num = coa_extract_leading_code_from_account_name((string) $n);
            if ($num !== null && $num >= $min && $num <= $max && $num > $maxFound) {
                $maxFound = $num;
            }
        }
    } catch (Throwable $e) {
    }
    $next = $maxFound + 1;
    if ($next < $min) {
        $next = $min;
    }

    return (string) $next;
}

/**
 * Allowed wallet types stored on financial_accounts.type for payment sources.
 *
 * @return array<string, string>
 */
function balances_payment_wallet_types(): array
{
    return [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'mobile' => 'Mobile money',
    ];
}

function balances_normalize_payment_wallet_type(?string $value): ?string
{
    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, ['cash', 'bank', 'mobile'], true) ? $normalized : null;
}

/**
 * @return array{code:string,name:string,full:string}
 */
function balances_parent_account_label_parts(array $parent): array
{
    $full = (string) ($parent['name'] ?? '');
    if (function_exists('coa_parse_account_name_parts')) {
        [$code, $nameOnly] = coa_parse_account_name_parts($full);

        return [
            'code' => (string) $code,
            'name' => (string) $nameOnly,
            'full' => $full,
        ];
    }

    return ['code' => '', 'name' => $full, 'full' => $full];
}

/**
 * Whether sub-accounts under this parent should pick an explicit cash/bank/mobile wallet type.
 */
function balances_parent_is_payment_wallet_group(array $parent): bool
{
    $type = strtolower(trim((string) ($parent['type'] ?? '')));
    if (in_array($type, ['cash', 'bank', 'mobile'], true)) {
        return true;
    }

    $parts = balances_parent_account_label_parts($parent);
    $haystack = strtolower($parts['name'] . ' ' . $parts['full']);

    if (preg_match('/receivable|a\/r\b|prepay|inventory|stock|fixed asset|equipment|depreciation|land|building|vehicle/i', $haystack)) {
        return false;
    }

    return (bool) preg_match(
        '/\b(petty\s*cash|cash\s*on\s*hand|undeposited|bank|mobile\s*money|mpesa|m-?pesa|tigo\s*pesa|airtel\s*money|wallet|crdb|nmb|uba|equity\s*bank|stanbic|dtb)\b/i',
        $haystack
    );
}

function balances_infer_payment_wallet_type(array $parent): string
{
    $type = strtolower(trim((string) ($parent['type'] ?? '')));
    if (in_array($type, ['cash', 'bank', 'mobile'], true)) {
        return $type;
    }

    $parts = balances_parent_account_label_parts($parent);
    $haystack = strtolower($parts['name'] . ' ' . $parts['full']);

    if (preg_match('/mobile|mpesa|m-?pesa|tigo|airtel|halotel|wallet/i', $haystack)) {
        return 'mobile';
    }
    if (preg_match('/bank|crdb|nmb|uba|equity\s*bank|stanbic|dtb|barclays/i', $haystack)) {
        return 'bank';
    }
    if (preg_match('/petty|cash\s*on\s*hand|\bcash\b|undeposited/i', $haystack)) {
        return 'cash';
    }

    return 'cash';
}

/**
 * Resolve financial_accounts.type for a wallet sub-account.
 */
function balances_resolve_sub_account_wallet_type(array $parent, ?string $requestedType): ?string
{
    if (!balances_parent_is_payment_wallet_group($parent)) {
        return null;
    }

    $normalized = balances_normalize_payment_wallet_type($requestedType);
    if ($normalized !== null) {
        return $normalized;
    }

    return balances_infer_payment_wallet_type($parent);
}

/**
 * Create a sub-account under a main account (name only; inherits parent category/currency).
 *
 * @return array{success:bool,id?:int,message:string}
 */
function balances_create_sub_account(PDO $pdo, int $parentId, string $accountName, ?string $paymentWalletType = null): array
{
    $accountName = trim($accountName);
    if ($parentId <= 0) {
        return ['success' => false, 'message' => 'Parent account is required.'];
    }
    if ($accountName === '') {
        return ['success' => false, 'message' => 'Sub-account name is required.'];
    }
    if (strlen($accountName) > 100) {
        return ['success' => false, 'message' => 'Sub-account name must be 100 characters or fewer.'];
    }

    coa_ensure_parent_id_column($pdo);

    $parentStmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE id = ? LIMIT 1');
    $parentStmt->execute([$parentId]);
    $parent = $parentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$parent) {
        return ['success' => false, 'message' => 'Parent account not found.'];
    }
    if ((int) ($parent['parent_id'] ?? 0) > 0) {
        return ['success' => false, 'message' => 'Sub-accounts can only be added under a main account.'];
    }

    $categoryLabel = coa_account_type_to_category_label((string) ($parent['type'] ?? ''));
    $categories = coa_category_options_map();
    if (!isset($categories[$categoryLabel])) {
        return ['success' => false, 'message' => 'Could not resolve parent account category.'];
    }

    $meta = $categories[$categoryLabel];
    $accountType = $meta['account_type'];
    $storedType = $accountType;
    if (balances_parent_is_payment_wallet_group($parent)) {
        $walletType = balances_resolve_sub_account_wallet_type($parent, $paymentWalletType);
        if ($walletType === null || balances_normalize_payment_wallet_type($walletType) === null) {
            return ['success' => false, 'message' => 'Please select a payment type (Cash, Bank, or Mobile money).'];
        }
        $storedType = $walletType;
    }
    if (function_exists('coa_ensure_account_category')) {
        coa_ensure_account_category(
            $pdo,
            $meta['account_category'],
            $accountType,
            $meta['reporting_group'],
            $meta['financial_statement']
        );
    }

    $accountCode = coa_compute_next_account_code($pdo, $accountType);
    $currency = strtoupper(trim((string) ($parent['currency'] ?? 'TZS')));

    $balanceSide = coa_normal_balance_side_for_account_type($accountType);
    if (coa_find_account_by_name_and_balance_side($pdo, $accountName, $balanceSide) !== null) {
        return ['success' => false, 'message' => coa_duplicate_account_message($accountName, $balanceSide)];
    }

    $nameToSave = $accountCode . ' - ' . $accountName;

    $insertRow = [
        'name' => $nameToSave,
        'type' => $storedType,
        'currency' => $currency,
        'opening_balance' => 0,
        'current_balance' => 0,
        'status' => 'active',
        'parent_id' => $parentId,
    ];

    if (function_exists('balancesUseCompanyScope') && balancesUseCompanyScope()) {
        $parentCompanyId = (int) ($parent['company_id'] ?? 0);
        $sessionCompanyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
        if ($parentCompanyId > 0) {
            $insertRow['company_id'] = $parentCompanyId;
        } elseif ($sessionCompanyId > 0) {
            $insertRow['company_id'] = $sessionCompanyId;
        }
    }
    $faCols = $pdo->query('SHOW COLUMNS FROM financial_accounts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array('parent_id', $faCols, true)) {
        return ['success' => false, 'message' => 'Sub-accounts are not supported yet. Please run balances database update.'];
    }

    $insertCols = [];
    $insertVals = [];
    foreach ($insertRow as $col => $val) {
        if (!in_array($col, $faCols, true)) {
            continue;
        }
        $insertCols[] = $col;
        $insertVals[] = $val;
    }
    if ($insertCols === [] || !in_array('parent_id', $insertCols, true)) {
        return ['success' => false, 'message' => 'Could not save sub-account parent link. Please contact support.'];
    }

    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
    $stmt = $pdo->prepare(
        'INSERT INTO financial_accounts (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')'
    );
    $stmt->execute($insertVals);

    $newId = (int) $pdo->lastInsertId();
    if ($newId <= 0) {
        return ['success' => false, 'message' => 'Sub-account could not be created.'];
    }

    $verify = $pdo->prepare('SELECT parent_id FROM financial_accounts WHERE id = ? LIMIT 1');
    $verify->execute([$newId]);
    $savedParentId = (int) $verify->fetchColumn();
    if ($savedParentId !== $parentId) {
        try {
            $pdo->prepare('DELETE FROM financial_accounts WHERE id = ?')->execute([$newId]);
        } catch (Throwable $e) {
        }

        return ['success' => false, 'message' => 'Sub-account was created without a parent link. Please try again.'];
    }

    balances_link_account_to_gl($pdo, $newId);

    return [
        'success' => true,
        'id' => $newId,
        'message' => 'Sub-account created successfully.',
    ];
}

function balances_link_account_to_gl(PDO $pdo, int $financialAccountId): void
{
    $linkFile = dirname(__DIR__, 2) . '/includes/fa_gl_linking.php';
    if (!is_file($linkFile)) {
        return;
    }
    require_once $linkFile;
    if (function_exists('fa_gl_ensure_gl_link_column')) {
        fa_gl_ensure_gl_link_column($pdo);
    }
    if (function_exists('fa_gl_link_financial_account')) {
        try {
            fa_gl_link_financial_account($pdo, $financialAccountId);
        } catch (Throwable $e) {
            error_log('balances_link_account_to_gl #' . $financialAccountId . ': ' . $e->getMessage());
        }
    }
}

function coa_normalize_ledger_type(string $type): string
{
    $t = strtolower(trim($type));
    $map = [
        'cash' => 'asset',
        'bank' => 'asset',
        'mobile' => 'asset',
        'income' => 'revenue',
        'sales' => 'revenue',
    ];

    return $map[$t] ?? $t;
}

function coa_is_default_parent_account_code(string $code): bool
{
    $code = trim($code);
    if ($code === '') {
        return false;
    }
    foreach (coa_default_accounts_catalog() as $parent) {
        if ((string) ($parent['code'] ?? '') === $code) {
            return true;
        }
    }

    return false;
}

/**
 * Default parents that must always exist (cannot be deleted or suppressed).
 */
function coa_is_required_default_parent_code(string $code): bool
{
    $code = trim($code);

    return $code === '1100';
}

/**
 * Whether an account row is locked as a required system parent (e.g. Petty Cash).
 *
 * @param array<string, mixed> $row
 */
function coa_account_is_required_system_parent(array $row): bool
{
    if ((int) ($row['is_system'] ?? 0) === 1) {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '' && function_exists('coa_parse_account_name_parts')) {
            [$code] = coa_parse_account_name_parts((string) ($row['name'] ?? ''));
        }
        if ($code !== '' && coa_is_required_default_parent_code($code)) {
            return true;
        }
    }

    $code = trim((string) ($row['code'] ?? ''));
    if ($code === '' && function_exists('coa_parse_account_name_parts')) {
        [$code] = coa_parse_account_name_parts((string) ($row['name'] ?? ''));
    }
    if ($code !== '' && coa_is_required_default_parent_code($code)) {
        return true;
    }

    $name = strtolower(trim((string) ($row['name'] ?? '')));
    if ($name === 'petty cash' || str_starts_with($name, '1100 - petty cash')) {
        return true;
    }

    return false;
}

/**
 * Build a display label for a default catalog parent in move/assign dropdowns.
 *
 * @param array<string,mixed> $row
 */
function coa_format_catalog_parent_option_label(array $row): string
{
    $name = trim((string) ($row['name'] ?? ''));
    $code = trim((string) ($row['code'] ?? ''));
    if ($code === '-') {
        $code = '';
    }
    $normal = strtoupper(trim((string) ($row['normal_balance_short'] ?? '')));
    if ($normal === '') {
        $typeSlug = strtolower((string) ($row['type'] ?? ''));
        $normal = in_array($typeSlug, ['liability', 'equity', 'revenue'], true) ? 'CR' : 'DR';
    }
    $description = trim((string) ($row['description'] ?? ''));
    if ($description === '' && $code !== '' && function_exists('coa_account_description_for_code')) {
        $description = coa_account_description_for_code($code, '');
    }

    $label = $name;
    if ($code !== '') {
        $label = $code . ' - ' . $name;
    }
    if ($normal !== '') {
        $label .= ' (' . $normal . ')';
    }
    if ($description !== '') {
        $label .= ' — ' . $description;
    }

    return $label;
}

function coa_account_types_compatible_for_parent(string $childType, string $parentType, string $parentCode = ''): bool
{
    $child = coa_normalize_ledger_type($childType);
    $parent = coa_normalize_ledger_type($parentType);
    if ($child === '') {
        return true;
    }
    if (trim($parentCode) === '6000') {
        return $child === 'expense';
    }

    return $child === $parent;
}

/**
 * Link an existing top-level account under a parent (sets parent_id only; balances/transactions unchanged).
 *
 * @return array{success:bool,id?:int,message:string}
 */
function balances_assign_account_as_sub_account(PDO $pdo, int $accountId, int $parentId): array
{
    if ($accountId <= 0 || $parentId <= 0) {
        return ['success' => false, 'message' => 'Account and parent are required.'];
    }
    if ($accountId === $parentId) {
        return ['success' => false, 'message' => 'An account cannot be assigned under itself.'];
    }

    coa_ensure_parent_id_column($pdo);

    $accountStmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE id = ? LIMIT 1');
    $accountStmt->execute([$accountId]);
    $account = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$account) {
        return ['success' => false, 'message' => 'Account not found.'];
    }

    $parentStmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE id = ? LIMIT 1');
    $parentStmt->execute([$parentId]);
    $parent = $parentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$parent) {
        return ['success' => false, 'message' => 'Parent account not found.'];
    }

    if ((int) ($parent['parent_id'] ?? 0) > 0) {
        return ['success' => false, 'message' => 'Sub-accounts can only be added under a main account.'];
    }

    $existingParentId = (int) ($account['parent_id'] ?? 0);
    if ($existingParentId > 0 && $existingParentId !== $parentId) {
        return ['success' => false, 'message' => 'This account is already under another parent. Unassign it first.'];
    }
    if ($existingParentId === $parentId) {
        return ['success' => true, 'id' => $accountId, 'message' => 'Account is already under this parent.'];
    }

    [$parentCode] = coa_parse_account_name_parts((string) ($parent['name'] ?? ''));
    $parentCode = trim($parentCode);
    $isCatalogParent = $parentCode !== '' && coa_is_default_parent_account_code($parentCode);
    if (
        !$isCatalogParent
        && !coa_account_types_compatible_for_parent(
            (string) ($account['type'] ?? ''),
            (string) ($parent['type'] ?? ''),
            $parentCode
        )
    ) {
        return ['success' => false, 'message' => 'Account type does not match the selected parent category.'];
    }

    $movedChildren = 0;
    try {
        $pdo->beginTransaction();

        // Sub-accounts cannot have their own children — attach them to the new parent first.
        $reparentChildren = $pdo->prepare('UPDATE financial_accounts SET parent_id = ? WHERE parent_id = ?');
        $reparentChildren->execute([$parentId, $accountId]);
        $movedChildren = (int) $reparentChildren->rowCount();

        $upd = $pdo->prepare('UPDATE financial_accounts SET parent_id = ? WHERE id = ?');
        $upd->execute([$parentId, $accountId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['success' => false, 'message' => 'Could not move account. Please try again.'];
    }

    $childNote = $movedChildren > 0
        ? ' Its former sub-accounts were kept under the new parent.'
        : '';

    return [
        'success' => true,
        'id' => $accountId,
        'message' => 'Account moved under the selected parent. Balances and transaction history are unchanged.' . $childNote,
    ];
}

/**
 * Move a sub-account back to the main accounts list (clears parent_id only).
 *
 * @return array{success:bool,id?:int,message:string}
 */
function balances_unassign_sub_account(PDO $pdo, int $accountId): array
{
    if ($accountId <= 0) {
        return ['success' => false, 'message' => 'Account is required.'];
    }

    coa_ensure_parent_id_column($pdo);

    $accountStmt = $pdo->prepare('SELECT id, parent_id FROM financial_accounts WHERE id = ? LIMIT 1');
    $accountStmt->execute([$accountId]);
    $account = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$account) {
        return ['success' => false, 'message' => 'Account not found.'];
    }
    if ((int) ($account['parent_id'] ?? 0) <= 0) {
        return ['success' => true, 'id' => $accountId, 'message' => 'Account is already a main account.'];
    }

    $upd = $pdo->prepare('UPDATE financial_accounts SET parent_id = NULL WHERE id = ?');
    $upd->execute([$accountId]);

    return [
        'success' => true,
        'id' => $accountId,
        'message' => 'Account moved back to main accounts. Balances and transaction history are unchanged.',
    ];
}

/**
 * Default catalog parents that can receive another account as a sub-account.
 *
 * @param list<array<string,mixed>> $parentAccounts
 * @return list<array<string,mixed>>
 */
function balances_list_target_parent_accounts(array $parentAccounts, int $sourceAccountId, ?array $sourceRow = null): array
{
    $codeToRow = [];
    foreach ($parentAccounts as $row) {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '-' || $code === '') {
            continue;
        }
        if (!coa_is_default_parent_account_code($code)) {
            continue;
        }
        $codeToRow[$code] = $row;
    }

    $targets = [];
    foreach (coa_default_accounts_catalog() as $catalogParent) {
        $code = (string) ($catalogParent['code'] ?? '');
        if ($code === '' || !isset($codeToRow[$code])) {
            continue;
        }
        $row = $codeToRow[$code];
        if ((int) ($row['id'] ?? 0) === $sourceAccountId) {
            continue;
        }
        $targets[] = $row;
    }

    return $targets;
}

function balances_can_move_parent_to_sub_account(array $parentRow): bool
{
    return (int) ($parentRow['parent_id'] ?? 0) <= 0;
}

/**
 * Top-level accounts that can be assigned under a given parent.
 *
 * @param list<array<string,mixed>> $accountRows
 * @param array<string,mixed>|null $parentRow
 * @param array<int,list<array<string,mixed>>> $childrenByParent
 * @return list<array<string,mixed>>
 */
function balances_list_assignable_accounts(array $accountRows, ?array $parentRow, array $childrenByParent): array
{
    if (!$parentRow) {
        return [];
    }

    $parentId = (int) ($parentRow['id'] ?? 0);
    $parentType = (string) ($parentRow['type'] ?? '');
    $parentCode = (string) ($parentRow['code'] ?? '');
    if ($parentCode === '-') {
        $parentCode = '';
    }

    $eligible = [];
    foreach ($accountRows as $row) {
        if ((int) ($row['parent_id'] ?? 0) > 0) {
            continue;
        }
        if ((int) ($row['id'] ?? 0) === $parentId) {
            continue;
        }
        if (!coa_account_types_compatible_for_parent((string) ($row['type'] ?? ''), $parentType, $parentCode)) {
            continue;
        }
        $eligible[] = $row;
    }

    usort($eligible, static function ($a, $b) {
        $codeA = (int) preg_replace('/\D/', '', (string) ($a['code'] ?? '0'));
        $codeB = (int) preg_replace('/\D/', '', (string) ($b['code'] ?? '0'));
        if ($codeA !== $codeB) {
            return $codeA <=> $codeB;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $eligible;
}

/**
 * RayPro-style default chart of accounts (default parents and seeded sub-accounts).
 *
 * @return list<array{code:string,name:string,type:string,description:string,display_order:int,children:list<array{code:string,name:string,description:string}>}>
 */
function coa_default_accounts_catalog(): array
{
    return [
        [
            'code' => '5000',
            'name' => 'Expenses',
            'type' => 'expense',
            'description' => 'Costs of running your business day to day',
            'display_order' => 10,
            'children' => [],
        ],
        [
            'code' => '4000',
            'name' => 'Revenue',
            'type' => 'revenue',
            'description' => 'All income your business earns from its activities',
            'display_order' => 30,
            'children' => [
                [
                    'code' => '4001',
                    'name' => 'Sales Revenue',
                    'description' => 'Income from selling products, e.g. retail sales, online orders, wholesale',
                    'is_system' => 1,
                ],
            ],
        ],
        [
            'code' => '6000',
            'name' => 'Cost of Goods Sold',
            'type' => 'expense',
            'description' => 'Direct costs to produce or purchase the items you sell',
            'display_order' => 40,
            'children' => [],
        ],
        [
            'code' => '1000',
            'name' => 'Assets',
            'type' => 'asset',
            'description' => 'Things your business owns that have value',
            'display_order' => 50,
            'children' => [
                [
                    'code' => '1002',
                    'name' => 'Accounts Receivable',
                    'description' => 'Money customers owe you for products or services already delivered',
                    'is_system' => 1,
                ],
                [
                    'code' => '1005',
                    'name' => 'Undeposited Funds',
                    'description' => 'Payments received but not yet deposited into a bank account',
                    'is_system' => 1,
                ],
                [
                    'code' => '1008',
                    'name' => 'CRDB',
                    'description' => 'Things your business owns that have value',
                    'is_system' => 1,
                ],
            ],
        ],
        [
            'code' => '1100',
            'name' => 'Petty Cash',
            'type' => 'cash',
            'description' => 'Cash float for small day-to-day business expenses. Add sub-accounts such as Fuel or Transport under this account.',
            'display_order' => 55,
            'is_system' => 1,
            'children' => [],
        ],
        [
            'code' => '2000',
            'name' => 'Liabilities',
            'type' => 'liability',
            'description' => 'Money your business owes to others',
            'display_order' => 60,
            'children' => [],
        ],
        [
            'code' => '3000',
            'name' => 'Equity',
            'type' => 'equity',
            'description' => "The owner's share of the business after all debts are paid",
            'display_order' => 70,
            'children' => [],
        ],
    ];
}

/**
 * Sub-account codes that were previously auto-seeded (safe to prune when unused).
 *
 * @return list<string>
 */
function coa_legacy_default_sub_account_codes(): array
{
    return [
        '5001', '5002', '5003', '5004', '5005', '5006', '5007', '5008', '5009', '5010', '5011', '5012', '5014',
        '4002', '4003', '4004', '4099',
        '6001', '6002', '6003', '6004', '6005',
        '1001', '1002', '1003', '1004', '1005', '1006', '1007', '1008', '1009',
        '2001', '2002', '2003', '2004', '2005', '2006',
    ];
}

/**
 * Sub-accounts removed from the default catalog; prune when unused so users add them manually.
 *
 * @return list<string>
 */
function coa_retired_default_sub_account_codes(): array
{
    return ['4002', '4003', '4099'];
}

/**
 * Remove retired catalog sub-accounts that have no transactions (manual creation still allowed).
 */
function balances_prune_retired_catalog_sub_accounts(PDO $pdo): void
{
    coa_ensure_parent_id_column($pdo);

    foreach (coa_retired_default_sub_account_codes() as $code) {
        $id = coa_find_account_id_by_code($pdo, $code);
        if ($id === null || $id <= 0) {
            continue;
        }

        $rowStmt = $pdo->prepare('SELECT id, parent_id FROM financial_accounts WHERE id = ? LIMIT 1');
        $rowStmt->execute([$id]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row || (int) ($row['parent_id'] ?? 0) <= 0) {
            continue;
        }

        try {
            $txStmt = $pdo->prepare('SELECT COUNT(*) FROM account_transactions WHERE account_id = ?');
            $txStmt->execute([$id]);
            if ((int) $txStmt->fetchColumn() > 0) {
                continue;
            }
        } catch (Throwable $e) {
            continue;
        }

        try {
            $pdo->prepare('DELETE FROM financial_accounts WHERE id = ?')->execute([$id]);
        } catch (Throwable $e) {
        }
    }
}

/**
 * Remove auto-seeded default sub-accounts that have no transactions (user-added subs are kept).
 */
function balances_prune_legacy_default_sub_accounts(PDO $pdo): void
{
    coa_ensure_parent_id_column($pdo);

    foreach (coa_legacy_default_sub_account_codes() as $code) {
        $id = coa_find_account_id_by_code($pdo, $code);
        if ($id === null || $id <= 0) {
            continue;
        }

        $rowStmt = $pdo->prepare('SELECT id, parent_id, created_at FROM financial_accounts WHERE id = ? LIMIT 1');
        $rowStmt->execute([$id]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row || (int) ($row['parent_id'] ?? 0) <= 0) {
            continue;
        }

        // Safeguard: Any account created on or after June 19, 2026 is user-created and must never be pruned.
        $createdAt = strtotime((string) ($row['created_at'] ?? ''));
        if ($createdAt !== false && $createdAt >= strtotime('2026-06-19 00:00:00')) {
            continue;
        }

        try {
            $txStmt = $pdo->prepare('SELECT COUNT(*) FROM account_transactions WHERE account_id = ?');
            $txStmt->execute([$id]);
            if ((int) $txStmt->fetchColumn() > 0) {
                continue;
            }
        } catch (Throwable $e) {
            continue;
        }

        try {
            $pdo->prepare('DELETE FROM financial_accounts WHERE id = ?')->execute([$id]);
        } catch (Throwable $e) {
        }
    }
}

/**
 * @return array<string, string>
 */
function coa_default_account_descriptions_map(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    foreach (coa_default_accounts_catalog() as $parent) {
        $map[(string) $parent['code']] = (string) ($parent['description'] ?? '');
        foreach ($parent['children'] ?? [] as $child) {
            $map[(string) $child['code']] = (string) ($child['description'] ?? '');
        }
    }

    return $map;
}

function coa_account_description_for_code(string $code, string $fallback = ''): string
{
    $code = trim($code);
    if ($code === '' || $code === '-') {
        return $fallback;
    }
    $map = coa_default_account_descriptions_map();

    return $map[$code] ?? $fallback;
}

function coa_ensure_suppressed_default_codes_table(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS coa_suppressed_default_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NULL DEFAULT NULL,
                account_code VARCHAR(20) NOT NULL,
                suppressed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_coa_suppressed_code (company_id, account_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
    }
}

function coa_suppress_default_account_code(PDO $pdo, string $code): void
{
    $code = trim($code);
    if ($code === '') {
        return;
    }
    if (function_exists('coa_is_required_default_parent_code') && coa_is_required_default_parent_code($code)) {
        return;
    }

    coa_ensure_suppressed_default_codes_table($pdo);

    $companyId = null;
    if (function_exists('balancesUseCompanyScope') && balancesUseCompanyScope() && function_exists('currentCompanyId')) {
        $cid = (int) (currentCompanyId() ?? 0);
        if ($cid > 0) {
            $companyId = $cid;
        }
    }

    try {
        $stmt = $pdo->prepare('
            INSERT IGNORE INTO coa_suppressed_default_codes (company_id, account_code)
            VALUES (?, ?)
        ');
        $stmt->execute([$companyId, $code]);
    } catch (Throwable $e) {
    }
}

function coa_unsuppress_default_account_code(PDO $pdo, string $code): void
{
    $code = trim($code);
    if ($code === '') {
        return;
    }

    coa_ensure_suppressed_default_codes_table($pdo);

    $companyId = null;
    if (function_exists('balancesUseCompanyScope') && balancesUseCompanyScope() && function_exists('currentCompanyId')) {
        $cid = (int) (currentCompanyId() ?? 0);
        if ($cid > 0) {
            $companyId = $cid;
        }
    }

    try {
        if ($companyId !== null) {
            $stmt = $pdo->prepare('
                DELETE FROM coa_suppressed_default_codes
                WHERE account_code = ? AND (company_id = ? OR company_id IS NULL)
            ');
            $stmt->execute([$code, $companyId]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM coa_suppressed_default_codes WHERE account_code = ?');
            $stmt->execute([$code]);
        }
    } catch (Throwable $e) {
    }
}

function coa_is_default_account_code_suppressed(PDO $pdo, string $code): bool
{
    $code = trim($code);
    if ($code === '') {
        return false;
    }

    coa_ensure_suppressed_default_codes_table($pdo);

    $companyId = null;
    if (function_exists('balancesUseCompanyScope') && balancesUseCompanyScope() && function_exists('currentCompanyId')) {
        $cid = (int) (currentCompanyId() ?? 0);
        if ($cid > 0) {
            $companyId = $cid;
        }
    }

    try {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM coa_suppressed_default_codes
            WHERE account_code = ? AND (company_id <=> ?)
        ');
        $stmt->execute([$code, $companyId]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function coa_seed_account_full_name(string $code, string $name): string
{
    return trim($code) . ' - ' . trim($name);
}

/**
 * Resolve company id used when seeding default COA parents.
 */
function coa_resolve_seed_company_id(?int $companyId = null): ?int
{
    if ($companyId !== null && $companyId > 0) {
        return $companyId;
    }
    if (!function_exists('balancesUseCompanyScope') || !balancesUseCompanyScope()) {
        return null;
    }
    if (!function_exists('currentCompanyId')) {
        return null;
    }
    $cid = (int) (currentCompanyId() ?? 0);

    return $cid > 0 ? $cid : null;
}

/**
 * Find a catalog seed account by exact seeded name (not just leading code).
 */
function coa_find_seed_account(PDO $pdo, string $code, string $seedName, ?int $companyId = null): ?int
{
    $fullName = coa_seed_account_full_name($code, $seedName);
    $companyId = coa_resolve_seed_company_id($companyId);

    try {
        if ($companyId !== null && function_exists('balancesUseCompanyScope') && balancesUseCompanyScope()) {
            $stmt = $pdo->prepare(
                'SELECT id FROM financial_accounts WHERE name = ? AND company_id = ? LIMIT 1'
            );
            $stmt->execute([$fullName, $companyId]);
            $id = (int) $stmt->fetchColumn();
            if ($id > 0) {
                return $id;
            }

            $stmt = $pdo->prepare(
                'SELECT id FROM financial_accounts
                 WHERE name = ? AND (company_id IS NULL OR company_id = 0)
                 LIMIT 1'
            );
            $stmt->execute([$fullName]);
            $id = (int) $stmt->fetchColumn();

            return $id > 0 ? $id : null;
        }

        $stmt = $pdo->prepare('SELECT id FROM financial_accounts WHERE name = ? LIMIT 1');
        $stmt->execute([$fullName]);
        $id = (int) $stmt->fetchColumn();

        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function coa_find_account_id_by_code(PDO $pdo, string $code): ?int
{
    $want = (int) trim($code);
    if ($want <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->query('SELECT id, name FROM financial_accounts');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            $num = coa_extract_leading_code_from_account_name((string) ($row['name'] ?? ''));
            if ($num !== null && $num === $want) {
                return (int) ($row['id'] ?? 0);
            }
        }
    } catch (Throwable $e) {
    }

    return null;
}

function coa_insert_seed_account(
    PDO $pdo,
    string $code,
    string $name,
    string $type,
    ?int $parentId = null,
    string $currency = 'TZS',
    ?int $companyId = null,
    int $isSystem = 0
): ?int {
    $existingId = coa_find_seed_account($pdo, $code, $name, $companyId);
    coa_ensure_parent_id_column($pdo);
    $faCols = $pdo->query('SHOW COLUMNS FROM financial_accounts')->fetchAll(PDO::FETCH_COLUMN) ?: [];

    if ($existingId !== null) {
        if ($parentId > 0 || $isSystem > 0) {
            $updates = [];
            $params = [];
            if ($parentId > 0 && in_array('parent_id', $faCols, true)) {
                $updates[] = 'parent_id = ?';
                $params[] = $parentId;
            }
            if ($isSystem > 0 && in_array('is_system', $faCols, true)) {
                $updates[] = 'is_system = 1';
            }
            if ($updates !== []) {
                $params[] = $existingId;
                try {
                    $pdo->prepare(
                        'UPDATE financial_accounts SET ' . implode(', ', $updates) . ' WHERE id = ?'
                    )->execute($params);
                } catch (Throwable $e) {
                    error_log('coa_insert_seed_account sync: ' . $e->getMessage());
                }
            }
        }

        return $existingId;
    }

    if ($parentId === null && coa_is_default_account_code_suppressed($pdo, $code)) {
        // Required parents (Petty Cash) must always be available.
        if (!function_exists('coa_is_required_default_parent_code') || !coa_is_required_default_parent_code($code)) {
            return null;
        }
        if (function_exists('coa_unsuppress_default_account_code')) {
            coa_unsuppress_default_account_code($pdo, $code);
        }
    }

    $insertRow = [
        'name' => coa_seed_account_full_name($code, $name),
        'type' => strtolower(trim($type)),
        'currency' => strtoupper(trim($currency)),
        'opening_balance' => 0,
        'current_balance' => 0,
        'status' => 'active',
        'parent_id' => $parentId > 0 ? $parentId : null,
    ];

    $seedCompanyId = coa_resolve_seed_company_id($companyId);
    if ($seedCompanyId !== null && function_exists('balancesUseCompanyScope') && balancesUseCompanyScope()) {
        $insertRow['company_id'] = $seedCompanyId;
    }

    if (in_array('is_system', $faCols, true)) {
        $insertRow['is_system'] = $isSystem;
    }

    $insertCols = [];
    $insertVals = [];
    foreach ($insertRow as $col => $val) {
        if (!in_array($col, $faCols, true)) {
            continue;
        }
        $insertCols[] = $col;
        $insertVals[] = $val;
    }
    if ($insertCols === []) {
        return null;
    }

    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
    $stmt = $pdo->prepare(
        'INSERT INTO financial_accounts (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')'
    );
    $stmt->execute($insertVals);

    return (int) $pdo->lastInsertId();
}

/**
 * Legacy top-level Petty Cash parents that should be merged into catalog 1100.
 *
 * @return list<int>
 */
function balances_find_legacy_petty_cash_parent_ids(PDO $pdo, int $excludeId = 0): array
{
    if (!function_exists('tableExists') || !tableExists('financial_accounts', $pdo)) {
        return [];
    }

    try {
        $hasParent = function_exists('columnExists') && columnExists('financial_accounts', 'parent_id', $pdo);
        $sql = "
            SELECT id, name
            FROM financial_accounts
            WHERE status = 'active'
        ";
        if ($hasParent) {
            $sql .= ' AND (parent_id IS NULL OR parent_id = 0)';
        }
        $sql .= "
              AND (
                    name LIKE '1002 - PETTY CASH%'
                 OR name LIKE '1002 - Petty Cash%'
                 OR UPPER(TRIM(name)) = 'PETTY CASH'
                 OR UPPER(TRIM(name)) = '1002 - PETTY CASH'
                 OR (
                        LOWER(name) LIKE '%petty cash%'
                    AND name NOT LIKE '1100 - Petty Cash%'
                    AND name NOT LIKE '[Merged]%'
                 )
              )
            ORDER BY id ASC
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }

    $ids = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || ($excludeId > 0 && $id === $excludeId)) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        // Never treat the catalog parent itself as legacy.
        if (strcasecmp($name, '1100 - Petty Cash') === 0 || str_starts_with($name, '1100 - Petty Cash')) {
            continue;
        }
        $ids[] = $id;
    }

    return array_values(array_unique($ids));
}

/**
 * Remap every known FK that may point at a financial_accounts row.
 */
function balances_remap_financial_account_references(PDO $pdo, int $fromId, int $toId): void
{
    if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
        return;
    }

    $maps = [
        ['account_transactions', 'account_id'],
        ['erp_expenses', 'account_id'],
        ['erp_expenses', 'source_account_id'],
        ['erp_journal_items', 'account_id'],
        ['petty_cash_vouchers', 'petty_cash_account_id'],
        ['petty_cash_vouchers', 'expense_account_id'],
        ['petty_cash_replenishments', 'petty_cash_account_id'],
        ['petty_cash_replenishments', 'source_account_id'],
        ['payment_vouchers', 'payment_account_id'],
        ['supplier_payments', 'bank_or_cash_account_id'],
        ['revenue_entries', 'revenue_account_id'],
        ['vendor_bill_items', 'account_id'],
        ['erp_bank_accounts', 'gl_account_id'],
    ];

    foreach ($maps as [$table, $column]) {
        try {
            if (!function_exists('tableExists') || !tableExists($table, $pdo)) {
                continue;
            }
            if (!function_exists('columnExists') || !columnExists($table, $column, $pdo)) {
                continue;
            }
            $pdo->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?")
                ->execute([$toId, $fromId]);
        } catch (Throwable $e) {
            error_log("balances_remap_financial_account_references {$table}.{$column}: " . $e->getMessage());
        }
    }
}

/**
 * Merge a legacy Petty Cash parent into catalog 1100 (balance, children, history, FKs).
 */
function balances_merge_financial_account_into(PDO $pdo, int $fromId, int $toId): bool
{
    if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
        return false;
    }

    $fromStmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE id = ? LIMIT 1');
    $fromStmt->execute([$fromId]);
    $from = $fromStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$from) {
        return false;
    }

    $toStmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE id = ? LIMIT 1');
    $toStmt->execute([$toId]);
    $to = $toStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$to) {
        return false;
    }

    $ownTxn = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownTxn = true;
        }

        // Point children at the canonical parent.
        if (function_exists('columnExists') && columnExists('financial_accounts', 'parent_id', $pdo)) {
            $pdo->prepare('UPDATE financial_accounts SET parent_id = ? WHERE parent_id = ?')
                ->execute([$toId, $fromId]);
        }

        balances_remap_financial_account_references($pdo, $fromId, $toId);

        // Carry opening balance forward so recalculation stays correct.
        $fromOpening = (float) ($from['opening_balance'] ?? 0);
        if ($fromOpening != 0.0) {
            $pdo->prepare(
                'UPDATE financial_accounts
                 SET opening_balance = COALESCE(opening_balance, 0) + ?
                 WHERE id = ?'
            )->execute([$fromOpening, $toId]);
        }

        $legacyName = trim((string) ($from['name'] ?? ('Account #' . $fromId)));
        $mergedName = '[Merged] ' . $legacyName;
        if (strlen($mergedName) > 100) {
            $mergedName = substr($mergedName, 0, 100);
        }

        $deactivateSql = "
            UPDATE financial_accounts
            SET status = 'inactive',
                current_balance = 0,
                opening_balance = 0,
                name = ?
        ";
        $params = [$mergedName];
        if (function_exists('columnExists') && columnExists('financial_accounts', 'is_system', $pdo)) {
            $deactivateSql .= ', is_system = 0';
        }
        if (function_exists('columnExists') && columnExists('financial_accounts', 'parent_id', $pdo)) {
            $deactivateSql .= ', parent_id = NULL';
        }
        $deactivateSql .= ' WHERE id = ?';
        $params[] = $fromId;
        $pdo->prepare($deactivateSql)->execute($params);

        if ($ownTxn && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('balances_merge_financial_account_into: ' . $e->getMessage());

        return false;
    }

    try {
        // Tenant rows often have NULL company_id; avoid company-scoped recalculate missing them.
        $openStmt = $pdo->prepare('SELECT opening_balance FROM financial_accounts WHERE id = ? LIMIT 1');
        $openStmt->execute([$toId]);
        $opening = (float) ($openStmt->fetchColumn() ?: 0);
        $inStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM account_transactions WHERE account_id = ? AND type = 'credit'");
        $inStmt->execute([$toId]);
        $inflow = (float) $inStmt->fetchColumn();
        $outStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM account_transactions WHERE account_id = ? AND type = 'debit'");
        $outStmt->execute([$toId]);
        $outflow = (float) $outStmt->fetchColumn();
        $newBalance = $opening + $inflow - $outflow;
        $pdo->prepare('UPDATE financial_accounts SET current_balance = ? WHERE id = ?')
            ->execute([$newBalance, $toId]);
    } catch (Throwable $e) {
        error_log('balances_merge_financial_account_into recalc: ' . $e->getMessage());
        try {
            balancesRecalculateAccount($pdo, $toId);
        } catch (Throwable $e2) {
            error_log('balances_merge_financial_account_into recalc fallback: ' . $e2->getMessage());
        }
    }

    error_log("balances_merge_financial_account_into: merged account {$fromId} into {$toId}");

    return true;
}

/**
 * Ensure catalog 1100 is the only active Petty Cash parent.
 * Runs safely on every bootstrap / live deploy (no-ops once cleaned).
 */
function balances_consolidate_petty_cash_accounts(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!function_exists('tableExists') || !tableExists('financial_accounts', $pdo)) {
        return;
    }

    try {
        coa_ensure_parent_id_column($pdo);

        // Prefer renaming a single legacy parent to 1100 when catalog 1100 is missing.
        $canonicalId = (int) (coa_find_seed_account($pdo, '1100', 'Petty Cash') ?: 0);
        if ($canonicalId <= 0) {
            $legacyIds = balances_find_legacy_petty_cash_parent_ids($pdo, 0);
            if ($legacyIds !== []) {
                $promoteId = (int) $legacyIds[0];
                $faCols = $pdo->query('SHOW COLUMNS FROM financial_accounts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $sets = ["name = '1100 - Petty Cash'", "type = 'cash'", "status = 'active'"];
                if (in_array('is_system', $faCols, true)) {
                    $sets[] = 'is_system = 1';
                }
                if (in_array('parent_id', $faCols, true)) {
                    $sets[] = 'parent_id = NULL';
                }
                $pdo->prepare('UPDATE financial_accounts SET ' . implode(', ', $sets) . ' WHERE id = ?')
                    ->execute([$promoteId]);
                $canonicalId = $promoteId;
                error_log("balances_consolidate_petty_cash_accounts: promoted account {$promoteId} to 1100 - Petty Cash");
            }
        }

        if ($canonicalId <= 0) {
            $canonicalId = (int) (coa_insert_seed_account(
                $pdo,
                '1100',
                'Petty Cash',
                'cash',
                null,
                'TZS',
                null,
                1
            ) ?: 0);
        }

        if ($canonicalId <= 0) {
            return;
        }

        // Keep the canonical row locked + correctly named.
        $faCols = $pdo->query('SHOW COLUMNS FROM financial_accounts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $sets = ["name = '1100 - Petty Cash'", "type = 'cash'", "status = 'active'"];
        if (in_array('is_system', $faCols, true)) {
            $sets[] = 'is_system = 1';
        }
        if (in_array('parent_id', $faCols, true)) {
            $sets[] = 'parent_id = NULL';
        }
        $pdo->prepare('UPDATE financial_accounts SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute([$canonicalId]);

        foreach (balances_find_legacy_petty_cash_parent_ids($pdo, $canonicalId) as $legacyId) {
            balances_merge_financial_account_into($pdo, $legacyId, $canonicalId);
        }
    } catch (Throwable $e) {
        error_log('balances_consolidate_petty_cash_accounts: ' . $e->getMessage());
    }
}

/**
 * Insert missing default COA parent accounts and catalog sub-accounts.
 */
function balances_ensure_default_accounts(PDO $pdo, ?int $companyId = null): void
{
    coa_ensure_parent_id_column($pdo);

    $categories = coa_category_options_map();
    foreach (coa_default_accounts_catalog() as $parent) {
        $type = (string) ($parent['type'] ?? 'asset');
        $parentCode = (string) ($parent['code'] ?? '');
        if ($parentCode !== '') {
            coa_unsuppress_default_account_code($pdo, $parentCode);
        }
        if ($parentCode === '6000') {
            $categoryLabel = 'Cost of Goods Sold';
        } else {
            $categoryLabel = coa_account_type_to_category_label($type);
        }
        if (isset($categories[$categoryLabel]) && function_exists('coa_ensure_account_category')) {
            $meta = $categories[$categoryLabel];
            coa_ensure_account_category(
                $pdo,
                $meta['account_category'],
                $meta['account_type'],
                $meta['reporting_group'],
                $meta['financial_statement']
            );
        }

        $parentId = coa_insert_seed_account(
            $pdo,
            (string) $parent['code'],
            (string) $parent['name'],
            $type,
            null,
            'TZS',
            $companyId,
            isset($parent['is_system']) ? (int) $parent['is_system'] : 0
        );
        if ($parentId === null || $parentId <= 0) {
            $parentId = coa_find_seed_account($pdo, (string) $parent['code'], (string) $parent['name'], $companyId);
        }
        if ($parentId === null || $parentId <= 0) {
            continue;
        }

        foreach ($parent['children'] ?? [] as $child) {
            $childId = coa_insert_seed_account(
                $pdo,
                (string) $child['code'],
                (string) $child['name'],
                $type,
                $parentId,
                'TZS',
                $companyId,
                isset($child['is_system']) ? (int) $child['is_system'] : 0
            );
            if ($childId && function_exists('balances_link_account_to_gl')) {
                balances_link_account_to_gl($pdo, $childId);
            }
        }
    }

    balances_prune_retired_catalog_sub_accounts($pdo);
    balances_consolidate_petty_cash_accounts($pdo);

    if (is_file(dirname(__DIR__, 2) . '/includes/accounting_settings.php')) {
        require_once dirname(__DIR__, 2) . '/includes/accounting_settings.php';
        if (function_exists('accounting_ensure_default_settings')) {
            accounting_ensure_default_settings($pdo);
        }
    }
}

/**
 * Seed default COA parents for a specific company (e.g. after company registration).
 */
function balances_ensure_default_accounts_for_company(PDO $pdo, int $companyId): void
{
    if ($companyId <= 0) {
        return;
    }

    $previousCompanyId = $_SESSION['company_id'] ?? null;
    $_SESSION['company_id'] = $companyId;
    try {
        balances_ensure_default_accounts($pdo, $companyId);
    } finally {
        if ($previousCompanyId === null) {
            unset($_SESSION['company_id']);
        } else {
            $_SESSION['company_id'] = $previousCompanyId;
        }
    }
}

/**
 * Flatten accounts into parent → children order with depth metadata.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function balances_flatten_account_tree(array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $byId = [];
    $byParent = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $byId[$id] = $row;
    }
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $pid = (int) ($row['parent_id'] ?? 0);
        if ($pid > 0 && !isset($byId[$pid])) {
            $pid = 0;
        }
        $byParent[$pid][] = $row;
    }

    foreach ($byParent as &$group) {
        usort($group, static function ($a, $b) {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
    }
    unset($group);

    $out = [];
    $walk = static function (int $parentId, int $depth) use (&$walk, &$byParent, &$out): void {
        foreach ($byParent[$parentId] ?? [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            $childCount = count($byParent[$id] ?? []);
            $out[] = array_merge($row, [
                'depth' => $depth,
                'has_children' => $childCount > 0,
                'child_count' => $childCount,
            ]);
            if ($childCount > 0) {
                $walk($id, $depth + 1);
            }
        }
    };
    $walk(0, 0);

    return $out;
}

/**
 * Human-readable internal transfer method from source/destination liquidity buckets.
 */
function balancesTransferMethodLabel(string $fromBucket, string $toBucket): string
{
    static $labels = [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'mobile' => 'Mobile Money',
    ];
    $from = $labels[$fromBucket] ?? 'Bank';
    $to = $labels[$toBucket] ?? 'Bank';

    return $from . ' to ' . $to;
}

/**
 * Account types excluded from payment deposit pickers (pure chart-of-accounts categories).
 *
 * @return array<int, string>
 */
function balancesCoaOnlyAccountTypes(): array
{
    return ['asset', 'liability', 'equity', 'revenue', 'expense'];
}

/**
 * Whether a financial_accounts row is a deposit destination (cash/bank/mobile), not a COA category only.
 */
function balancesIsDepositAccountType(string $type): bool
{
    $t = strtolower(trim($type));

    return $t !== '' && !in_array($t, balancesCoaOnlyAccountTypes(), true);
}

/**
 * Active deposit accounts from the Balances module PDO (same source as accounts.php).
 *
 * @return array<int, array<string, mixed>>
 */
function balancesFetchDepositAccounts(?PDO $pdo = null): array
{
    if (!($pdo instanceof PDO) && function_exists('balances_resolve_pdo')) {
        $pdo = balances_resolve_pdo();
    }
    if (!($pdo instanceof PDO)) {
        return [];
    }

    $rows = function_exists('balancesFetchAccountsWithLiveBalance')
        ? balancesFetchAccountsWithLiveBalance($pdo, true)
        : [];

    return array_values(array_filter($rows, static function ($acc) {
        return balancesIsDepositAccountType((string) ($acc['type'] ?? ''));
    }));
}

/**
 * Whether system OpenAI integration is enabled and configured.
 */
function balances_ai_is_connected(): bool
{
    $helpers = __DIR__ . '/../../includes/ai_helpers.php';
    if (is_file($helpers)) {
        require_once $helpers;
    }
    if (!function_exists('ai_settings_for_api')) {
        return false;
    }
    $settings = ai_settings_for_api();
    if (empty($settings['configured']) || empty($settings['is_enabled'])) {
        return false;
    }
    try {
        if (function_exists('ai_get_decrypted_api_key')) {
            ai_get_decrypted_api_key();
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Payment, voucher, and balance alerts for the liquidity dashboard.
 *
 * @return array<int, array{type: string, text: string, link?: string}>
 */
function balances_fetch_operational_alerts(PDO $pdo, array $activeAccounts): array
{
    $alerts = [];
    $fmt = static function (float $n): string {
        if (function_exists('bal_dashboard_format_value')) {
            return bal_dashboard_format_value($n);
        }
        if ($n >= 1_000_000) {
            return 'TZS ' . number_format($n / 1_000_000, 1) . 'M';
        }
        if ($n >= 1_000) {
            return 'TZS ' . number_format($n / 1_000, 1) . 'K';
        }
        return 'TZS ' . number_format($n);
    };

    foreach ($activeAccounts as $acc) {
        $bal = isset($acc['live_balance']) ? (float) $acc['live_balance'] : (float) ($acc['current_balance'] ?? 0);
        if ($bal >= 0) {
            continue;
        }
        $name = trim((string) ($acc['name'] ?? 'Account'));
        $alerts[] = [
            'type' => 'warning',
            'text' => $name . ' has a negative balance of ' . $fmt(abs($bal)) . '. Top up or review recent debits.',
            'link' => 'accounts.php',
        ];
    }

    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'payment_vouchers'");
        if (!$tableCheck || !$tableCheck->fetchColumn()) {
            return $alerts;
        }

        $pendingRow = $pdo->query("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total
            FROM payment_vouchers
            WHERE IFNULL(is_paid, 0) = 0
              AND LOWER(status) IN ('pending', 'confirming')
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        $pendingCnt = (int) ($pendingRow['cnt'] ?? 0);
        $pendingTotal = (float) ($pendingRow['total'] ?? 0);
        if ($pendingCnt > 0) {
            $alerts[] = [
                'type' => 'alert',
                'text' => $pendingCnt . ' payment voucher' . ($pendingCnt === 1 ? '' : 's') . ' awaiting approval (' . $fmt($pendingTotal) . ' total).',
                'link' => function_exists('app_url') ? app_url('/admin/all-vouchers.php') : '/admin/all-vouchers.php',
            ];
        }

        $approvedRow = $pdo->query("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total
            FROM payment_vouchers
            WHERE IFNULL(is_paid, 0) = 0
              AND LOWER(status) = 'approved'
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        $approvedCnt = (int) ($approvedRow['cnt'] ?? 0);
        $approvedTotal = (float) ($approvedRow['total'] ?? 0);
        if ($approvedCnt > 0) {
            $alerts[] = [
                'type' => 'payment',
                'text' => $approvedCnt . ' approved payment' . ($approvedCnt === 1 ? '' : 's') . ' ready to pay — ' . $fmt($approvedTotal) . ' outstanding.',
                'link' => function_exists('app_url') ? app_url('/admin/all-vouchers.php') : '/admin/all-vouchers.php',
            ];
        }

        $dueStmt = $pdo->query("
            SELECT id, voucher_no, payee_name, total_amount, status
            FROM payment_vouchers
            WHERE IFNULL(is_paid, 0) = 0
              AND LOWER(status) IN ('approved', 'pending', 'confirming')
            ORDER BY
                CASE WHEN LOWER(status) = 'approved' THEN 0 WHEN LOWER(status) = 'confirming' THEN 1 ELSE 2 END,
                date_created ASC
            LIMIT 3
        ");
        foreach ($dueStmt ? $dueStmt->fetchAll(PDO::FETCH_ASSOC) : [] as $pv) {
            $payee = trim((string) ($pv['payee_name'] ?? '')) ?: 'Unnamed payee';
            $voucherLink = function_exists('app_url')
                ? app_url('/view-voucher.php?id=' . (int) ($pv['id'] ?? 0))
                : ('/view-voucher.php?id=' . (int) ($pv['id'] ?? 0));
            $alerts[] = [
                'type' => 'payment',
                'text' => 'Payment to make: ' . $payee . ' — ' . $fmt((float) ($pv['total_amount'] ?? 0)) . ' (' . ($pv['voucher_no'] ?? 'voucher') . ').',
                'link' => $voucherLink,
            ];
        }
    } catch (Throwable $e) {
        error_log('balances operational alerts: ' . $e->getMessage());
    }

    return array_slice($alerts, 0, 8);
}

/**
 * Rule-based liquidity insights for the balances dashboard.
 *
 * @return array{highlights: string[], suggestions: string[], alerts: array<int, array{type: string, text: string, link?: string}>, ai_connected: bool}
 */
function balances_build_insights(array $metrics, ?PDO $pdo = null): array
{
    $highlights = [];
    $suggestions = [];

    $totalLiquidity = (float) ($metrics['total_liquidity'] ?? 0);
    $activeCount = (int) ($metrics['active_count'] ?? 0);
    $monthCredits = (float) ($metrics['month_credits'] ?? 0);
    $monthDebits = (float) ($metrics['month_debits'] ?? 0);
    $monthNet = (float) ($metrics['month_net'] ?? 0);
    $monthTxCount = (int) ($metrics['month_tx_count'] ?? 0);
    $hasCash = !empty($metrics['has_cash']);
    $hasBank = !empty($metrics['has_bank']);
    $hasMobile = !empty($metrics['has_mobile']);
    $topAccount = $metrics['top_account'] ?? null;
    $topAccountBalance = (float) ($metrics['top_account_balance'] ?? 0);
    $accountStats = $metrics['account_stats'] ?? ['cash' => 0, 'bank' => 0, 'mobile' => 0];

    $fmt = static function (float $n): string {
        if (function_exists('bal_dashboard_format_value')) {
            return bal_dashboard_format_value($n);
        }
        if ($n >= 1_000_000) {
            return 'TZS ' . number_format($n / 1_000_000, 1) . 'M';
        }
        if ($n >= 1_000) {
            return 'TZS ' . number_format($n / 1_000, 1) . 'K';
        }
        return 'TZS ' . number_format($n);
    };

    if ($activeCount === 0) {
        $suggestions[] = 'No active accounts yet. Add cash, bank, or mobile accounts to start tracking liquidity.';
        return [
            'highlights' => $highlights,
            'suggestions' => $suggestions,
            'alerts' => [],
            'ai_connected' => function_exists('balances_ai_is_connected') && balances_ai_is_connected(),
        ];
    }

    if ($totalLiquidity > 0) {
        $highlights[] = $fmt($totalLiquidity) . ' available across ' . $activeCount . ' active account' . ($activeCount === 1 ? '' : 's') . '.';
    } else {
        $suggestions[] = 'Active accounts have zero balance. Record opening balances or post transactions.';
    }

    if ($monthTxCount > 0) {
        if ($monthNet > 0) {
            $highlights[] = 'Net inflow of ' . $fmt($monthNet) . ' this month from ' . number_format($monthTxCount) . ' transaction' . ($monthTxCount === 1 ? '' : 's') . '.';
        } elseif ($monthNet < 0) {
            $suggestions[] = 'Net outflow of ' . $fmt(abs($monthNet)) . ' this month. Review recent debits and expenses.';
        } else {
            $highlights[] = 'Inflows and outflows are balanced this month (' . number_format($monthTxCount) . ' transactions).';
        }
    } else {
        $suggestions[] = 'No transactions recorded this month. Post sales receipts or expense payments to keep ledgers current.';
    }

    if ($totalLiquidity > 0 && $topAccount && $topAccountBalance > 0) {
        $share = ($topAccountBalance / $totalLiquidity) * 100;
        if ($share >= 70) {
            $suggestions[] = number_format($share, 0) . '% of liquidity sits in "' . ($topAccount['name'] ?? 'one account') . '". Consider spreading funds across accounts.';
        }
    }

    $typesUsed = (int) (($accountStats['cash'] ?? 0) > 0) + (int) (($accountStats['bank'] ?? 0) > 0) + (int) (($accountStats['mobile'] ?? 0) > 0);
    if ($typesUsed >= 2) {
        $highlights[] = 'Liquidity is diversified across ' . $typesUsed . ' account type' . ($typesUsed === 1 ? '' : 's') . '.';
    } else {
        if (!$hasCash) {
            $suggestions[] = 'Add a cash account for petty cash and daily collections.';
        }
        if (!$hasBank) {
            $suggestions[] = 'Add a bank account to separate deposits from cash on hand.';
        }
        if (!$hasMobile) {
            $suggestions[] = 'Add mobile money if you accept M-Pesa or similar payments.';
        }
    }

    if ($highlights === []) {
        $highlights[] = 'Liquidity dashboard is ready. Use transfers to move funds between your own accounts.';
    }
    if (count($highlights) > 4) {
        $highlights = array_slice($highlights, 0, 4);
    }

    $activeAccounts = $metrics['active_accounts'] ?? [];
    $alerts = ($pdo instanceof PDO && function_exists('balances_fetch_operational_alerts'))
        ? balances_fetch_operational_alerts($pdo, is_array($activeAccounts) ? $activeAccounts : [])
        : [];

    $aiConnected = function_exists('balances_ai_is_connected') && balances_ai_is_connected();

    return [
        'highlights' => $highlights,
        'suggestions' => $suggestions,
        'alerts' => $alerts,
        'ai_connected' => $aiConnected,
    ];
}

/** Default COA categories when erp_account_categories is empty or missing. */
function coa_default_categories()
{
    return [
        ['id' => 1, 'name' => 'Current Assets', 'account_type' => 'asset', 'financial_statement' => 'Balance Sheet', 'status' => 'active'],
        ['id' => 2, 'name' => 'Non-Current Assets', 'account_type' => 'asset', 'financial_statement' => 'Balance Sheet', 'status' => 'active'],
        ['id' => 3, 'name' => 'Current Liabilities', 'account_type' => 'liability', 'financial_statement' => 'Balance Sheet', 'status' => 'active'],
        ['id' => 4, 'name' => 'Equity', 'account_type' => 'equity', 'financial_statement' => 'Balance Sheet', 'status' => 'active'],
        ['id' => 5, 'name' => 'Revenue', 'account_type' => 'revenue', 'financial_statement' => 'Income Statement', 'status' => 'active'],
        ['id' => 6, 'name' => 'Expense', 'account_type' => 'expense', 'financial_statement' => 'Income Statement', 'status' => 'active'],
    ];
}

/**
 * Default rows for financial_account_categories (Chart of Accounts category master).
 *
 * @return array<int,array<string,mixed>>
 */
function coa_default_financial_account_categories()
{
    return [
        ['code' => 'CA', 'name' => 'Current Assets', 'account_type' => 'Asset', 'reporting_group' => 'Current Assets', 'financial_statement' => 'Balance Sheet', 'display_order' => 10],
        ['code' => 'FA', 'name' => 'Fixed Assets', 'account_type' => 'Asset', 'reporting_group' => 'Non-Current Assets', 'financial_statement' => 'Balance Sheet', 'display_order' => 20],
        ['code' => 'NCA', 'name' => 'Non-Current Assets', 'account_type' => 'Asset', 'reporting_group' => 'Non-Current Assets', 'financial_statement' => 'Balance Sheet', 'display_order' => 30],
        ['code' => 'CL', 'name' => 'Current Liabilities', 'account_type' => 'Liability', 'reporting_group' => 'Current Liabilities', 'financial_statement' => 'Balance Sheet', 'display_order' => 40],
        ['code' => 'LTL', 'name' => 'Long-term Liabilities', 'account_type' => 'Liability', 'reporting_group' => 'Long-term Liabilities', 'financial_statement' => 'Balance Sheet', 'display_order' => 50],
        ['code' => 'EQ', 'name' => 'Equity', 'account_type' => 'Equity', 'reporting_group' => 'Equity', 'financial_statement' => 'Balance Sheet', 'display_order' => 60],
        ['code' => 'INC', 'name' => 'Income', 'account_type' => 'Revenue', 'reporting_group' => 'Revenue', 'financial_statement' => 'Income Statement', 'display_order' => 70],
        ['code' => 'OE', 'name' => 'Operating Expenses', 'account_type' => 'Expense', 'reporting_group' => 'Operating Expenses', 'financial_statement' => 'Income Statement', 'display_order' => 80],
        ['code' => 'COGS', 'name' => 'Cost of Goods Sold', 'account_type' => 'Expense', 'reporting_group' => 'Cost of Goods Sold', 'financial_statement' => 'Income Statement', 'display_order' => 90],
    ];
}

function coa_category_account_type_slug($accountType)
{
    $t = strtolower(trim((string) $accountType));
    $map = [
        'asset' => 'asset',
        'assets' => 'asset',
        'liability' => 'liability',
        'liabilities' => 'liability',
        'equity' => 'equity',
        'revenue' => 'revenue',
        'income' => 'revenue',
        'expense' => 'expense',
        'expenses' => 'expense',
    ];
    return $map[$t] ?? $t;
}

function coa_category_account_type_label($uiAccountType)
{
    $slug = coa_category_account_type_slug($uiAccountType);
    $map = [
        'asset' => 'Asset',
        'liability' => 'Liability',
        'equity' => 'Equity',
        'revenue' => 'Revenue',
        'expense' => 'Expense',
        'cash' => 'Asset',
        'bank' => 'Asset',
        'mobile' => 'Asset',
    ];
    return $map[$slug] ?? ucfirst($slug);
}

function coa_category_code_base($name, $reportingGroup, $accountType)
{
    $source = trim((string) $name) !== '' ? $name : (trim((string) $reportingGroup) !== '' ? $reportingGroup : $accountType);
    $source = preg_replace('/[^a-zA-Z0-9 ]+/', ' ', (string) $source);
    $source = trim((string) $source);
    if ($source === '') {
        return 'CAT';
    }
    $parts = preg_split('/\s+/', strtoupper($source)) ?: [];
    $code = '';
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        $code .= substr($p, 0, 1);
        if (strlen($code) >= 3) {
            break;
        }
    }
    if ($code === '') {
        $code = strtoupper(substr(preg_replace('/\s+/', '', $source), 0, 3));
    }
    return substr($code, 0, 3);
}

function coa_next_category_code(PDO $pdo, $name, $reportingGroup, $accountType)
{
    $base = coa_category_code_base($name, $reportingGroup, $accountType);
    try {
        $stmt = $pdo->prepare('SELECT code FROM financial_account_categories WHERE code LIKE ?');
        $stmt->execute([$base . '%']);
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $codes = [];
    }
    if ($codes === []) {
        return $base;
    }
    $used = [];
    foreach ($codes as $c) {
        $c = strtoupper((string) $c);
        if ($c === $base) {
            $used[1] = true;
            continue;
        }
        if (preg_match('/^' . preg_quote($base, '/') . '(\d{2,4})$/', $c, $m)) {
            $used[(int) $m[1]] = true;
        }
    }
    $n = 2;
    while (isset($used[$n])) {
        $n++;
    }
    return $base . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
}

/**
 * Create financial_account_categories table and seed defaults when empty.
 */
function ensureFinancialAccountCategoriesSchema(PDO $pdo)
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS financial_account_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            account_type VARCHAR(30) NOT NULL,
            parent_id INT NULL,
            description TEXT NULL,
            reporting_group VARCHAR(120) NOT NULL,
            financial_statement VARCHAR(80) NOT NULL,
            display_order INT NOT NULL DEFAULT 10,
            is_header ENUM('Yes','No') NOT NULL DEFAULT 'No',
            allow_child ENUM('Yes','No') NOT NULL DEFAULT 'Yes',
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            notes TEXT NULL,
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_parent_id (parent_id),
            INDEX idx_fac_status (status),
            INDEX idx_fac_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureFinancialAccountCategoriesSchema DDL: ' . $e->getMessage());
        return;
    }

    try {
        $cnt = (int) $pdo->query('SELECT COUNT(*) FROM financial_account_categories')->fetchColumn();
        if ($cnt > 0) {
            return;
        }
        $ins = $pdo->prepare('INSERT INTO financial_account_categories
            (code, name, account_type, reporting_group, financial_statement, display_order, is_header, allow_child, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (coa_default_financial_account_categories() as $row) {
            $ins->execute([
                $row['code'],
                $row['name'],
                $row['account_type'],
                $row['reporting_group'],
                $row['financial_statement'],
                (int) ($row['display_order'] ?? 10),
                'Yes',
                'Yes',
                'Active',
            ]);
        }
    } catch (Throwable $e) {
        error_log('ensureFinancialAccountCategoriesSchema seed: ' . $e->getMessage());
    }
}

/**
 * Ensure a category exists in financial_account_categories; create if missing.
 */
function coa_ensure_account_category(PDO $pdo, $categoryName, $uiAccountType, $reportingGroup = '', $financialStatement = '')
{
    $categoryName = trim((string) $categoryName);
    if ($categoryName === '') {
        return 0;
    }

    ensureFinancialAccountCategoriesSchema($pdo);

    try {
        $find = $pdo->prepare("SELECT id FROM financial_account_categories WHERE name = ? LIMIT 1");
        $find->execute([$categoryName]);
        $existingId = (int) $find->fetchColumn();
        if ($existingId > 0) {
            return $existingId;
        }
    } catch (Throwable $e) {
        error_log('coa_ensure_account_category find: ' . $e->getMessage());
    }

    $accountTypeLabel = coa_category_account_type_label($uiAccountType);
    $slug = coa_category_account_type_slug($uiAccountType);
    if ($financialStatement === '') {
        $financialStatement = in_array($slug, ['revenue', 'expense'], true) ? 'Income Statement' : 'Balance Sheet';
    }
    if ($reportingGroup === '') {
        $reportingGroup = $categoryName;
    }

    $code = coa_next_category_code($pdo, $categoryName, $reportingGroup, $accountTypeLabel);
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    try {
        $ins = $pdo->prepare('INSERT INTO financial_account_categories
            (code, name, account_type, reporting_group, financial_statement, display_order, is_header, allow_child, status, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([
            $code,
            $categoryName,
            $accountTypeLabel,
            $reportingGroup,
            $financialStatement,
            100,
            'No',
            'Yes',
            'Active',
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('coa_ensure_account_category insert: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Load active account categories for COA create/edit dropdowns.
 *
 * @return array<int,array<string,mixed>>
 */
function coa_load_financial_account_categories(PDO $pdo)
{
    ensureFinancialAccountCategoriesSchema($pdo);
    try {
        $rows = $pdo->query("
            SELECT id, code, name, account_type, reporting_group, financial_statement, status
            FROM financial_account_categories
            WHERE status = 'Active'
            ORDER BY display_order ASC, name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'account_type' => coa_category_account_type_slug($row['account_type'] ?? 'asset'),
                'reporting_group' => (string) ($row['reporting_group'] ?? ''),
                'financial_statement' => (string) ($row['financial_statement'] ?? 'Balance Sheet'),
                'status' => 'active',
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('coa_load_financial_account_categories: ' . $e->getMessage());
        return [];
    }
}

/** Default reporting groups when table is empty or missing. */
function coa_default_reporting_groups()
{
    return [
        ['name' => 'Current Assets', 'category_name' => 'Current Assets'],
        ['name' => 'Cash and Bank', 'category_name' => 'Current Assets'],
        ['name' => 'Accounts Receivable', 'category_name' => 'Current Assets'],
        ['name' => 'Inventory', 'category_name' => 'Current Assets'],
        ['name' => 'Non-Current Assets', 'category_name' => 'Non-Current Assets'],
        ['name' => 'Current Liabilities', 'category_name' => 'Current Liabilities'],
        ['name' => 'Accounts Payable', 'category_name' => 'Current Liabilities'],
        ['name' => 'VAT Payable', 'category_name' => 'Current Liabilities'],
        ['name' => 'Equity', 'category_name' => 'Equity'],
        ['name' => 'Sales Revenue', 'category_name' => 'Revenue'],
        ['name' => 'Other Income', 'category_name' => 'Revenue'],
        ['name' => 'Cost of Goods Sold', 'category_name' => 'Expense'],
        ['name' => 'Operating Expenses', 'category_name' => 'Expense'],
        ['name' => 'Payroll Expenses', 'category_name' => 'Expense'],
        ['name' => 'Finance Costs', 'category_name' => 'Expense'],
        ['name' => 'Tax Expense', 'category_name' => 'Expense'],
    ];
}

/**
 * Create COA reference tables and seed defaults when empty.
 */
function ensureCoaReferenceSchema(PDO $pdo)
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS erp_account_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL,
            name VARCHAR(255) NOT NULL,
            account_type VARCHAR(50) NOT NULL DEFAULT 'asset',
            financial_statement VARCHAR(80) NOT NULL DEFAULT 'Balance Sheet',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_coa_cat_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS erp_reporting_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL,
            name VARCHAR(255) NOT NULL,
            category_name VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_coa_rg_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureCoaReferenceSchema DDL: ' . $e->getMessage());
        return;
    }

    try {
        $cnt = (int) $pdo->query('SELECT COUNT(*) FROM erp_account_categories')->fetchColumn();
        if ($cnt === 0) {
            $ins = $pdo->prepare('INSERT INTO erp_account_categories (name, account_type, financial_statement, status) VALUES (?, ?, ?, ?)');
            foreach (coa_default_categories() as $row) {
                $ins->execute([$row['name'], $row['account_type'], $row['financial_statement'], $row['status']]);
            }
        }
    } catch (Throwable $e) {
        error_log('ensureCoaReferenceSchema categories seed: ' . $e->getMessage());
    }

    try {
        $cnt = (int) $pdo->query('SELECT COUNT(*) FROM erp_reporting_groups')->fetchColumn();
        if ($cnt === 0) {
            $ins = $pdo->prepare('INSERT INTO erp_reporting_groups (name, category_name, status) VALUES (?, ?, ?)');
            foreach (coa_default_reporting_groups() as $row) {
                $ins->execute([$row['name'], $row['category_name'], 'active']);
            }
        }
    } catch (Throwable $e) {
        error_log('ensureCoaReferenceSchema reporting seed: ' . $e->getMessage());
    }
}

/**
 * @return array<int,array<string,mixed>>
 */
function coa_load_categories(PDO $pdo)
{
    $rows = coa_load_financial_account_categories($pdo);
    if ($rows !== []) {
        return $rows;
    }

    ensureCoaReferenceSchema($pdo);
    try {
        $legacy = $pdo->query("SELECT * FROM erp_account_categories WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($legacy) && $legacy !== []) {
            return $legacy;
        }
    } catch (Throwable $e) {
        error_log('coa_load_categories legacy: ' . $e->getMessage());
    }

    return coa_default_categories();
}

/**
 * @return array<int,array<string,mixed>>
 */
function coa_load_reporting_groups(PDO $pdo)
{
    ensureFinancialAccountCategoriesSchema($pdo);
    $groups = [];
    try {
        $rows = $pdo->query("
            SELECT name AS category_name, reporting_group AS name
            FROM financial_account_categories
            WHERE status = 'Active' AND reporting_group <> ''
            ORDER BY display_order ASC, reporting_group ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows ?: [] as $row) {
            $groups[] = [
                'name' => (string) ($row['name'] ?? ''),
                'category_name' => (string) ($row['category_name'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        error_log('coa_load_reporting_groups financial: ' . $e->getMessage());
    }

    if ($groups !== []) {
        return $groups;
    }

    ensureCoaReferenceSchema($pdo);
    try {
        $rows = $pdo->query("SELECT name, category_name FROM erp_reporting_groups WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows) && $rows !== []) {
            return $rows;
        }
    } catch (Throwable $e) {
        error_log('coa_load_reporting_groups legacy: ' . $e->getMessage());
    }

    return coa_default_reporting_groups();
}

/**
 * Whether financial_accounts / account_transactions are scoped by company_id.
 * Tenant DBs are already isolated per company; legacy tables may lack the column.
 */
function balancesUseCompanyScope(?PDO $conn = null): bool
{
    if (defined('IS_TENANT_DB') && IS_TENANT_DB) {
        return false;
    }
    if ($conn === null) {
        global $pdo;
        $conn = ($pdo instanceof PDO) ? $pdo : null;
    }
    if (!($conn instanceof PDO)) {
        return false;
    }

    return function_exists('columnExists') && columnExists('financial_accounts', 'company_id', $conn);
}

function ensureBalancesSchema() {
    global $pdo;

    // Table: financial_accounts
    $sqlAccounts = "CREATE TABLE IF NOT EXISTS financial_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NULL,
        name VARCHAR(100) NOT NULL,
        type VARCHAR(50) NOT NULL,
        currency VARCHAR(3) DEFAULT 'TZS',
        opening_balance DECIMAL(15,2) DEFAULT 0.00,
        current_balance DECIMAL(15,2) DEFAULT 0.00,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    // Table: account_transactions
    $sqlTransactions = "CREATE TABLE IF NOT EXISTS account_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NULL,
        account_id INT NOT NULL,
        transaction_date DATETIME NOT NULL,
        type ENUM('credit', 'debit') NOT NULL, -- credit = inflow, debit = outflow
        amount DECIMAL(15,2) NOT NULL,
        reference_type VARCHAR(50) DEFAULT NULL, -- e.g. 'invoice_payment', 'expense_voucher'
        reference_id INT DEFAULT NULL,
        description TEXT,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sqlAccounts);
    } catch (Throwable $e) {
        error_log('Balances Schema financial_accounts: ' . $e->getMessage());
    }

    try {
        $pdo->exec($sqlTransactions);
    } catch (Throwable $e) {
        error_log('Balances Schema account_transactions (FK): ' . $e->getMessage());
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS account_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NULL,
                account_id INT NOT NULL,
                transaction_date DATETIME NOT NULL,
                type ENUM('credit', 'debit') NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                reference_type VARCHAR(50) DEFAULT NULL,
                reference_id INT DEFAULT NULL,
                description TEXT,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_acct_tx_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e2) {
            error_log('Balances Schema account_transactions (no FK): ' . $e2->getMessage());
        }
    }

    try {
        if (function_exists('columnExists') && !columnExists('financial_accounts', 'company_id', $pdo)) {
            $pdo->exec('ALTER TABLE financial_accounts ADD COLUMN company_id INT NULL AFTER id');
        }
        if (function_exists('columnExists') && !columnExists('account_transactions', 'company_id', $pdo)) {
            $pdo->exec('ALTER TABLE account_transactions ADD COLUMN company_id INT NULL AFTER id');
        }
        if (function_exists('columnExists') && !columnExists('financial_accounts', 'account_image', $pdo)) {
            $pdo->exec('ALTER TABLE financial_accounts ADD COLUMN account_image VARCHAR(500) NULL DEFAULT NULL AFTER status');
        }
        if (function_exists('columnExists') && !columnExists('financial_accounts', 'gl_account_id', $pdo)) {
            $faCols = $pdo->query('SHOW COLUMNS FROM financial_accounts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $afterGl = in_array('parent_id', $faCols, true) ? ' AFTER parent_id' : '';
            $pdo->exec('ALTER TABLE financial_accounts ADD COLUMN gl_account_id INT NULL DEFAULT NULL' . $afterGl);
        }
    } catch (Throwable $e) {
        error_log('Balances Schema alter company_id: ' . $e->getMessage());
    }
}

/**
 * Update the current balance of an account based on its transaction history
 * Optionally optimized to just add/subtract the new amount if trusted,
 * but recalculating from history is safer for consistency.
 */
function recalculateBalance($accountId) {
    global $pdo;
    $scoped = balancesUseCompanyScope();
    $companyId = (int) (currentCompanyId() ?? 0);
    if ($scoped && $companyId <= 0) {
        return false;
    }

    // Get opening balance
    if ($scoped) {
        $stmt = $pdo->prepare("SELECT opening_balance FROM financial_accounts WHERE id = ? AND company_id = ?");
        $stmt->execute([$accountId, $companyId]);
    } else {
        $stmt = $pdo->prepare("SELECT opening_balance FROM financial_accounts WHERE id = ?");
        $stmt->execute([$accountId]);
    }
    $account = $stmt->fetch();
    if (!$account) return false;
    
    $opening = $account['opening_balance'];
    
    // Sum inflows (credits)
    if ($scoped) {
        $stmtIn = $pdo->prepare("SELECT SUM(amount) FROM account_transactions WHERE account_id = ? AND company_id = ? AND type = 'credit'");
        $stmtIn->execute([$accountId, $companyId]);
    } else {
        $stmtIn = $pdo->prepare("SELECT SUM(amount) FROM account_transactions WHERE account_id = ? AND type = 'credit'");
        $stmtIn->execute([$accountId]);
    }
    $inflow = $stmtIn->fetchColumn() ?: 0;
    
    // Sum outflows (debits)
    if ($scoped) {
        $stmtOut = $pdo->prepare("SELECT SUM(amount) FROM account_transactions WHERE account_id = ? AND company_id = ? AND type = 'debit'");
        $stmtOut->execute([$accountId, $companyId]);
    } else {
        $stmtOut = $pdo->prepare("SELECT SUM(amount) FROM account_transactions WHERE account_id = ? AND type = 'debit'");
        $stmtOut->execute([$accountId]);
    }
    $outflow = $stmtOut->fetchColumn() ?: 0;
    
    $newBalance = $opening + $inflow - $outflow;
    
    // Update account
    if ($scoped) {
        $update = $pdo->prepare("UPDATE financial_accounts SET current_balance = ? WHERE id = ? AND company_id = ?");
        $update->execute([$newBalance, $accountId, $companyId]);
    } else {
        $update = $pdo->prepare("UPDATE financial_accounts SET current_balance = ? WHERE id = ?");
        $update->execute([$newBalance, $accountId]);
    }

    // Tracing
    error_log("Balances Recalculate: Account ID $accountId | Opening $opening | Inflow $inflow | Outflow $outflow | New $newBalance");
    
    return $newBalance;
}

/**
 * Record a new transaction and update balance
 */
function balancesSyncGlobalPdo(?PDO $conn = null): ?PDO
{
    if ($conn === null && function_exists('balances_resolve_pdo')) {
        $conn = balances_resolve_pdo();
    }
    if ($conn instanceof PDO) {
        $GLOBALS['pdo'] = $conn;
        return $conn;
    }
    global $pdo;
    return ($pdo instanceof PDO) ? $pdo : null;
}

/**
 * Insert a ledger row on the Balances PDO. Safe inside an outer DB transaction.
 */
function balancesRecordTransaction(
    PDO $pdo,
    int $accountId,
    string $type,
    float $amount,
    string $description,
    ?string $refType = null,
    ?int $refId = null,
    ?string $date = null,
    ?int $companyId = null
): bool {
    $date = $date ?: date('Y-m-d H:i:s');
    $userId = $_SESSION['user_id'] ?? null;
    $scoped = balancesUseCompanyScope();
    $companyId = (int) ($companyId ?: (currentCompanyId() ?? 0));
    if ($accountId <= 0 || $amount <= 0) {
        return false;
    }
    if ($scoped && $companyId <= 0) {
        error_log('balancesRecordTransaction: company scope enabled but company_id missing');
        return false;
    }

    try {
        if ($scoped) {
            $stmt = $pdo->prepare(
                'INSERT INTO account_transactions
                (company_id, account_id, transaction_date, type, amount, reference_type, reference_id, description, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$companyId, $accountId, $date, $type, $amount, $refType, $refId, $description, $userId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO account_transactions
                (account_id, transaction_date, type, amount, reference_type, reference_id, description, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$accountId, $date, $type, $amount, $refType, $refId, $description, $userId]);
        }

        if (!$pdo->inTransaction()) {
            balancesRecalculateAccount($pdo, $accountId, $companyId > 0 ? $companyId : null);
        }

        return true;
    } catch (Throwable $e) {
        error_log('balancesRecordTransaction: ' . $e->getMessage());
        return false;
    }
}

/**
 * Recalculate financial_accounts.current_balance using a specific PDO connection.
 */
function balancesRecalculateAccount(PDO $pdo, int $accountId, ?int $companyId = null): bool
{
    $prev = $GLOBALS['pdo'] ?? null;
    $GLOBALS['pdo'] = $pdo;
    try {
        return (bool) recalculateBalance($accountId);
    } finally {
        if ($prev instanceof PDO) {
            $GLOBALS['pdo'] = $prev;
        }
    }
}

function recordTransaction($accountId, $type, $amount, $description, $refType = null, $refId = null, $date = null, $companyId = null) {
    global $pdo;
    $conn = balancesSyncGlobalPdo($pdo instanceof PDO ? $pdo : null);
    if (!($conn instanceof PDO)) {
        return false;
    }

    if ($conn->inTransaction()) {
        return balancesRecordTransaction(
            $conn,
            (int) $accountId,
            (string) $type,
            (float) $amount,
            (string) $description,
            $refType !== null ? (string) $refType : null,
            $refId !== null ? (int) $refId : null,
            $date !== null ? (string) $date : null,
            $companyId !== null ? (int) $companyId : null
        );
    }

    $useTransaction = !$conn->inTransaction();
    
    try {
        if ($useTransaction) {
            $conn->beginTransaction();
        }

        $ok = balancesRecordTransaction(
            $conn,
            (int) $accountId,
            (string) $type,
            (float) $amount,
            (string) $description,
            $refType !== null ? (string) $refType : null,
            $refId !== null ? (int) $refId : null,
            $date !== null ? (string) $date : null,
            $companyId !== null ? (int) $companyId : null
        );
        if (!$ok) {
            throw new RuntimeException('balancesRecordTransaction failed');
        }

        if ($useTransaction) {
            $conn->commit();
        }
        return true;
    } catch (Exception $e) {
        if ($useTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("recordTransaction error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all active accounts with balances
 */
function getBalances() {
    global $pdo;

    try {
        $conn = balancesSyncGlobalPdo($pdo instanceof PDO ? $pdo : null);
        if (!($conn instanceof PDO)) {
            return [];
        }

        $accounts = balancesFetchAccountsWithLiveBalance($conn, true);
        foreach ($accounts as &$acc) {
            if (isset($acc['live_balance'])) {
                $acc['current_balance'] = (float) $acc['live_balance'];
            }
        }
        unset($acc);

        return $accounts;
    } catch (Throwable $e) {
        error_log('getBalances: ' . $e->getMessage());
        return [];
    }
}

/**
 * Build a financial_account_types slug from a display name (lowercase, underscores).
 */
function balances_slugify_label(string $label): string
{
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
    return trim((string) $slug, '_');
}

/**
 * Default chart-of-accounts account types (slug => row).
 *
 * @return array<string, array{slug:string,label:string,code_range_min:int,code_range_max:int,display_order:int}>
 */
function balances_default_account_types(): array
{
    return [
        'asset' => ['slug' => 'asset', 'label' => 'Asset', 'code_range_min' => 1000, 'code_range_max' => 1999, 'display_order' => 10],
        'cash' => ['slug' => 'cash', 'label' => 'Cash', 'code_range_min' => 1000, 'code_range_max' => 1999, 'display_order' => 20],
        'bank' => ['slug' => 'bank', 'label' => 'Bank', 'code_range_min' => 1000, 'code_range_max' => 1999, 'display_order' => 30],
        'mobile' => ['slug' => 'mobile', 'label' => 'Mobile', 'code_range_min' => 1000, 'code_range_max' => 1999, 'display_order' => 40],
        'liability' => ['slug' => 'liability', 'label' => 'Liability', 'code_range_min' => 2000, 'code_range_max' => 2999, 'display_order' => 50],
        'equity' => ['slug' => 'equity', 'label' => 'Equity', 'code_range_min' => 3000, 'code_range_max' => 3999, 'display_order' => 60],
        'revenue' => ['slug' => 'revenue', 'label' => 'Revenue', 'code_range_min' => 4000, 'code_range_max' => 4999, 'display_order' => 70],
        'expense' => ['slug' => 'expense', 'label' => 'Expense', 'code_range_min' => 5000, 'code_range_max' => 5999, 'display_order' => 80],
    ];
}

function balances_ensure_account_types_schema(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS financial_account_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(50) NOT NULL,
                label VARCHAR(80) NOT NULL,
                code_range_min INT NOT NULL DEFAULT 1000,
                code_range_max INT NOT NULL DEFAULT 1999,
                status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
                display_order INT NOT NULL DEFAULT 10,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_fin_acct_type_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('balances_ensure_account_types_schema: ' . $e->getMessage());
        return;
    }

    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM financial_account_types')->fetchColumn();
    } catch (Throwable $e) {
        return;
    }

    if ($count > 0) {
        return;
    }

    $ins = $pdo->prepare('
        INSERT INTO financial_account_types (slug, label, code_range_min, code_range_max, status, display_order)
        VALUES (?, ?, ?, ?, \'Active\', ?)
    ');
    foreach (balances_default_account_types() as $row) {
        try {
            $ins->execute([
                $row['slug'],
                $row['label'],
                (int) $row['code_range_min'],
                (int) $row['code_range_max'],
                (int) $row['display_order'],
            ]);
        } catch (Throwable $e) {
            // ignore duplicate seed
        }
    }
}

/**
 * @return list<array{slug:string,label:string,code_range_min:int,code_range_max:int,status:string,display_order:int}>
 */
function balances_fetch_account_types(PDO $pdo, bool $activeOnly = true): array
{
    balances_ensure_account_types_schema($pdo);

    $sql = 'SELECT slug, label, code_range_min, code_range_max, status, display_order FROM financial_account_types';
    if ($activeOnly) {
        $sql .= " WHERE status = 'Active'";
    }
    $sql .= ' ORDER BY display_order ASC, label ASC';

    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    if ($rows !== []) {
        return $rows;
    }

    return array_values(balances_default_account_types());
}

/**
 * @return array{0:int,1:int}|null
 */
function balances_account_type_code_range(PDO $pdo, string $slug): ?array
{
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return null;
    }

    balances_ensure_account_types_schema($pdo);
    try {
        $st = $pdo->prepare('SELECT code_range_min, code_range_max FROM financial_account_types WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [(int) $row['code_range_min'], (int) $row['code_range_max']];
        }
    } catch (Throwable $e) {
        // fall through
    }

    $defaults = balances_default_account_types();
    if (isset($defaults[$slug])) {
        return [(int) $defaults[$slug]['code_range_min'], (int) $defaults[$slug]['code_range_max']];
    }

    return null;
}

/**
 * @return list<string>
 */
function balances_account_type_slugs(PDO $pdo, bool $activeOnly = true): array
{
    $types = balances_fetch_account_types($pdo, $activeOnly);
    return array_values(array_map(static fn($t) => (string) ($t['slug'] ?? ''), $types));
}

/**
 * Ensure system default sub-accounts exist and are locked from editing/deleting.
 */
function balances_ensure_system_locked_accounts(PDO $pdo): void
{
    try {
        // 1. Ensure `is_system` column exists
        if (function_exists('columnExists') && !columnExists('financial_accounts', 'is_system', $pdo)) {
            try {
                $faCols = $pdo->query('SHOW COLUMNS FROM financial_accounts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $after = in_array('gl_account_id', $faCols, true) ? ' AFTER gl_account_id' : '';
                $pdo->exec('ALTER TABLE financial_accounts ADD COLUMN is_system TINYINT DEFAULT 0' . $after);
            } catch (Throwable $e) {
                error_log('Failed to add is_system column: ' . $e->getMessage());
            }
        }

        coa_ensure_parent_id_column($pdo);

        // 2. Find parent Assets (1000) account
        $parentStmt = $pdo->query("SELECT id FROM financial_accounts WHERE name = '1000 - Assets' AND (parent_id IS NULL OR parent_id = 0) LIMIT 1");
        $parentId = $parentStmt ? (int) $parentStmt->fetchColumn() : 0;
        if ($parentId <= 0) {
            $parentStmt = $pdo->query("SELECT id FROM financial_accounts WHERE name LIKE '%Assets%' AND (parent_id IS NULL OR parent_id = 0) LIMIT 1");
            $parentId = $parentStmt ? (int) $parentStmt->fetchColumn() : 0;
        }
        if ($parentId <= 0) {
            $parentStmt = $pdo->query("SELECT id FROM financial_accounts WHERE name LIKE '%1000%' AND (parent_id IS NULL OR parent_id = 0) LIMIT 1");
            $parentId = $parentStmt ? (int) $parentStmt->fetchColumn() : 0;
        }
        if ($parentId <= 0) {
            return;
        }

        // 3. Define default system sub-accounts
        $systemSubs = [
            [
                'code' => '1002',
                'name' => 'Accounts Receivable',
                'description' => 'Money customers owe you for products or services already delivered',
                'type' => 'asset',
            ],
            [
                'code' => '1005',
                'name' => 'Undeposited Funds',
                'description' => 'Payments received but not yet deposited into a bank account',
                'type' => 'asset',
            ],
            [
                'code' => '1008',
                'name' => 'CRDB',
                'description' => 'Things your business owns that have value',
                'type' => 'asset',
            ],
        ];

        foreach ($systemSubs as $sub) {
            $fullName = $sub['code'] . ' - ' . $sub['name'];
            $findStmt = $pdo->prepare("SELECT id FROM financial_accounts WHERE name = ? OR name = ?");
            $findStmt->execute([$fullName, $sub['name']]);
            $existingId = (int) $findStmt->fetchColumn();

            if ($existingId > 0) {
                $pdo->prepare("UPDATE financial_accounts SET is_system = 1, parent_id = ? WHERE id = ?")->execute([$parentId, $existingId]);
            } else {
                $insertStmt = $pdo->prepare("
                    INSERT INTO financial_accounts (name, type, currency, opening_balance, current_balance, status, parent_id, is_system)
                    VALUES (?, ?, 'TZS', 0.00, 0.00, 'active', ?, 1)
                ");
                $insertStmt->execute([$fullName, $sub['type'], $parentId]);
            }
        }
    } catch (Throwable $e) {
        error_log('balances_ensure_system_locked_accounts: ' . $e->getMessage());
    }
}
?>
