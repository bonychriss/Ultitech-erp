<?php

/**
 * Connect expenses module to Chart of Accounts (financial_accounts / balances).
 */

function expenses_balances_bootstrap(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $path = dirname(__DIR__, 2) . '/balances/functions.php';
    if (is_file($path)) {
        require_once $path;
    }
    global $pdo;
    if ($pdo instanceof PDO && function_exists('coa_ensure_parent_id_column')) {
        coa_ensure_parent_id_column($pdo);
    }
    // Create form categories come from financial_accounts; seed default COA when empty
    // so Category / Paid from are available without visiting Balances first.
    if ($pdo instanceof PDO && function_exists('balances_ensure_default_accounts')) {
        try {
            $companyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
            balances_ensure_default_accounts($pdo, $companyId > 0 ? $companyId : null);
        } catch (Throwable $e) {
            error_log('expenses_balances_bootstrap seed: ' . $e->getMessage());
        }
    }
    if ($pdo instanceof PDO && function_exists('expenses_ensure_schema')) {
        expenses_ensure_schema($pdo);
    }
    $loaded = true;
}

/**
 * erp_expenses.account_id stores Chart of Accounts (financial_accounts) IDs.
 * Create the table when missing (new tenants), then apply column/FK upgrades.
 */
function expenses_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $tableExists = false;
    try {
        $pdo->query('SELECT 1 FROM erp_expenses LIMIT 1');
        $tableExists = true;
    } catch (Throwable $e) {
        $tableExists = false;
    }

    if (!$tableExists) {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS erp_expenses (
                    id INT NOT NULL AUTO_INCREMENT,
                    company_id INT NULL DEFAULT NULL,
                    pv_id INT NULL DEFAULT NULL,
                    source_type ENUM('receipt','voucher') NOT NULL DEFAULT 'receipt',
                    expense_number VARCHAR(255) NULL DEFAULT NULL,
                    date DATE NULL DEFAULT NULL,
                    payee VARCHAR(255) NULL DEFAULT NULL,
                    account_id INT NULL DEFAULT NULL,
                    source_account_id INT NULL DEFAULT NULL,
                    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    currency_code VARCHAR(10) NOT NULL DEFAULT 'TSh',
                    payment_method VARCHAR(255) NULL DEFAULT NULL,
                    description TEXT NULL,
                    attachment VARCHAR(255) NULL DEFAULT NULL,
                    status ENUM('draft','pending','approved','rejected','deleted') NOT NULL DEFAULT 'pending',
                    is_posted TINYINT(1) NOT NULL DEFAULT 0,
                    created_by INT NULL DEFAULT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    approved_by INT NULL DEFAULT NULL,
                    approved_at TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_erp_expenses_date (date),
                    KEY idx_erp_expenses_status (status),
                    KEY idx_erp_expenses_account (account_id),
                    KEY idx_erp_expenses_source_account (source_account_id),
                    KEY idx_erp_expenses_pv (pv_id),
                    KEY idx_erp_expenses_posted (is_posted)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $tableExists = true;
        } catch (Throwable $e) {
            error_log('expenses_ensure_schema create: ' . $e->getMessage());
            $ensured = true;

            return;
        }
    }

    if (!$tableExists) {
        $ensured = true;

        return;
    }

    if (function_exists('ensureExpenseColumns')) {
        ensureExpenseColumns();
    } else {
        expenses_ensure_voucher_link_columns($pdo);
    }

    try {
        $dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
        if ($dbName === '') {
            expenses_ensure_draft_status_enum($pdo);
            expenses_ensure_approval_columns($pdo);
            $ensured = true;

            return;
        }

        $stmt = $pdo->prepare("
            SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = 'erp_expenses'
              AND COLUMN_NAME = 'account_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute([$dbName]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $refTable = strtolower((string) ($row['REFERENCED_TABLE_NAME'] ?? ''));
            if ($refTable !== 'erp_accounts') {
                continue;
            }
            $fkName = str_replace('`', '``', (string) ($row['CONSTRAINT_NAME'] ?? ''));
            if ($fkName === '') {
                continue;
            }
            $pdo->exec('ALTER TABLE erp_expenses DROP FOREIGN KEY `' . $fkName . '`');
        }
    } catch (Throwable $e) {
        error_log('expenses_ensure_schema: ' . $e->getMessage());
    }

    expenses_ensure_draft_status_enum($pdo);
    expenses_ensure_approval_columns($pdo);

    $ensured = true;
}

/**
 * Ensure erp_expenses has voucher-link columns used to separate receipts from payment vouchers.
 */
function expenses_ensure_voucher_link_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $col = $pdo->query("SHOW COLUMNS FROM erp_expenses LIKE 'pv_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec('ALTER TABLE erp_expenses ADD COLUMN pv_id INT NULL AFTER id');
        }

        $col = $pdo->query("SHOW COLUMNS FROM erp_expenses LIKE 'source_type'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec("ALTER TABLE erp_expenses ADD COLUMN source_type ENUM('receipt', 'voucher') DEFAULT 'receipt' AFTER pv_id");
        }

        $col = $pdo->query("SHOW COLUMNS FROM erp_expenses LIKE 'is_posted'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec('ALTER TABLE erp_expenses ADD COLUMN is_posted TINYINT(1) DEFAULT 0 AFTER status');
        }
    } catch (Throwable $e) {
        error_log('expenses_ensure_voucher_link_columns: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * Ensure erp_expenses has approval audit columns used when posting expenses.
 */
function expenses_ensure_approval_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $col = $pdo->query("SHOW COLUMNS FROM erp_expenses LIKE 'approved_by'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec('ALTER TABLE erp_expenses ADD COLUMN approved_by INT NULL');
        }

        $col = $pdo->query("SHOW COLUMNS FROM erp_expenses LIKE 'approved_at'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec('ALTER TABLE erp_expenses ADD COLUMN approved_at TIMESTAMP NULL');
        }
    } catch (Throwable $e) {
        error_log('expenses_ensure_approval_columns: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * Ensure erp_expenses.status supports draft and normalize legacy blank rows.
 */
function expenses_ensure_draft_status_enum(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $col = $pdo->query("SHOW COLUMNS FROM erp_expenses LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = strtolower((string) ($col['Type'] ?? ''));
        if ($type !== '' && strpos($type, 'draft') === false) {
            $pdo->exec(
                "ALTER TABLE erp_expenses MODIFY COLUMN status "
                . "ENUM('draft','pending','approved','rejected','deleted') NOT NULL DEFAULT 'pending'"
            );
        }

        $pdo->exec(
            "UPDATE erp_expenses SET status = 'draft'
             WHERE is_posted = 0
               AND (status IS NULL OR TRIM(status) = '')"
        );
        $pdo->exec(
            "UPDATE erp_expenses SET status = 'draft'
             WHERE is_posted = 0
               AND status = 'pending'
               AND (pv_id IS NULL OR pv_id = 0)
               AND (source_type IS NULL OR source_type = '' OR source_type = 'receipt')"
        );
    } catch (Throwable $e) {
        error_log('expenses_ensure_draft_status_enum: ' . $e->getMessage());
    }

    $done = true;
}

function expenses_is_payment_account_type(string $type): bool
{
    $t = strtolower(trim($type));
    if (function_exists('coa_normalize_ledger_type')) {
        $t = coa_normalize_ledger_type($type);
    }

    return in_array($t, ['asset', 'cash', 'bank', 'mobile'], true)
        || in_array(strtolower(trim($type)), ['cash', 'bank', 'mobile'], true);
}

function expenses_is_expense_account_type(string $type): bool
{
    $normalized = function_exists('coa_normalize_ledger_type')
        ? coa_normalize_ledger_type($type)
        : strtolower(trim($type));

    return in_array($normalized, ['expense', 'expenses'], true);
}

/**
 * @return array<int, array<string, mixed>>
 */
function expenses_fetch_financial_account_map(PDO $pdo): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    expenses_balances_bootstrap();

    $map = [];
    foreach (expenses_fetch_financial_accounts_direct($pdo) as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = $row;
        }
    }

    // Merge company-scoped rows (live balances) without dropping shared / NULL company_id accounts.
    if (function_exists('balancesFetchAccountsWithLiveBalance')) {
        foreach (balancesFetchAccountsWithLiveBalance($pdo, true) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $map[$id] = array_merge($map[$id] ?? [], $row);
            }
        }
    }

    $cached = $map;

    return $cached;
}

/**
 * Load active financial accounts when scoped fetch returns nothing (e.g. NULL company_id).
 *
 * @return list<array<string, mixed>>
 */
function expenses_fetch_financial_accounts_direct(PDO $pdo): array
{
    $companyId = (int) (currentCompanyId() ?? 0);
    $scoped = function_exists('balancesUseCompanyScope') && balancesUseCompanyScope();

    try {
        if ($scoped && $companyId > 0) {
            $stmt = $pdo->prepare("
                SELECT fa.*,
                    COALESCE(fa.opening_balance, 0) AS opening_balance_safe,
                    (COALESCE(fa.opening_balance, 0)
                        + COALESCE((SELECT SUM(amount) FROM account_transactions t WHERE t.account_id = fa.id AND t.type = 'credit'), 0)
                        - COALESCE((SELECT SUM(amount) FROM account_transactions t WHERE t.account_id = fa.id AND t.type = 'debit'), 0)
                    ) AS live_balance
                FROM financial_accounts fa
                WHERE fa.status = 'active'
                  AND (fa.company_id IS NULL OR fa.company_id = 0 OR fa.company_id = ?)
                ORDER BY fa.name
            ");
            $stmt->execute([$companyId]);
        } else {
            $stmt = $pdo->query("
                SELECT fa.*,
                    COALESCE(fa.opening_balance, 0) AS opening_balance_safe,
                    (COALESCE(fa.opening_balance, 0)
                        + COALESCE((SELECT SUM(amount) FROM account_transactions t WHERE t.account_id = fa.id AND t.type = 'credit'), 0)
                        - COALESCE((SELECT SUM(amount) FROM account_transactions t WHERE t.account_id = fa.id AND t.type = 'debit'), 0)
                    ) AS live_balance
                FROM financial_accounts fa
                WHERE fa.status = 'active'
                ORDER BY fa.name
            ");
        }

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function expenses_row_is_under_asset_tree(array $row, array $map): bool
{
    $parentId = (int) ($row['parent_id'] ?? 0);
    if ($parentId <= 0) {
        return false;
    }
    $parent = $map[$parentId] ?? null;
    if (!$parent) {
        return false;
    }
    if (expenses_is_payment_account_type((string) ($parent['type'] ?? ''))) {
        return true;
    }
    if (function_exists('coa_parse_account_name_parts')) {
        [$parentCode] = coa_parse_account_name_parts((string) ($parent['name'] ?? ''));
        if ($parentCode === '1000') {
            return true;
        }
    }

    return expenses_row_is_under_asset_tree($parent, $map);
}

function expenses_is_petty_cash_account_row(array $row): bool
{
    $name = strtolower(trim((string) ($row['name'] ?? '')));

    return $name !== '' && preg_match('/petty\s*cash|\bpetty\b/', $name) === 1;
}

/**
 * @return list<int>
 */
function expenses_petty_cash_account_ids(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $ids = [];
    foreach (expenses_fetch_financial_account_map($pdo) as $id => $row) {
        if (expenses_is_petty_cash_account_row($row)) {
            $ids[] = (int) $id;
        }
    }

    $cache = $ids;

    return $cache;
}

function expenses_row_is_payment_candidate(array $row, array $map): bool
{
    if (expenses_is_petty_cash_account_row($row)) {
        return false;
    }

    $type = (string) ($row['type'] ?? '');
    if (expenses_is_payment_account_type($type)) {
        if (function_exists('coa_normalize_ledger_type') && coa_normalize_ledger_type($type) === 'expense') {
            return false;
        }

        return true;
    }

    return expenses_row_is_under_asset_tree($row, $map);
}

function expenses_account_row_label(array $row, ?array $parentRow = null): string
{
    $name = trim((string) ($row['name'] ?? ''));
    if ($parentRow) {
        $parentName = trim((string) ($parentRow['name'] ?? ''));
        if ($parentName !== '') {
            $name = $parentName . ' / ' . $name;
        }
    }

    return $name;
}

/**
 * @return array{mains:list<array>,children:array<int,list<array>>,hierarchical:bool}
 */
function expenses_build_expense_account_tree(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $map = expenses_fetch_financial_account_map($pdo);
    $mains = [];
    $children = [];
    $hasChildren = false;

    foreach ($map as $id => $row) {
        if (!expenses_is_expense_account_type((string) ($row['type'] ?? ''))) {
            continue;
        }

        $parentId = (int) ($row['parent_id'] ?? 0);
        $item = [
            'id' => $id,
            'label' => expenses_account_row_label($row, null),
            'type' => (string) ($row['type'] ?? 'expense'),
            'parent_id' => $parentId,
            'balance' => isset($row['live_balance']) ? (float) $row['live_balance'] : (float) ($row['current_balance'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
        ];

        if ($parentId <= 0) {
            $mains[] = $item;
            continue;
        }

        $hasChildren = true;
        $children[$parentId][] = $item;
    }

    $sortFn = static function (array $a, array $b): int {
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    };
    usort($mains, $sortFn);
    foreach ($children as $parentId => $rows) {
        usort($children[$parentId], $sortFn);
    }

    $cache = [
        'mains' => $mains,
        'children' => $children,
        'hierarchical' => $hasChildren && $mains !== [],
    ];

    return $cache;
}

function expenses_expense_accounts_are_hierarchical(PDO $pdo): bool
{
    return expenses_build_expense_account_tree($pdo)['hierarchical'];
}

/**
 * Top-level expense categories (e.g. 5000 Expenses, 6000 COGS).
 *
 * @return list<array{id:int,label:string,type:string,parent_id:int,balance:float,name:string}>
 */
function expenses_fetch_expense_main_accounts(PDO $pdo): array
{
    return expenses_build_expense_account_tree($pdo)['mains'];
}

/**
 * Expense sub-accounts under a main category.
 *
 * @return list<array{id:int,label:string,type:string,parent_id:int,balance:float,name:string}>
 */
function expenses_fetch_expense_sub_accounts_for_parent(PDO $pdo, int $parentId): array
{
    if ($parentId <= 0) {
        return [];
    }

    return expenses_build_expense_account_tree($pdo)['children'][$parentId] ?? [];
}

function expenses_validate_expense_sub_account(PDO $pdo, int $subAccountId, ?int $mainAccountId = null): bool
{
    if ($subAccountId <= 0) {
        return false;
    }

    $row = expenses_resolve_financial_account($pdo, $subAccountId);
    if (!$row || !expenses_is_expense_account_type((string) ($row['type'] ?? ''))) {
        return false;
    }

    $parentId = (int) ($row['parent_id'] ?? 0);
    if (expenses_expense_accounts_are_hierarchical($pdo)) {
        if ($parentId <= 0) {
            return false;
        }
        if ($mainAccountId !== null && $mainAccountId > 0 && $parentId !== $mainAccountId) {
            return false;
        }
    }

    return true;
}

/**
 * Expense accounts from balances COA (sub-accounts when hierarchical, else all expense rows).
 *
 * @return list<array{id:int,label:string,type:string,parent_id:int,balance:float}>
 */
function expenses_fetch_expense_sub_accounts(PDO $pdo): array
{
    $tree = expenses_build_expense_account_tree($pdo);
    $options = [];

    if ($tree['hierarchical']) {
        foreach ($tree['children'] as $rows) {
            foreach ($rows as $row) {
                $options[] = $row;
            }
        }
        if ($options !== []) {
            usort($options, static function ($a, $b) {
                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });

            return $options;
        }
        // Hierarchical parents exist but no leaf accounts yet — allow selecting mains.
        return array_values($tree['mains']);
    }

    $map = expenses_fetch_financial_account_map($pdo);
    foreach ($map as $id => $row) {
        if (!expenses_is_expense_account_type((string) ($row['type'] ?? ''))) {
            continue;
        }

        $parentId = (int) ($row['parent_id'] ?? 0);
        $parentRow = $parentId > 0 ? ($map[$parentId] ?? null) : null;
        $options[] = [
            'id' => $id,
            'label' => expenses_account_row_label($row, $parentRow),
            'type' => (string) ($row['type'] ?? 'expense'),
            'parent_id' => $parentId,
            'balance' => isset($row['live_balance']) ? (float) $row['live_balance'] : (float) ($row['current_balance'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }

    usort($options, static function ($a, $b) {
        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    return $options;
}

/**
 * Direct SQL load for payment accounts when map-based filtering returns nothing.
 *
 * @return list<array<string, mixed>>
 */
function expenses_fetch_payment_accounts_direct(PDO $pdo): array
{
    $companyId = (int) (currentCompanyId() ?? 0);
    $scoped = function_exists('balancesUseCompanyScope') && balancesUseCompanyScope();
    $paymentTypes = ['bank', 'cash', 'mobile', 'asset'];

    try {
        if ($scoped && $companyId > 0) {
            $placeholders = implode(',', array_fill(0, count($paymentTypes), '?'));
            $stmt = $pdo->prepare("
                SELECT fa.*,
                    COALESCE(fa.opening_balance, 0) AS opening_balance_safe,
                    (COALESCE(fa.opening_balance, 0)
                        + COALESCE((SELECT SUM(amount) FROM account_transactions t WHERE t.account_id = fa.id AND t.type = 'credit'), 0)
                        - COALESCE((SELECT SUM(amount) FROM account_transactions t WHERE t.account_id = fa.id AND t.type = 'debit'), 0)
                    ) AS live_balance
                FROM financial_accounts fa
                WHERE fa.status = 'active'
                  AND LOWER(fa.type) IN ($placeholders)
                  AND (fa.company_id IS NULL OR fa.company_id = 0 OR fa.company_id = ?)
                ORDER BY fa.name
            ");
            $stmt->execute(array_merge($paymentTypes, [$companyId]));
        } else {
            $placeholders = implode(',', array_fill(0, count($paymentTypes), '?'));
            $stmt = $pdo->prepare("
                SELECT fa.*,
                    COALESCE(fa.opening_balance, 0) AS opening_balance_safe,
                    (COALESCE(fa.opening_balance, 0)
                        + COALESCE((SELECT SUM(amount) FROM account_transactions t WHERE t.account_id = fa.id AND t.type = 'credit'), 0)
                        - COALESCE((SELECT SUM(amount) FROM account_transactions t WHERE t.account_id = fa.id AND t.type = 'debit'), 0)
                    ) AS live_balance
                FROM financial_accounts fa
                WHERE fa.status = 'active'
                  AND LOWER(fa.type) IN ($placeholders)
                ORDER BY fa.name
            ");
            $stmt->execute($paymentTypes);
        }

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function expenses_row_is_payment_source_candidate(array $row, array $map): bool
{
    if (expenses_is_petty_cash_account_row($row)) {
        return false;
    }

    $type = strtolower(trim((string) ($row['type'] ?? '')));
    $name = strtolower(trim((string) ($row['name'] ?? '')));

    if (preg_match('/receivable|a\/r\b|prepay|inventory|stock|fixed asset|equipment|depreciation|land|building|vehicle/', $name)) {
        return false;
    }

    if (in_array($type, ['bank', 'cash', 'mobile'], true)) {
        return true;
    }

    if ($type === 'asset' && preg_match('/bank|crdb|nmb|uba|cash|mobile|mpesa|tigo|airtel|undeposited/', $name)) {
        return expenses_row_is_payment_candidate($row, $map);
    }

    return false;
}

function expenses_find_asset_main_account_id(array $map): int
{
    foreach ($map as $id => $row) {
        if ((int) ($row['parent_id'] ?? 0) > 0) {
            continue;
        }
        if (strtolower((string) ($row['type'] ?? '')) !== 'asset') {
            continue;
        }
        if (function_exists('coa_parse_account_name_parts')) {
            [$code] = coa_parse_account_name_parts((string) ($row['name'] ?? ''));
            if ($code === '1000') {
                return (int) $id;
            }
        }
        if (stripos((string) ($row['name'] ?? ''), 'asset') !== false) {
            return (int) $id;
        }
    }

    return 0;
}

function expenses_payment_account_kind(array $row): string
{
    if (expenses_is_petty_cash_account_row($row)) {
        return 'petty';
    }

    $type = strtolower(trim((string) ($row['type'] ?? '')));
    if ($type === 'cash') {
        return 'cash';
    }
    if ($type === 'mobile') {
        return 'mobile';
    }
    if ($type === 'bank') {
        return 'bank';
    }

    $name = strtolower(trim((string) ($row['name'] ?? '')));
    if (preg_match('/\bcash\b/', $name)) {
        return 'cash';
    }
    if (preg_match('/mobile|mpesa|m-?pesa|tigo|airtel/', $name)) {
        return 'mobile';
    }

    return 'bank';
}

/**
 * @return array{mains:list<array>,children:array<int,list<array>>,hierarchical:bool}
 */
function expenses_build_payment_account_tree(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $map = expenses_fetch_financial_account_map($pdo);
    $mains = [];
    $children = [];
    $hasChildren = false;

    foreach ($map as $id => $row) {
        $parentId = (int) ($row['parent_id'] ?? 0);
        $type = strtolower((string) ($row['type'] ?? ''));
        if ($parentId <= 0 && $type === 'asset') {
            $mains[] = [
                'id' => $id,
                'label' => expenses_account_row_label($row, null),
                'name' => (string) ($row['name'] ?? ''),
            ];
        }
    }

    foreach ($map as $id => $row) {
        if (!expenses_row_is_payment_source_candidate($row, $map)) {
            continue;
        }
        $parentId = (int) ($row['parent_id'] ?? 0);
        if ($parentId <= 0) {
            continue;
        }

        $hasChildren = true;
        $children[$parentId][] = [
            'id' => $id,
            'label' => expenses_account_row_label($row, null),
            'name' => (string) ($row['name'] ?? ''),
            'kind' => expenses_payment_account_kind($row),
            'type' => strtolower((string) ($row['type'] ?? '')),
        ];
    }

    $assetMainId = expenses_find_asset_main_account_id($map);
    if ($assetMainId > 0) {
        $existingChildIds = [];
        foreach ($children[$assetMainId] ?? [] as $childRow) {
            $existingChildIds[(int) ($childRow['id'] ?? 0)] = true;
        }
        foreach ($map as $id => $row) {
            if ((int) ($row['parent_id'] ?? 0) > 0) {
                continue;
            }
            if (isset($existingChildIds[(int) $id])) {
                continue;
            }
            if (!expenses_row_is_payment_source_candidate($row, $map)) {
                continue;
            }
            $hasChildren = true;
            $children[$assetMainId][] = [
                'id' => (int) $id,
                'label' => expenses_account_row_label($row, null),
                'name' => (string) ($row['name'] ?? ''),
                'kind' => expenses_payment_account_kind($row),
                'type' => strtolower((string) ($row['type'] ?? '')),
            ];
        }
    }

    $sortFn = static function (array $a, array $b): int {
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    };
    usort($mains, $sortFn);
    foreach ($children as $parentId => $rows) {
        usort($children[$parentId], $sortFn);
    }

    $cache = [
        'mains' => $mains,
        'children' => $children,
        'hierarchical' => $hasChildren && $mains !== [],
    ];

    return $cache;
}

function expenses_payment_accounts_are_hierarchical(PDO $pdo): bool
{
    return expenses_build_payment_account_tree($pdo)['hierarchical'];
}

/**
 * @return list<array{id:int,label:string,type:string,balance:float,name:string,kind:string}>
 */
function expenses_fetch_payment_accounts(PDO $pdo): array
{
    $tree = expenses_build_payment_account_tree($pdo);
    if ($tree['hierarchical']) {
        $options = [];
        foreach ($tree['children'] as $rows) {
            foreach ($rows as $row) {
                $options[] = $row;
            }
        }
        usort($options, static function ($a, $b) {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $options;
    }

    $map = expenses_fetch_financial_account_map($pdo);
    $options = [];

    foreach ($map as $id => $row) {
        if (!expenses_row_is_payment_candidate($row, $map)) {
            continue;
        }

        $parentId = (int) ($row['parent_id'] ?? 0);
        $parentRow = $parentId > 0 ? ($map[$parentId] ?? null) : null;

        $options[] = [
            'id' => $id,
            'label' => expenses_account_row_label($row, $parentRow),
            'type' => strtolower((string) ($row['type'] ?? 'asset')),
            'parent_id' => $parentId,
            'balance' => isset($row['live_balance']) ? (float) $row['live_balance'] : (float) ($row['current_balance'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'kind' => expenses_payment_account_kind($row),
        ];
    }

    if ($options === []) {
        foreach (expenses_fetch_payment_accounts_direct($pdo) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || expenses_is_petty_cash_account_row($row)) {
                continue;
            }
            $options[] = [
                'id' => $id,
                'label' => expenses_account_row_label($row, null),
                'type' => strtolower((string) ($row['type'] ?? 'asset')),
                'parent_id' => (int) ($row['parent_id'] ?? 0),
                'balance' => isset($row['live_balance']) ? (float) $row['live_balance'] : (float) ($row['current_balance'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'kind' => expenses_payment_account_kind($row),
            ];
        }
    }

    usort($options, static function ($a, $b) {
        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    return $options;
}

function expenses_resolve_financial_account(PDO $pdo, int $accountId): ?array
{
    if ($accountId <= 0) {
        return null;
    }
    $map = expenses_fetch_financial_account_map($pdo);

    return $map[$accountId] ?? null;
}

function expenses_expense_already_posted(PDO $pdo, int $expenseId): bool
{
    if ($expenseId <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM account_transactions WHERE reference_type = 'expense' AND reference_id = ?"
        );
        $stmt->execute([$expenseId]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Desk list status: posted (recorded) or draft (unfinished).
 *
 * @param array<string, mixed> $row
 */
function expenses_resolve_list_display_status(PDO $pdo, array $row): string
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    $id = (int) ($row['id'] ?? 0);

    if ($status === 'draft' || $status === 'pending') {
        return 'draft';
    }

    if ((int) ($row['is_posted'] ?? 0) === 1 || $status === 'posted') {
        return 'posted';
    }

    if ($id > 0 && expenses_expense_already_posted($pdo, $id)) {
        try {
            $pdo->prepare("UPDATE erp_expenses SET is_posted = 1, status = 'posted' WHERE id = ?")
                ->execute([$id]);
        } catch (Throwable $e) {
            error_log('expenses_resolve_list_display_status: ' . $e->getMessage());
        }

        return 'posted';
    }

    return 'draft';
}

/**
 * Unposted user expenses that can be opened in the edit form.
 *
 * @param array<string, mixed> $row
 */
function expenses_is_editable_expense_row(array $row): bool
{
    if ((int) ($row['is_posted'] ?? 0) === 1) {
        return false;
    }

    return strtolower(trim((string) ($row['status'] ?? ''))) === 'draft';
}

function expenses_editable_expense_sql_constraint(): string
{
    return "is_posted = 0 AND LOWER(TRIM(status)) = 'draft'";
}

/**
 * SQL fragment: manual expense receipts only (exclude payment-voucher sync rows).
 */
function expenses_receipt_only_sql(string $tableAlias = ''): string
{
    $p = $tableAlias !== '' ? rtrim($tableAlias, '.') . '.' : '';

    return "({$p}pv_id IS NULL OR {$p}pv_id = 0)
        AND COALESCE(NULLIF({$p}source_type, ''), 'receipt') <> 'voucher'";
}

/**
 * SQL fragment: exclude petty-cash funded / PC-* rows (owned by the petty-cash module).
 */
function expenses_exclude_petty_cash_sql(string $tableAlias = ''): string
{
    $p = $tableAlias !== '' ? rtrim($tableAlias, '.') . '.' : '';
    $parts = [
        "{$p}expense_number NOT LIKE 'PC-%'",
        "{$p}expense_number NOT LIKE 'PCV-%'",
    ];

    global $pdo;
    if ($pdo instanceof PDO) {
        $ids = expenses_petty_cash_account_ids($pdo);
        if ($ids !== []) {
            $idList = implode(',', array_map('intval', $ids));
            $parts[] = "({$p}source_account_id IS NULL OR {$p}source_account_id = 0 OR {$p}source_account_id NOT IN ({$idList}))";
        } else {
            $parts[] = "NOT EXISTS (
                SELECT 1 FROM financial_accounts fa
                WHERE fa.id = {$p}source_account_id
                  AND LOWER(fa.name) LIKE '%petty%'
            )";
        }
    }

    return '(' . implode(' AND ', $parts) . ')';
}

/**
 * Default erp_expenses scope for the expenses module (non-deleted receipts only).
 */
function expenses_scope_sql(string $tableAlias = ''): string
{
    $p = $tableAlias !== '' ? rtrim($tableAlias, '.') . '.' : '';

    return "{$p}status != 'deleted' AND " . expenses_receipt_only_sql($tableAlias)
        . ' AND ' . expenses_exclude_petty_cash_sql($tableAlias);
}

function expenses_mark_expense_posted(PDO $pdo, int $expenseId): void
{
    if ($expenseId <= 0) {
        return;
    }
    $pdo->prepare("UPDATE erp_expenses SET is_posted = 1, status = 'posted' WHERE id = ?")
        ->execute([$expenseId]);
}

/**
 * Live / current balance for a Chart of Accounts row.
 */
function expenses_account_live_balance(PDO $pdo, int $accountId): float
{
    if ($accountId <= 0) {
        return 0.0;
    }

    $row = expenses_resolve_financial_account($pdo, $accountId);
    $balance = (float) ($row['live_balance'] ?? $row['current_balance'] ?? 0);

    if (function_exists('balancesFetchAccountsWithLiveBalance')) {
        foreach (balancesFetchAccountsWithLiveBalance($pdo, true) as $liveRow) {
            if ((int) ($liveRow['id'] ?? 0) === $accountId) {
                return (float) ($liveRow['live_balance'] ?? $balance);
            }
        }
    }

    return $balance;
}

/**
 * Preview balance impact of posting a draft expense (payment account outflow).
 *
 * @return array{ok:bool,message?:string,preview?:array<string,mixed>}
 */
function expenses_post_draft_preview(PDO $pdo, int $expenseId): array
{
    expenses_balances_bootstrap();

    $draft = expenses_fetch_editable_draft($pdo, $expenseId);
    if ($draft === null) {
        return ['ok' => false, 'message' => 'Draft expense not found or already posted.'];
    }

    $amount = (float) ($draft['amount'] ?? 0);
    $sourceId = (int) ($draft['source_account_id'] ?? 0);
    $accountId = (int) ($draft['account_id'] ?? 0);
    if ($amount <= 0 || $sourceId <= 0 || $accountId <= 0) {
        return ['ok' => false, 'message' => 'Draft is missing amount or accounts required to post.'];
    }

    $sourceRow = expenses_resolve_financial_account($pdo, $sourceId);
    $expenseRow = expenses_resolve_financial_account($pdo, $accountId);
    if (!$sourceRow) {
        return ['ok' => false, 'message' => 'Payment account was not found.'];
    }
    if (!$expenseRow) {
        return ['ok' => false, 'message' => 'Expense account was not found.'];
    }

    $sourceBefore = expenses_account_live_balance($pdo, $sourceId);
    $expenseBefore = expenses_account_live_balance($pdo, $accountId);
    // Payment outflow debits the bank/cash asset → balance decreases.
    $sourceAfter = round($sourceBefore - $amount, 2);
    // Expense account debit increases reported expense balance.
    $expenseAfter = round($expenseBefore + $amount, 2);

    $currency = (string) ($draft['currency_code'] ?? 'TZS');
    if (function_exists('expenses_currency_display_code')) {
        require_once __DIR__ . '/currency_helpers.php';
        $currency = expenses_currency_display_code($currency);
    }

    return [
        'ok' => true,
        'preview' => [
            'id' => $expenseId,
            'expense_number' => (string) ($draft['expense_number'] ?? ''),
            'amount' => $amount,
            'tax_amount' => (float) ($draft['tax_amount'] ?? 0),
            'currency_code' => $currency,
            'date' => (string) ($draft['date'] ?? ''),
            'description' => (string) ($draft['description'] ?? ''),
            'source_account' => [
                'id' => $sourceId,
                'name' => expenses_account_row_label($sourceRow, null),
                'balance_before' => $sourceBefore,
                'balance_after' => $sourceAfter,
            ],
            'expense_account' => [
                'id' => $accountId,
                'name' => expenses_account_row_label($expenseRow, null),
                'balance_before' => $expenseBefore,
                'balance_after' => $expenseAfter,
            ],
        ],
    ];
}

/**
 * Post expense to balances: debit payment account (outflow) + debit expense sub-account.
 *
 * @return array{success:bool,message:string,source_balance?:float,expense_balance?:float}
 */
function expenses_post_to_balances(
    PDO $pdo,
    int $expenseId,
    int $sourceAccountId,
    int $expenseAccountId,
    float $amount,
    string $description,
    ?string $transactionDate = null,
    ?int $companyId = null
): array {
    expenses_balances_bootstrap();

    if ($expenseId <= 0 || $sourceAccountId <= 0 || $expenseAccountId <= 0) {
        return ['success' => false, 'message' => 'Expense and both chart-of-accounts selections are required.'];
    }
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Amount must be greater than zero.'];
    }
    if (!function_exists('balancesRecordTransaction')) {
        return ['success' => false, 'message' => 'Balances module is not available.'];
    }

    if (expenses_expense_already_posted($pdo, $expenseId)) {
        return ['success' => true, 'message' => 'Expense was already posted to balances.'];
    }

    $sourceRow = expenses_resolve_financial_account($pdo, $sourceAccountId);
    $expenseRow = expenses_resolve_financial_account($pdo, $expenseAccountId);
    if (!$sourceRow) {
        return ['success' => false, 'message' => 'Payment account not found in balances.'];
    }
    if (!$expenseRow) {
        return ['success' => false, 'message' => 'Expense account not found in balances.'];
    }
    if (!expenses_is_payment_account_type((string) ($sourceRow['type'] ?? ''))) {
        return ['success' => false, 'message' => 'Selected payment account is not a cash/bank/asset account.'];
    }
    if (!expenses_is_expense_account_type((string) ($expenseRow['type'] ?? ''))) {
        return ['success' => false, 'message' => 'Selected expense account is not an expense category in balances.'];
    }

    $companyId = $companyId !== null ? (int) $companyId : (int) (currentCompanyId() ?? 0);
    $txDate = $transactionDate ?: date('Y-m-d H:i:s');
    $desc = trim($description) !== '' ? trim($description) : 'Expense #' . $expenseId;
    $refLabel = 'Expense #' . $expenseId;

    $paymentOk = balancesRecordTransaction(
        $pdo,
        $sourceAccountId,
        'debit',
        $amount,
        'Payment: ' . $desc,
        'expense',
        $expenseId,
        $txDate,
        $companyId > 0 ? $companyId : null
    );
    if (!$paymentOk) {
        return ['success' => false, 'message' => 'Could not deduct from the payment account.'];
    }

    $expenseOk = balancesRecordTransaction(
        $pdo,
        $expenseAccountId,
        'debit',
        $amount,
        $refLabel . ' � ' . $desc,
        'expense',
        $expenseId,
        $txDate,
        $companyId > 0 ? $companyId : null
    );
    if (!$expenseOk) {
        return ['success' => false, 'message' => 'Payment was recorded but expense account could not be updated.'];
    }

    if (function_exists('balancesRecalculateAccount')) {
        balancesRecalculateAccount($pdo, $sourceAccountId, $companyId > 0 ? $companyId : null);
        balancesRecalculateAccount($pdo, $expenseAccountId, $companyId > 0 ? $companyId : null);
    }

    $sourceBalance = (float) ($sourceRow['current_balance'] ?? 0);
    $expenseBalance = (float) ($expenseRow['current_balance'] ?? 0);
    if (function_exists('balancesFetchAccountsWithLiveBalance')) {
        foreach (balancesFetchAccountsWithLiveBalance($pdo, true) as $liveRow) {
            $lid = (int) ($liveRow['id'] ?? 0);
            if ($lid === $sourceAccountId) {
                $sourceBalance = (float) ($liveRow['live_balance'] ?? $sourceBalance);
            }
            if ($lid === $expenseAccountId) {
                $expenseBalance = (float) ($liveRow['live_balance'] ?? $expenseBalance);
            }
        }
    }

    return [
        'success' => true,
        'message' => 'Expense posted to balances successfully.',
        'source_balance' => $sourceBalance,
        'expense_balance' => $expenseBalance,
    ];
}

/**
 * Post an erp_expenses row to balances using its stored account IDs.
 *
 * @return array{success:bool,message:string}
 */
function expenses_post_erp_expense_row(PDO $pdo, int $expenseId): array
{
    $stmt = $pdo->prepare('SELECT * FROM erp_expenses WHERE id = ? LIMIT 1');
    $stmt->execute([$expenseId]);
    $exp = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$exp) {
        return ['success' => false, 'message' => 'Expense not found.'];
    }
    if ((int) ($exp['is_posted'] ?? 0) === 1 && expenses_expense_already_posted($pdo, $expenseId)) {
        return ['success' => true, 'message' => 'Expense already posted.'];
    }
    if (expenses_expense_already_posted($pdo, $expenseId)) {
        expenses_mark_expense_posted($pdo, $expenseId);

        return ['success' => true, 'message' => 'Expense already posted.'];
    }

    $sourceId = (int) ($exp['source_account_id'] ?? 0);
    $expenseAccountId = (int) ($exp['account_id'] ?? 0);
    $amount = (float) ($exp['amount'] ?? 0);
    $desc = trim((string) ($exp['description'] ?? $exp['expense_number'] ?? 'Expense'));
    $txDate = !empty($exp['date']) ? ($exp['date'] . ' 12:00:00') : null;
    $companyId = (int) ($exp['company_id'] ?? 0);

    $result = expenses_post_to_balances(
        $pdo,
        $expenseId,
        $sourceId,
        $expenseAccountId,
        $amount,
        $desc,
        $txDate,
        $companyId > 0 ? $companyId : null
    );

    if (!empty($result['success'])) {
        expenses_mark_expense_posted($pdo, $expenseId);
    }

    return $result;
}

/**
 * Category / expense account name for display (balances first, erp_accounts fallback).
 */
/**
 * Approve and post legacy pending expenses (no admin approval workflow).
 *
 * @return array{posted:int,failed:list<array{id:int,message:string}>}
 */
function expenses_backfill_pending_records(PDO $pdo): array
{
    expenses_balances_bootstrap();

    $posted = 0;
    $failed = [];

    try {
        $stmt = $pdo->query("
            SELECT id, created_by
            FROM erp_expenses
            WHERE status = 'pending'
              AND is_posted = 0
              AND " . expenses_receipt_only_sql() . "
            ORDER BY id ASC
        ");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return ['posted' => 0, 'failed' => [['id' => 0, 'message' => $e->getMessage()]]];
    }

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        try {
            $pdo->beginTransaction();
            $userId = (int) ($row['created_by'] ?? 0);
            $upd = $pdo->prepare("
                UPDATE erp_expenses
                SET status = 'approved', approved_by = ?, approved_at = NOW()
                WHERE id = ? AND status = 'pending' AND is_posted = 0
            ");
            $upd->execute([$userId > 0 ? $userId : null, $id]);

            $postResult = expenses_post_erp_expense_row($pdo, $id);
            if (empty($postResult['success'])) {
                $failed[] = [
                    'id' => $id,
                    'message' => (string) ($postResult['message'] ?? 'Could not post expense to balances.'),
                ];
            } else {
                $posted++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $failed[] = ['id' => $id, 'message' => $e->getMessage()];
        }
    }

    return ['posted' => $posted, 'failed' => $failed];
}

function expenses_resolve_category_name(PDO $pdo, int $accountId): string
{
    if ($accountId <= 0) {
        return '';
    }
    $fa = expenses_resolve_financial_account($pdo, $accountId);
    if ($fa) {
        return (string) ($fa['name'] ?? '');
    }
    try {
        $stmt = $pdo->prepare('SELECT name FROM erp_accounts WHERE id = ? LIMIT 1');
        $stmt->execute([$accountId]);

        return (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function expenses_resolve_source_account_name(PDO $pdo, int $accountId): string
{
    return expenses_resolve_category_name($pdo, $accountId);
}

function expenses_payment_method_label(?string $method): string
{
    $m = strtolower(trim((string) $method));
    if ($m === 'cash') {
        return 'Cash';
    }
    if (in_array($m, ['bank_transfer', 'mobile_money', 'mobile', 'bank'], true)) {
        return 'Bank Transfer';
    }

    return $m !== '' ? ucwords(str_replace('_', ' ', $m)) : '-';
}

/**
 * Add display fields for expense list rows (matches create form fields).
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function expenses_enrich_list_row(PDO $pdo, array $row): array
{
    $accountId = (int) ($row['account_id'] ?? 0);
    $sourceId = (int) ($row['source_account_id'] ?? 0);
    $accountRow = expenses_resolve_financial_account($pdo, $accountId);
    $parentId = (int) ($accountRow['parent_id'] ?? 0);

    $subAccountName = expenses_resolve_category_name($pdo, $accountId);
    $mainAccountName = $parentId > 0
        ? expenses_resolve_category_name($pdo, $parentId)
        : $subAccountName;

    $row['sub_account_name'] = $subAccountName !== '' ? $subAccountName : '-';
    $row['main_account_name'] = $mainAccountName !== '' ? $mainAccountName : '-';
    if ($parentId <= 0 && $subAccountName !== '') {
        $row['sub_account_name'] = '-';
    }
    $row['category_name'] = $row['sub_account_name'] !== '-' ? $row['sub_account_name'] : $row['main_account_name'];
    $row['source_account_name'] = expenses_resolve_source_account_name($pdo, $sourceId) ?: '-';
    $row['payment_method_label'] = expenses_payment_method_label($row['payment_method'] ?? '');

    if (function_exists('expenses_currency_display_code')) {
        $row['currency_display'] = expenses_currency_display_code((string) ($row['currency_code'] ?? 'TZS'));
    } else {
        $row['currency_display'] = (string) ($row['currency_code'] ?? 'TZS');
    }

    $row['display_status'] = expenses_resolve_list_display_status($pdo, $row);
    $row['can_edit'] = expenses_is_editable_expense_row($row);
    $row['can_delete'] = $row['can_edit'];
    $row['display_name'] = expenses_resolve_display_name($row);

    return $row;
}

/**
 * Human-readable expense label for lists (payee on legacy rows, else description).
 *
 * @param array<string, mixed> $row
 */
function expenses_resolve_display_name(array $row): string
{
    $payee = trim((string) ($row['payee'] ?? ''));
    if ($payee !== '') {
        return $payee;
    }

    return trim((string) ($row['description'] ?? ''));
}

/**
 * Shared account/currency payload for create and edit forms.
 *
 * @return array<string, mixed>
 */
function expenses_build_desk_form_init(PDO $pdo): array
{
    require_once __DIR__ . '/currency_helpers.php';
    expenses_balances_bootstrap();

    $expenseTree = expenses_build_expense_account_tree($pdo);
    $paymentTree = expenses_build_payment_account_tree($pdo);

    $expenseChildren = [];
    foreach ($expenseTree['children'] as $parentId => $rows) {
        $expenseChildren[(string) $parentId] = array_values($rows);
    }
    $paymentChildren = [];
    foreach ($paymentTree['children'] as $parentId => $rows) {
        $paymentChildren[(string) $parentId] = array_values($rows);
    }

    $paymentFlat = expenses_fetch_payment_accounts($pdo);
    $expenseFlat = expenses_fetch_expense_sub_accounts($pdo);

    $simpleWallets = [];
    foreach ($paymentFlat as $row) {
        $kind = strtolower((string) ($row['kind'] ?? 'bank'));
        if ($kind === 'petty') {
            continue;
        }
        $simpleWallets[] = [
            'id' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['label'] ?? $row['name'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'kind' => $kind === 'cash' ? 'cash' : ($kind === 'mobile' ? 'mobile' : 'bank'),
            'parent_id' => (int) ($row['parent_id'] ?? 0),
        ];
    }

    $simpleCategories = [];
    foreach ($expenseFlat as $row) {
        $simpleCategories[] = [
            'id' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['label'] ?? $row['name'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'parent_id' => (int) ($row['parent_id'] ?? 0),
        ];
    }

    $currencyCatalog = expenses_currency_catalog();
    $currencies = [];
    foreach ($currencyCatalog as $currencyOpt) {
        $iso = (string) ($currencyOpt['iso'] ?? '');
        if ($iso === '' || isset($currencies[$iso])) {
            continue;
        }
        $currencies[$iso] = [
            'code' => (string) ($currencyOpt['code'] ?? expenses_currency_display_code($iso)),
            'iso' => $iso,
            'name' => expenses_currency_name($iso),
            'flag' => expenses_currency_flag_country($iso),
        ];
    }
    if ($currencies === []) {
        foreach (['TZS', 'USD', 'EUR', 'GBP', 'KES'] as $code) {
            $currencies[$code] = [
                'code' => expenses_currency_display_code($code),
                'iso' => $code,
                'name' => expenses_currency_name($code),
                'flag' => expenses_currency_flag_country($code),
            ];
        }
    }

    return [
        'default_currency' => 'TZS',
        'currencies' => array_values($currencies),
        'expense' => [
            'hierarchical' => (bool) $expenseTree['hierarchical'],
            'mains' => $expenseTree['mains'],
            'childrenByParent' => $expenseChildren,
            'flat' => $expenseFlat,
        ],
        'payment' => [
            'hierarchical' => (bool) $paymentTree['hierarchical'],
            'mains' => $paymentTree['mains'],
            'childrenByParent' => $paymentChildren,
            'flat' => $paymentFlat,
        ],
        'simple_wallets' => $simpleWallets,
        'simple_categories' => $simpleCategories,
        'require_receipt' => false,
        'balances_url' => '../balances/accounts.php?module=balances',
        'list_url' => 'index.php?module=expenses',
    ];
}

/**
 * @return array<string, mixed>|null
 */
function expenses_fetch_editable_draft(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT * FROM erp_expenses
        WHERE id = ? AND ' . expenses_editable_expense_sql_constraint() . '
        LIMIT 1
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @param array<string, mixed> $draft
 * @return array<string, mixed>
 */
function expenses_draft_to_form_fields(PDO $pdo, array $draft): array
{
    require_once __DIR__ . '/currency_helpers.php';

    $accountId = (int) ($draft['account_id'] ?? 0);
    $sourceId = (int) ($draft['source_account_id'] ?? 0);
    $accountRow = expenses_resolve_financial_account($pdo, $accountId);
    $sourceRow = expenses_resolve_financial_account($pdo, $sourceId);

    $accountParentId = (int) ($accountRow['parent_id'] ?? 0);
    $sourceParentId = (int) ($sourceRow['parent_id'] ?? 0);

    $mainAccountId = $accountParentId > 0 ? $accountParentId : 0;
    $mainPaymentAccountId = $sourceParentId > 0 ? $sourceParentId : 0;

    $paymentMethod = strtolower(trim((string) ($draft['payment_method'] ?? 'cash')));
    if ($paymentMethod === 'mobile_money' || $paymentMethod === 'bank') {
        $paymentMethod = 'bank_transfer';
    }

    $attachment = trim((string) ($draft['attachment'] ?? ''));

    return [
        'id' => (int) ($draft['id'] ?? 0),
        'expense_number' => (string) ($draft['expense_number'] ?? ''),
        'date' => (string) ($draft['date'] ?? ''),
        'payment_method' => $paymentMethod,
        'main_account_id' => $mainAccountId > 0 ? (string) $mainAccountId : '',
        'account_id' => $accountId > 0 ? (string) $accountId : '',
        'main_payment_account_id' => $mainPaymentAccountId > 0 ? (string) $mainPaymentAccountId : '',
        'source_account_id' => $sourceId > 0 ? (string) $sourceId : '',
        'currency' => expenses_currency_iso((string) ($draft['currency_code'] ?? 'TZS')),
        'amount' => (string) ($draft['amount'] ?? ''),
        'tax_amount' => (string) ($draft['tax_amount'] ?? '0'),
        'description' => (string) ($draft['description'] ?? ''),
        'attachment' => $attachment,
        'attachment_name' => $attachment !== '' ? basename($attachment) : '',
    ];
}

function expenses_soft_delete_draft(PDO $pdo, int $id): bool
{
    if ($id <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('
        UPDATE erp_expenses
        SET status = \'deleted\'
        WHERE id = ? AND ' . expenses_editable_expense_sql_constraint() . '
    ');
    $stmt->execute([$id]);

    return $stmt->rowCount() > 0;
}

/**
 * Parse list/export query filters from request parameters.
 *
 * @param array<string, mixed> $get
 * @return array{search:string,status:string,category:string,date_from:string,date_to:string,payment_method:string,amount_min:string,amount_max:string,source_type:string}
 */
function expenses_parse_list_filters(array $get): array
{
    return [
        'search' => trim((string) ($get['search'] ?? '')),
        'status' => strtolower(trim((string) ($get['status'] ?? ''))),
        'category' => trim((string) ($get['category'] ?? '')),
        'date_from' => trim((string) ($get['date_from'] ?? '')),
        'date_to' => trim((string) ($get['date_to'] ?? '')),
        'payment_method' => strtolower(trim((string) ($get['payment_method'] ?? ''))),
        'amount_min' => trim((string) ($get['amount_min'] ?? '')),
        'amount_max' => trim((string) ($get['amount_max'] ?? '')),
        'source_type' => trim((string) ($get['source_type'] ?? '')),
    ];
}

/**
 * Build WHERE clause and bound params for expense list queries.
 *
 * @param array<string, string> $filters
 * @param array<int, mixed> $params
 */
function expenses_build_list_where(array $filters, array &$params): string
{
    $where = 'WHERE ' . expenses_scope_sql('e');

    if ($filters['search'] !== '') {
        $where .= ' AND (e.expense_number LIKE ? OR e.payee LIKE ? OR e.description LIKE ?)';
        $needle = '%' . $filters['search'] . '%';
        $params[] = $needle;
        $params[] = $needle;
        $params[] = $needle;
    }

    if ($filters['status'] === 'posted') {
        $where .= ' AND e.is_posted = 1';
    } elseif ($filters['status'] === 'unposted' || $filters['status'] === 'approved') {
        $where .= " AND e.is_posted = 0 AND e.status NOT IN ('draft', 'rejected', 'deleted')";
    } elseif ($filters['status'] !== '') {
        $where .= ' AND e.status = ?';
        $params[] = $filters['status'];
    }

    if ($filters['category'] !== '') {
        $where .= ' AND e.account_id = ?';
        $params[] = $filters['category'];
    }

    if ($filters['date_from'] !== '') {
        $where .= ' AND e.date >= ?';
        $params[] = $filters['date_from'];
    }

    if ($filters['date_to'] !== '') {
        $where .= ' AND e.date <= ?';
        $params[] = $filters['date_to'];
    }

    if ($filters['payment_method'] === 'bank') {
        $where .= " AND e.payment_method IN ('bank_transfer', 'mobile_money', 'mobile', 'bank')";
    } elseif ($filters['payment_method'] !== '') {
        $where .= ' AND e.payment_method = ?';
        $params[] = $filters['payment_method'];
    }

    if ($filters['amount_min'] !== '' && is_numeric($filters['amount_min'])) {
        $where .= ' AND e.amount >= ?';
        $params[] = (float) $filters['amount_min'];
    }

    if ($filters['amount_max'] !== '' && is_numeric($filters['amount_max'])) {
        $where .= ' AND e.amount <= ?';
        $params[] = (float) $filters['amount_max'];
    }

    if ($filters['source_type'] !== '') {
        $where .= ' AND e.source_type = ?';
        $params[] = $filters['source_type'];
    }

    return $where;
}

/**
 * Same row universe as the expenses table (receipts + General Payment vouchers).
 *
 * @param array<string, string> $filters
 * @return list<array<string, mixed>>
 */
function expenses_fetch_listed_rows(PDO $pdo, array $filters = []): array
{
    $filters = array_merge(expenses_parse_list_filters([]), $filters);

    $expenseRows = [];
    $sourceFilter = strtolower(trim((string) ($filters['source_type'] ?? '')));
    if ($sourceFilter !== 'voucher') {
        $params = [];
        $where = expenses_build_list_where($filters, $params);
        try {
            $stmt = $pdo->prepare(
                "SELECT e.* FROM erp_expenses e {$where}
                 ORDER BY e.date DESC, e.id DESC LIMIT 5000"
            );
            $stmt->execute($params);
            $expenseRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($expenseRows as &$row) {
                $row = expenses_enrich_list_row($pdo, $row);
            }
            unset($row);
        } catch (Throwable $e) {
            $expenseRows = [];
        }
    }

    $voucherRows = expenses_fetch_general_vouchers_as_list_rows($pdo, $filters);

    return expenses_merge_list_rows($expenseRows, $voucherRows);
}

/**
 * Whether a listed row should count toward spend / trend (posted or paid).
 *
 * @param array<string, mixed> $row
 */
function expenses_listed_row_is_spend(array $row): bool
{
    $display = strtolower(trim((string) ($row['display_status'] ?? '')));
    $status = strtolower(trim((string) ($row['status'] ?? '')));

    if ((int) ($row['is_posted'] ?? 0) === 1 || $display === 'posted' || $status === 'posted') {
        return true;
    }
    if ((int) ($row['is_paid'] ?? 0) === 1 || $display === 'paid' || $status === 'paid') {
        return true;
    }

    return false;
}

/**
 * Status breakdown for the desk chart — same row universe as the expenses table.
 *
 * @param array<string, string> $filters
 * @return array{total:int,breakdown:list<array{key:string,label:string,count:int,color:string}>}
 */
function expenses_build_listed_status_breakdown(PDO $pdo, array $filters = []): array
{
    $merged = expenses_fetch_listed_rows($pdo, $filters);

    $counts = [
        'posted' => 0,
        'paid' => 0,
        'approved' => 0,
        'pending' => 0,
        'confirming' => 0,
        'draft' => 0,
        'rejected' => 0,
    ];

    foreach ($merged as $row) {
        $display = strtolower(trim((string) ($row['display_status'] ?? '')));
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $isPosted = (int) ($row['is_posted'] ?? 0) === 1;
        $isPaid = (int) ($row['is_paid'] ?? 0) === 1;

        if ($isPosted || $display === 'posted' || $status === 'posted') {
            $counts['posted']++;
        } elseif ($isPaid || $display === 'paid' || $status === 'paid') {
            $counts['paid']++;
        } elseif ($display === 'approved' || $status === 'approved') {
            $counts['approved']++;
        } elseif ($display === 'rejected' || $status === 'rejected') {
            $counts['rejected']++;
        } elseif ($display === 'pending' || $status === 'pending') {
            $counts['pending']++;
        } elseif ($display === 'confirming' || $status === 'confirming') {
            $counts['confirming']++;
        } else {
            $counts['draft']++;
        }
    }

    $meta = [
        'posted' => ['label' => 'Posted', 'color' => '#22c55e'],
        'paid' => ['label' => 'Paid', 'color' => '#3b82f6'],
        'approved' => ['label' => 'Approved', 'color' => '#14b8a6'],
        'pending' => ['label' => 'Pending', 'color' => '#f59e0b'],
        'confirming' => ['label' => 'Confirming', 'color' => '#a855f7'],
        'draft' => ['label' => 'Draft', 'color' => '#94a3b8'],
        'rejected' => ['label' => 'Rejected', 'color' => '#ef4444'],
    ];

    $breakdown = [];
    foreach ($meta as $key => $info) {
        $count = (int) ($counts[$key] ?? 0);
        if ($count <= 0) {
            continue;
        }
        $breakdown[] = [
            'key' => $key,
            'label' => $info['label'],
            'count' => $count,
            'color' => $info['color'],
        ];
    }

    return [
        'total' => count($merged),
        'breakdown' => $breakdown,
    ];
}

/**
 * Trend + monthly KPIs from the same listed universe as the expenses table.
 *
 * @param array<string, string> $filters
 * @return array{
 *   trend:list<array{date:string,label:string,amount:float}>,
 *   trend_from:string,
 *   trend_to:string,
 *   posted_month_count:int,
 *   spend_month:float
 * }
 */
function expenses_build_listed_trend_stats(PDO $pdo, array $filters = []): array
{
    $currentMonth = date('Y-m');
    $monthStart = $currentMonth . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));

    $merged = expenses_fetch_listed_rows($pdo, $filters);
    $spendByDay = [];
    $spendDates = [];
    $postedMonthCount = 0;
    $spendMonth = 0.0;

    foreach ($merged as $row) {
        if (!expenses_listed_row_is_spend($row)) {
            continue;
        }
        $day = substr((string) ($row['date'] ?? ''), 0, 10);
        if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            continue;
        }
        $amount = (float) ($row['amount'] ?? 0);
        if ($amount <= 0) {
            continue;
        }
        $spendByDay[$day] = ($spendByDay[$day] ?? 0) + $amount;
        $spendDates[] = $day;
        if (strpos($day, $currentMonth) === 0) {
            $postedMonthCount++;
            $spendMonth += $amount;
        }
    }

    $trendFrom = $monthStart;
    $trendTo = $monthEnd;

    // Current month empty but historical posted/paid exist → show the active spend window.
    if ($spendMonth <= 0 && $spendDates !== []) {
        sort($spendDates);
        $latest = $spendDates[count($spendDates) - 1];
        $earliest = $spendDates[0];
        $windowStart = date('Y-m-d', strtotime('-5 months', strtotime(substr($latest, 0, 7) . '-01')));
        $trendFrom = $earliest > $windowStart ? $earliest : $windowStart;
        $trendTo = $latest;
        // Align to month boundaries for a cleaner "monthly" chart.
        $trendFrom = date('Y-m-01', strtotime($trendFrom));
        $trendTo = date('Y-m-t', strtotime($trendTo));
    }

    $fromTs = strtotime($trendFrom);
    $toTs = strtotime($trendTo);
    if ($fromTs === false || $toTs === false || $fromTs > $toTs) {
        $trendFrom = $monthStart;
        $trendTo = $monthEnd;
        $fromTs = strtotime($trendFrom);
        $toTs = strtotime($trendTo);
    }

    $daySpan = (int) round(($toTs - $fromTs) / 86400) + 1;
    $useMonthly = $daySpan > 45;
    $buckets = [];

    if ($useMonthly) {
        $cursor = strtotime(date('Y-m-01', $fromTs));
        $endMonth = strtotime(date('Y-m-01', $toTs));
        while ($cursor !== false && $cursor <= $endMonth) {
            $key = date('Y-m', $cursor);
            $buckets[$key] = [
                'date' => $key . '-01',
                'label' => date('M Y', $cursor),
                'amount' => 0.0,
            ];
            $cursor = strtotime('+1 month', $cursor);
        }
        foreach ($spendByDay as $day => $amount) {
            if ($day < $trendFrom || $day > $trendTo) {
                continue;
            }
            $key = substr($day, 0, 7);
            if (isset($buckets[$key])) {
                $buckets[$key]['amount'] += (float) $amount;
            }
        }
    } else {
        $cursor = $fromTs;
        while ($cursor !== false && $cursor <= $toTs) {
            $day = date('Y-m-d', $cursor);
            $buckets[$day] = [
                'date' => $day,
                'label' => date('M j', $cursor),
                'amount' => (float) ($spendByDay[$day] ?? 0),
            ];
            $cursor = strtotime('+1 day', $cursor);
        }
    }

    return [
        'trend' => array_values($buckets),
        'trend_from' => $trendFrom,
        'trend_to' => $trendTo,
        'posted_month_count' => $postedMonthCount,
        'spend_month' => $spendMonth,
    ];
}

/**
 * Payment channel bucket for desk summary cards.
 *
 * @param array<string, mixed> $row
 * @return 'cash'|'bank'|'payables'|'other'
 */
function expenses_listed_row_payment_bucket(array $row): string
{
    $method = strtolower(trim((string) ($row['payment_method'] ?? '')));
    $label = strtolower(trim((string) ($row['payment_method_label'] ?? '')));
    $haystack = $method . ' ' . $label;

    if ($haystack !== ' ' && (str_contains($haystack, 'credit') || str_contains($haystack, 'payable'))) {
        return 'payables';
    }
    if ($haystack !== ' ' && str_contains($haystack, 'cash')) {
        return 'cash';
    }
    if (
        $haystack !== ' '
        && (
            str_contains($haystack, 'bank')
            || str_contains($haystack, 'mobile')
            || str_contains($haystack, 'cheque')
            || str_contains($haystack, 'check')
            || str_contains($haystack, 'transfer')
        )
    ) {
        return 'bank';
    }

    return 'other';
}

/**
 * Whether a listed row belongs in the financial summary cards.
 *
 * @param array<string, mixed> $row
 */
function expenses_listed_row_in_summary(array $row): bool
{
    $display = strtolower(trim((string) ($row['display_status'] ?? '')));
    $status = strtolower(trim((string) ($row['status'] ?? '')));

    if (in_array($display, ['draft', 'rejected', 'deleted'], true) || in_array($status, ['draft', 'rejected', 'deleted'], true)) {
        return false;
    }

    // Credit / payable rows count even before they are posted or paid.
    if (expenses_listed_row_payment_bucket($row) === 'payables') {
        return true;
    }

    return expenses_listed_row_is_spend($row);
}

/**
 * Desk summary totals: exclusive, input VAT, gross, bank, cash, payables.
 *
 * @param array<string, string> $filters
 * @return array{
 *   total_exclusive:float,
 *   input_vat:float,
 *   total_inclusive:float,
 *   total_bank:float,
 *   total_cash:float,
 *   total_payables:float
 * }
 */
function expenses_build_listed_summary_stats(PDO $pdo, array $filters = []): array
{
    $merged = expenses_fetch_listed_rows($pdo, $filters);

    $totalExclusive = 0.0;
    $inputVat = 0.0;
    $totalInclusive = 0.0;
    $totalBank = 0.0;
    $totalCash = 0.0;
    $totalPayables = 0.0;

    foreach ($merged as $row) {
        if (!expenses_listed_row_in_summary($row)) {
            continue;
        }

        $amount = (float) ($row['amount'] ?? 0);
        if ($amount <= 0) {
            continue;
        }

        $tax = max(0.0, (float) ($row['tax_amount'] ?? 0));
        if ($tax > $amount) {
            $tax = $amount;
        }
        $exclusive = max(0.0, $amount - $tax);

        $totalExclusive += $exclusive;
        $inputVat += $tax;
        $totalInclusive += $amount;

        $bucket = expenses_listed_row_payment_bucket($row);
        if ($bucket === 'cash') {
            $totalCash += $amount;
        } elseif ($bucket === 'payables') {
            $totalPayables += $amount;
        } elseif ($bucket === 'bank') {
            $totalBank += $amount;
        } else {
            // Unknown channel → treat as bank (most general payments settle via bank).
            $totalBank += $amount;
        }
    }

    return [
        'total_exclusive' => round($totalExclusive, 2),
        'input_vat' => round($inputVat, 2),
        'total_inclusive' => round($totalInclusive, 2),
        'total_bank' => round($totalBank, 2),
        'total_cash' => round($totalCash, 2),
        'total_payables' => round($totalPayables, 2),
    ];
}

/**
 * Whether list filters should include General Payment vouchers from payment_vouchers.
 */
function expenses_list_should_include_general_vouchers(array $filters): bool
{
    $source = strtolower(trim((string) ($filters['source_type'] ?? '')));
    if ($source !== '' && $source !== 'voucher') {
        return false;
    }
    // Voucher rows have no Chart-of-Accounts account_id; skip when filtering by account.
    if (trim((string) ($filters['category'] ?? '')) !== '') {
        return false;
    }

    return true;
}

/**
 * SQL purpose predicate for General Payment vouchers (excludes stock purchase).
 */
function expenses_general_voucher_purpose_sql(string $alias = 'pv'): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $candidates = [];
    foreach (['purpose', 'payment_purpose', 'voucher_purpose'] as $col) {
        if (function_exists('columnExists') && columnExists('payment_vouchers', $col)) {
            $candidates[] = "NULLIF(TRIM({$p}{$col}), '')";
        }
    }
    if ($candidates === []) {
        return '1=1';
    }

    $resolved = 'LOWER(TRIM(COALESCE(' . implode(', ', $candidates) . ", 'general')))";

    return "({$resolved} IN ('general', 'general_payment') OR {$resolved} = '')
        AND {$resolved} NOT IN ('stock_purchase', 'stockpurchase')
        AND {$resolved} NOT LIKE '%stock%purchase%'";
}

/**
 * Map a payment voucher (+ first line item) into the expenses desk row shape.
 *
 * @param array<string, mixed> $voucher
 * @param array<string, mixed>|null $firstItem
 * @return array<string, mixed>
 */
function expenses_map_general_voucher_to_list_row(array $voucher, ?array $firstItem = null): array
{
    $pvId = (int) ($voucher['id'] ?? 0);
    $status = strtolower(trim((string) ($voucher['status'] ?? '')));
    $isPosted = (int) ($voucher['is_posted'] ?? 0) === 1;
    $isPaid = (int) ($voucher['is_paid'] ?? 0) === 1;

    $budgetType = trim((string) ($firstItem['budget_type'] ?? ''));
    $paymentType = trim((string) ($firstItem['payment_type'] ?? ''));
    $itemName = trim((string) ($firstItem['name'] ?? ''));

    if ($isPosted) {
        $displayStatus = 'posted';
    } elseif ($isPaid) {
        $displayStatus = 'paid';
    } elseif ($status === 'approved') {
        $displayStatus = 'approved';
    } elseif ($status === 'rejected') {
        $displayStatus = 'rejected';
    } elseif ($status === 'pending') {
        $displayStatus = 'pending';
    } elseif ($status === 'confirming') {
        $displayStatus = 'confirming';
    } else {
        $displayStatus = $status !== '' ? $status : 'pending';
    }

    $currency = strtoupper(trim((string) ($voucher['currency'] ?? 'TZS')));
    if ($currency === 'TSH') {
        $currency = 'TZS';
    }

    $paymentLabel = $paymentType !== '' ? $paymentType : 'Payment Voucher';
    $accountLabel = $budgetType !== '' ? $budgetType : 'General Payment';
    $description = trim((string) ($voucher['description'] ?? ''));
    if ($description === '' && $itemName !== '') {
        $description = $itemName;
    }

    $date = (string) ($voucher['date_created'] ?? '');
    if (strlen($date) > 10) {
        $date = substr($date, 0, 10);
    }

    return [
        'id' => -1 * $pvId,
        'row_key' => 'pv-' . $pvId,
        'pv_id' => $pvId,
        'source_type' => 'voucher',
        'is_voucher' => 1,
        'expense_number' => (string) ($voucher['voucher_no'] ?? ('PV-' . $pvId)),
        'date' => $date,
        'payee' => (string) ($voucher['payee_name'] ?? ''),
        'display_name' => (string) ($voucher['payee_name'] ?? ''),
        'description' => $description,
        'amount' => (float) ($voucher['total_amount'] ?? 0),
        'tax_amount' => 0,
        'currency_code' => $currency,
        'currency_display' => $currency,
        'account_id' => 0,
        'source_account_id' => (int) ($voucher['payment_account_id'] ?? 0),
        'category_name' => $accountLabel,
        'main_account_name' => $accountLabel,
        'sub_account_name' => '-',
        'payment_method' => strtolower(str_replace(' ', '_', $paymentType)),
        'payment_method_label' => $paymentLabel,
        'source_account_name' => '',
        'status' => $status !== '' ? $status : 'pending',
        'display_status' => $displayStatus,
        'is_posted' => $isPosted ? 1 : 0,
        'is_paid' => $isPaid ? 1 : 0,
        'can_edit' => 0,
        'can_delete' => 0,
        'voucher_url' => '../../employee/view-voucher.php?id=' . $pvId . '&module=voucher',
        'prepared_by' => (string) ($voucher['prepared_by'] ?? ''),
    ];
}

/**
 * Fetch General Payment vouchers shaped like expense desk rows.
 *
 * @param array<string, string> $filters
 * @return list<array<string, mixed>>
 */
function expenses_fetch_general_vouchers_as_list_rows(PDO $pdo, array $filters = []): array
{
    if (!expenses_list_should_include_general_vouchers($filters)) {
        return [];
    }
    if (!function_exists('tableExists') || !tableExists('payment_vouchers', $pdo)) {
        return [];
    }

    $params = [];
    $where = ['1=1', expenses_general_voucher_purpose_sql('pv')];

    // Hide private drafts; keep public confirming/pending/approved/rejected/paid.
    if (function_exists('columnExists') && columnExists('payment_vouchers', 'is_draft', $pdo)) {
        $where[] = 'IFNULL(pv.is_draft, 0) = 0';
    }

    $companyWhere = '';
    if (function_exists('companyScopeSql')) {
        [$companyFrag, $companyParams] = companyScopeSql('payment_vouchers', 'pv');
        if (is_string($companyFrag) && trim($companyFrag) !== '') {
            $companyWhere = $companyFrag;
            foreach ($companyParams as $cp) {
                $params[] = $cp;
            }
        }
    }

    if (($filters['search'] ?? '') !== '') {
        $needle = '%' . $filters['search'] . '%';
        $where[] = '(pv.voucher_no LIKE ? OR pv.payee_name LIKE ? OR COALESCE(pv.description, \'\') LIKE ?)';
        $params[] = $needle;
        $params[] = $needle;
        $params[] = $needle;
    }

    $status = strtolower(trim((string) ($filters['status'] ?? '')));
    if ($status === 'posted') {
        $where[] = 'IFNULL(pv.is_posted, 0) = 1';
    } elseif ($status === 'draft') {
        $where[] = "LOWER(TRIM(pv.status)) IN ('confirming', 'pending')";
    } elseif ($status === 'rejected') {
        $where[] = "LOWER(TRIM(pv.status)) = 'rejected'";
    } elseif ($status === 'unposted' || $status === 'approved') {
        $where[] = "IFNULL(pv.is_posted, 0) = 0 AND LOWER(TRIM(pv.status)) = 'approved'";
    } elseif ($status !== '') {
        $where[] = 'LOWER(TRIM(pv.status)) = ?';
        $params[] = $status;
    }

    if (($filters['date_from'] ?? '') !== '') {
        $where[] = 'DATE(pv.date_created) >= ?';
        $params[] = $filters['date_from'];
    }
    if (($filters['date_to'] ?? '') !== '') {
        $where[] = 'DATE(pv.date_created) <= ?';
        $params[] = $filters['date_to'];
    }

    if (($filters['payment_method'] ?? '') === 'cash') {
        $where[] = "EXISTS (
            SELECT 1 FROM voucher_items vi
            WHERE vi.voucher_id = pv.id
              AND LOWER(vi.payment_type) LIKE '%cash%'
        )";
    } elseif (($filters['payment_method'] ?? '') === 'bank') {
        $where[] = "EXISTS (
            SELECT 1 FROM voucher_items vi
            WHERE vi.voucher_id = pv.id
              AND (
                LOWER(vi.payment_type) LIKE '%bank%'
                OR LOWER(vi.payment_type) LIKE '%mobile%'
                OR LOWER(vi.payment_type) LIKE '%cheque%'
              )
        )";
    }

    if (($filters['amount_min'] ?? '') !== '' && is_numeric($filters['amount_min'])) {
        $where[] = 'pv.total_amount >= ?';
        $params[] = (float) $filters['amount_min'];
    }
    if (($filters['amount_max'] ?? '') !== '' && is_numeric($filters['amount_max'])) {
        $where[] = 'pv.total_amount <= ?';
        $params[] = (float) $filters['amount_max'];
    }

    $whereSql = implode(' AND ', $where) . ($companyWhere !== '' ? ' ' . $companyWhere : '');

    try {
        $sql = "
            SELECT
                pv.*,
                (
                    SELECT vi.payment_type FROM voucher_items vi
                    WHERE vi.voucher_id = pv.id
                    ORDER BY vi.id ASC LIMIT 1
                ) AS item_payment_type,
                (
                    SELECT vi.budget_type FROM voucher_items vi
                    WHERE vi.voucher_id = pv.id
                    ORDER BY vi.id ASC LIMIT 1
                ) AS item_budget_type,
                (
                    SELECT vi.name FROM voucher_items vi
                    WHERE vi.voucher_id = pv.id
                    ORDER BY vi.id ASC LIMIT 1
                ) AS item_name
            FROM payment_vouchers pv
            WHERE {$whereSql}
            ORDER BY DATE(pv.date_created) DESC, pv.id DESC
            LIMIT 5000
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('expenses_fetch_general_vouchers_as_list_rows: ' . $e->getMessage());

        return [];
    }

    $out = [];
    foreach ($rows as $voucher) {
        $firstItem = [
            'payment_type' => (string) ($voucher['item_payment_type'] ?? ''),
            'budget_type' => (string) ($voucher['item_budget_type'] ?? ''),
            'name' => (string) ($voucher['item_name'] ?? ''),
        ];
        $out[] = expenses_map_general_voucher_to_list_row($voucher, $firstItem);
    }

    return $out;
}

/**
 * Merge receipt expenses with General Payment voucher rows, newest first.
 * Prefers live voucher rows when the same pv_id already exists in erp_expenses.
 *
 * @param list<array<string, mixed>> $expenseRows
 * @param list<array<string, mixed>> $voucherRows
 * @return list<array<string, mixed>>
 */
function expenses_merge_list_rows(array $expenseRows, array $voucherRows): array
{
    $voucherPvIds = [];
    foreach ($voucherRows as $row) {
        $pvId = (int) ($row['pv_id'] ?? 0);
        if ($pvId > 0) {
            $voucherPvIds[$pvId] = true;
        }
    }

    $merged = [];
    foreach ($expenseRows as $row) {
        $pvId = (int) ($row['pv_id'] ?? 0);
        if ($pvId > 0 && isset($voucherPvIds[$pvId])) {
            continue;
        }
        if (!isset($row['row_key'])) {
            $row['row_key'] = 'exp-' . (int) ($row['id'] ?? 0);
        }
        $merged[] = $row;
    }
    foreach ($voucherRows as $row) {
        $merged[] = $row;
    }

    usort($merged, static function (array $a, array $b): int {
        $dateA = (string) ($a['date'] ?? '');
        $dateB = (string) ($b['date'] ?? '');
        if ($dateA !== $dateB) {
            return strcmp($dateB, $dateA);
        }
        $idA = (int) ($a['pv_id'] ?? abs((int) ($a['id'] ?? 0)));
        $idB = (int) ($b['pv_id'] ?? abs((int) ($b['id'] ?? 0)));

        return $idB <=> $idA;
    });

    return $merged;
}

/**
 * Map an expense row to a KPI trace line item.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function expenses_map_kpi_trace_item(PDO $pdo, array $row, float $contribution, string $note): array
{
    // Voucher-shaped list rows are already enriched; re-running expense enrich would wipe labels.
    if (empty($row['is_voucher']) && (string) ($row['source_type'] ?? '') !== 'voucher') {
        $row = expenses_enrich_list_row($pdo, $row);
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'expenseNumber' => (string) ($row['expense_number'] ?? ''),
        'payee' => (string) ($row['payee'] ?? $row['display_name'] ?? ''),
        'date' => (string) ($row['date'] ?? ''),
        'account' => (string) ($row['category_name'] ?? ''),
        'payment' => (string) ($row['payment_method_label'] ?? ''),
        'amount' => (float) ($row['amount'] ?? 0),
        'currency' => (string) ($row['currency_display'] ?? $row['currency_code'] ?? 'TZS'),
        'isPosted' => (int) ($row['is_posted'] ?? 0) === 1,
        'contribution' => $contribution,
        'note' => $note,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function expenses_fetch_kpi_trace_rows(PDO $pdo, string $scope): array
{
    $scopeSql = expenses_scope_sql('e');
    $limit = 500;

    if ($scope === 'posted_month') {
        $month = date('Y-m');
        $stmt = $pdo->prepare(
            "SELECT e.* FROM erp_expenses e
             WHERE $scopeSql AND e.is_posted = 1 AND e.date LIKE ?
             ORDER BY e.date DESC, e.id DESC
             LIMIT $limit"
        );
        $stmt->execute(["$month%"]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($scope === 'total') {
        $stmt = $pdo->query(
            "SELECT e.* FROM erp_expenses e
             WHERE $scopeSql
             ORDER BY e.date DESC, e.id DESC
             LIMIT $limit"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return [];
}

/**
 * Plain-language KPI confirmation (deterministic; AI can override via API).
 *
 * @param array<string, mixed> $context
 */
function expenses_kpi_build_confirmation(string $key, array $context): string
{
    $monthLabel = (string) ($context['monthLabel'] ?? date('F Y'));
    $postedCount = (int) ($context['postedCount'] ?? 0);
    $spendAmount = (float) ($context['spendAmount'] ?? 0);
    $totalCount = (int) ($context['totalCount'] ?? 0);
    $listedCount = (int) ($context['listedCount'] ?? 0);
    $itemCount = (int) ($context['itemCount'] ?? 0);
    $spendFormatted = number_format($spendAmount, 2);

    if ($key === 'postedThisMonth') {
        if ($postedCount === 0) {
            return "No expenses were posted in {$monthLabel}. We only count expenses that are fully recorded in the accounts and dated this month. Drafts and unposted expenses are not included.";
        }

        return "You have {$postedCount} " . ($postedCount === 1 ? 'expense' : 'expenses') . " posted in {$monthLabel}. These are expenses that were recorded in the accounts this month.";
    }

    if ($key === 'monthlySpend') {
        if ($postedCount === 0) {
            return "Nothing was spent on posted expenses in {$monthLabel}. We only add up amounts from expenses that are posted and dated this month.";
        }

        return "You have spent TZS {$spendFormatted} on posted expenses in {$monthLabel}. Draft and unposted expenses are not included in this total.";
    }

    if ($key === 'totalRecords') {
        $sampleNote = $itemCount < $totalCount
            ? " The list below shows {$itemCount} of them."
            : '';

        return "You have {$totalCount} " . ($totalCount === 1 ? 'expense' : 'expenses') . " saved in the system. Deleted expenses are not counted.{$sampleNote}";
    }

    if ($key === 'listedNow') {
        if ($listedCount === 0) {
            return 'No expenses match your current search or filters. Try clearing them to see more results.';
        }

        return "{$listedCount} " . ($listedCount === 1 ? 'expense is' : 'expenses are') . " shown in the table right now, based on your search and filters.";
    }

    return '';
}

/**
 * Build server-side KPI trace payloads for the expenses desk.
 *
 * @return array<string, array<string, mixed>>
 */
function expenses_build_kpi_traces(PDO $pdo): array
{
    $summary = expenses_build_listed_summary_stats($pdo, []);
    $rows = expenses_fetch_listed_rows($pdo, []);
    $criteria = [
        ['label' => 'Universe', 'value' => 'Listed expenses + General Payment vouchers'],
        ['label' => 'Included', 'value' => 'Posted/paid spend, plus Credit/payables rows'],
        ['label' => 'Excluded', 'value' => 'Draft, rejected, deleted'],
        ['label' => 'API', 'value' => 'modules/expenses/api/desk-init.php'],
    ];

    $mapAmountItems = static function (array $rows, callable $contribFn, string $note) use ($pdo): array {
        $items = [];
        foreach ($rows as $row) {
            if (!expenses_listed_row_in_summary($row)) {
                continue;
            }
            $contrib = (float) $contribFn($row);
            if ($contrib <= 0) {
                continue;
            }
            $items[] = expenses_map_kpi_trace_item($pdo, $row, $contrib, $note);
            if (count($items) >= 500) {
                break;
            }
        }
        return $items;
    };

    $bucketItems = static function (array $rows, string $bucket) use ($pdo): array {
        $items = [];
        foreach ($rows as $row) {
            if (!expenses_listed_row_in_summary($row)) {
                continue;
            }
            $rowBucket = expenses_listed_row_payment_bucket($row);
            $normalized = $rowBucket === 'other' ? 'bank' : $rowBucket;
            if ($normalized !== $bucket) {
                continue;
            }
            $amount = (float) ($row['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $items[] = expenses_map_kpi_trace_item($pdo, $row, $amount, 'Counted in ' . $bucket);
            if (count($items) >= 500) {
                break;
            }
        }
        return $items;
    };

    $exclusiveItems = $mapAmountItems(
        $rows,
        static function (array $row): float {
            $amount = (float) ($row['amount'] ?? 0);
            $tax = max(0.0, min($amount, (float) ($row['tax_amount'] ?? 0)));
            return max(0.0, $amount - $tax);
        },
        'Exclusive amount (gross − VAT)'
    );
    $vatItems = $mapAmountItems(
        $rows,
        static function (array $row): float {
            $amount = (float) ($row['amount'] ?? 0);
            return max(0.0, min($amount, (float) ($row['tax_amount'] ?? 0)));
        },
        'Input VAT'
    );
    $inclusiveItems = $mapAmountItems(
        $rows,
        static fn(array $row): float => (float) ($row['amount'] ?? 0),
        'Gross amount (VAT inclusive)'
    );

    return [
        'totalExclusive' => [
            'title' => 'Total Expenses (Exclusive)',
            'headline' => (string) ($summary['total_exclusive'] ?? 0),
            'currency' => 'TZS',
            'source' => 'listed expenses',
            'method' => 'SUM(amount − tax_amount) on posted/paid listed rows (and credit payables)',
            'criteria' => $criteria,
            'confirmation' => 'This is the VAT-exclusive total of posted and payable expenses in the list.',
            'viaAi' => false,
            'items' => $exclusiveItems,
            'footnote' => '',
        ],
        'inputVat' => [
            'title' => 'Input VAT',
            'headline' => (string) ($summary['input_vat'] ?? 0),
            'currency' => 'TZS',
            'source' => 'listed expenses',
            'method' => 'SUM(tax_amount) on posted/paid listed rows (and credit payables)',
            'criteria' => $criteria,
            'confirmation' => 'This is recoverable input VAT recorded on those expenses.',
            'viaAi' => false,
            'items' => $vatItems,
            'footnote' => '',
        ],
        'totalInclusive' => [
            'title' => 'Total Expenses (VAT + Exclusive)',
            'headline' => (string) ($summary['total_inclusive'] ?? 0),
            'currency' => 'TZS',
            'source' => 'listed expenses',
            'method' => 'SUM(amount) on posted/paid listed rows (and credit payables)',
            'criteria' => $criteria,
            'confirmation' => 'This is the full expense total including VAT.',
            'viaAi' => false,
            'items' => $inclusiveItems,
            'footnote' => '',
        ],
        'totalBank' => [
            'title' => 'Total Bank',
            'headline' => (string) ($summary['total_bank'] ?? 0),
            'currency' => 'TZS',
            'source' => 'listed expenses',
            'method' => 'SUM(amount) where payment is bank transfer, mobile, or cheque',
            'criteria' => $criteria,
            'confirmation' => 'Expenses settled through bank, mobile money, or cheque.',
            'viaAi' => false,
            'items' => $bucketItems($rows, 'bank'),
            'footnote' => '',
        ],
        'totalCash' => [
            'title' => 'Total Cash',
            'headline' => (string) ($summary['total_cash'] ?? 0),
            'currency' => 'TZS',
            'source' => 'listed expenses',
            'method' => 'SUM(amount) where payment method is cash',
            'criteria' => $criteria,
            'confirmation' => 'Expenses settled in cash.',
            'viaAi' => false,
            'items' => $bucketItems($rows, 'cash'),
            'footnote' => '',
        ],
        'totalPayables' => [
            'title' => 'Total Payables',
            'headline' => (string) ($summary['total_payables'] ?? 0),
            'currency' => 'TZS',
            'source' => 'listed expenses',
            'method' => 'SUM(amount) where payment type is Credit / payable',
            'criteria' => $criteria,
            'confirmation' => 'Expenses on credit that count as payables.',
            'viaAi' => false,
            'items' => $bucketItems($rows, 'payables'),
            'footnote' => '',
        ],
    ];
}

/**
 * Optional AI verification of a KPI trace (falls back to computed confirmation).
 *
 * @param array<string, mixed> $trace
 * @return array{confirmation: string, viaAi: bool}
 */
function expenses_kpi_ai_confirm(string $key, array $trace): array
{
    $computed = (string) ($trace['confirmation'] ?? '');
    if ($computed === '') {
        $computed = expenses_kpi_build_confirmation($key, [
            'postedCount' => (int) ($trace['headline'] ?? 0),
            'spendAmount' => (float) ($trace['headline'] ?? 0),
            'totalCount' => (int) ($trace['headline'] ?? 0),
            'listedCount' => (int) ($trace['headline'] ?? 0),
            'itemCount' => count($trace['items'] ?? []),
        ]);
    }

    if (!function_exists('ai_openai_request')) {
        return ['confirmation' => $computed, 'viaAi' => false];
    }

    $aiConnected = function_exists('balances_ai_is_connected') && balances_ai_is_connected();
    if (!$aiConnected) {
        return ['confirmation' => $computed, 'viaAi' => false];
    }

    $title = (string) ($trace['title'] ?? $key);
    $headline = (string) ($trace['headline'] ?? '');
    $method = (string) ($trace['method'] ?? '');
    $itemCount = count($trace['items'] ?? []);
    $criteriaLines = [];
    foreach ($trace['criteria'] ?? [] as $line) {
        if (!is_array($line)) {
            continue;
        }
        $criteriaLines[] = ($line['label'] ?? '') . ': ' . ($line['value'] ?? '');
    }

    $messages = [
        [
            'role' => 'system',
            'content' => 'You explain expense totals in plain language that any business user can understand. Use short, simple sentences. Do not use technical terms, database field names, SQL, or jargon like is_posted or ledger. Reply in 2-4 sentences. Be direct; no markdown.',
        ],
        [
            'role' => 'user',
            'content' => "KPI: {$title}\nHeadline value: {$headline}\nMethod: {$method}\nCriteria:\n" . implode("\n", $criteriaLines) . "\nContributing rows in breakdown: {$itemCount}\nComputed summary: {$computed}",
        ],
    ];

    try {
        $result = ai_openai_request($messages);
        $content = trim((string) ($result['content'] ?? $result['message'] ?? ''));
        if ($content !== '') {
            return ['confirmation' => $content, 'viaAi' => true];
        }
    } catch (Throwable $e) {
        // fall through
    }

    return ['confirmation' => $computed, 'viaAi' => false];
}

/**
 * Insights dashboard stats for Smart Insights page.
 *
 * @return array<string, mixed>
 */
function expenses_fetch_insights_stats(PDO $pdo, string $month = ''): array
{
    if ($month === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $scopeSql = expenses_scope_sql();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_expenses WHERE $scopeSql AND is_posted = 1 AND date LIKE ?");
    $stmt->execute(["$month%"]);
    $postedMonthCount = (int) ($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM erp_expenses WHERE $scopeSql AND is_posted = 1 AND date LIKE ?");
    $stmt->execute(["$month%"]);
    $spendMonth = (float) $stmt->fetchColumn();

    $totalCount = (int) ($pdo->query("SELECT COUNT(*) FROM erp_expenses WHERE $scopeSql")->fetchColumn() ?: 0);

    $totalVolume = (float) ($pdo->query("SELECT COALESCE(SUM(amount), 0) FROM erp_expenses WHERE $scopeSql AND is_posted = 1")->fetchColumn() ?: 0);

    $pendingCount = (int) ($pdo->query(
        "SELECT COUNT(*) FROM erp_expenses WHERE $scopeSql AND status = 'pending'"
    )->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(tax_amount), 0) FROM erp_expenses WHERE $scopeSql AND is_posted = 1 AND date LIKE ?");
    $stmt->execute(["$month%"]);
    $totalTax = (float) $stmt->fetchColumn();

    $trends = [];
    $trendStmt = $pdo->query(
        "SELECT DATE_FORMAT(date, '%Y-%m') AS ym, COALESCE(SUM(amount), 0) AS total
         FROM erp_expenses
         WHERE $scopeSql AND is_posted = 1 AND date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
         GROUP BY ym
         ORDER BY ym ASC"
    );
    foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $ym = (string) ($row['ym'] ?? '');
        if ($ym === '') {
            continue;
        }
        $label = DateTime::createFromFormat('Y-m', $ym);
        $trends[] = [
            'name' => $label ? $label->format('M Y') : $ym,
            'amount' => (float) ($row['total'] ?? 0),
        ];
    }

    $scopeSqlE = expenses_scope_sql('e');
    $byCategory = [];
    $catStmt = $pdo->prepare(
        "SELECT e.account_id, COALESCE(SUM(e.amount), 0) AS total
         FROM erp_expenses e
         WHERE $scopeSqlE AND e.is_posted = 1 AND e.date LIKE ?
         GROUP BY e.account_id
         ORDER BY total DESC
         LIMIT 8"
    );
    $catStmt->execute(["$month%"]);
    foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $accountId = (int) ($row['account_id'] ?? 0);
        $label = $accountId > 0 ? expenses_resolve_category_name($pdo, $accountId) : 'Uncategorized';
        if ($label === '') {
            $label = 'Uncategorized';
        }
        $byCategory[] = [
            'name' => $label,
            'value' => (float) ($row['total'] ?? 0),
        ];
    }

    $postedStatusCount = (int) ($pdo->query("SELECT COUNT(*) FROM erp_expenses WHERE $scopeSql AND is_posted = 1")->fetchColumn() ?: 0);
    $draftCount = (int) ($pdo->query(
        "SELECT COUNT(*) FROM erp_expenses WHERE $scopeSql AND status = 'draft'"
    )->fetchColumn() ?: 0);
    $unpostedCount = (int) ($pdo->query(
        "SELECT COUNT(*) FROM erp_expenses WHERE $scopeSql AND is_posted = 0 AND status NOT IN ('draft', 'rejected', 'deleted', 'pending')"
    )->fetchColumn() ?: 0);

    $byStatus = array_values(array_filter([
        ['name' => 'Posted', 'value' => $postedStatusCount],
        ['name' => 'Pending', 'value' => $pendingCount],
        ['name' => 'Draft', 'value' => $draftCount],
        ['name' => 'Unposted', 'value' => $unpostedCount],
    ], static fn(array $row): bool => (float) ($row['value'] ?? 0) > 0));

    return [
        'posted_month_count' => $postedMonthCount,
        'spend_month' => $spendMonth,
        'total_count' => $totalCount,
        'total_volume' => $totalVolume,
        'pending_count' => $pendingCount,
        'total_tax' => $totalTax,
        'current_month' => $month,
        'current_month_label' => DateTime::createFromFormat('Y-m', $month)?->format('F Y') ?? $month,
        'trends' => $trends,
        'by_category' => $byCategory,
        'by_status' => $byStatus,
    ];
}
