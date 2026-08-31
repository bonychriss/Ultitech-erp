<?php

declare(strict_types=1);

/**
 * Petty cash ? Balances (financial_accounts / account_transactions).
 */

function petty_cash_balances_bootstrap(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $path = dirname(__DIR__, 2) . '/balances/functions.php';
    if (is_file($path)) {
        require_once $path;
    }
    if (function_exists('ensureBalancesSchema')) {
        ensureBalancesSchema();
    }

    $expensesBalances = dirname(__DIR__, 2) . '/expenses/includes/balances_integration.php';
    if (is_file($expensesBalances)) {
        require_once $expensesBalances;
    }

    $loaded = true;
}

/**
 * PDO for Balances / financial_accounts lookups.
 * Uses the active company DB when tenant-isolated. Never replaces global $pdo (vouchers).
 */
function petty_cash_sync_balances_pdo(?PDO $conn = null): PDO
{
    global $pdo;
    static $cached = null;
    if ($cached instanceof PDO) {
        return $cached;
    }

    // Tenant company DB is authoritative for that company.
    if (defined('IS_TENANT_DB') && IS_TENANT_DB && $pdo instanceof PDO) {
        $cached = $pdo;

        return $cached;
    }

    if (function_exists('balances_resolve_pdo')) {
        $resolved = balances_resolve_pdo();
        if ($resolved instanceof PDO) {
            $cached = $resolved;

            return $cached;
        }
    }

    if (function_exists('balancesSyncGlobalPdo')) {
        $synced = balancesSyncGlobalPdo(null);
        if ($synced instanceof PDO) {
            $cached = $synced;

            return $cached;
        }
    }

    if ($conn instanceof PDO) {
        $cached = $conn;

        return $cached;
    }
    if ($pdo instanceof PDO) {
        $cached = $pdo;

        return $cached;
    }

    throw new RuntimeException('Database connection is not available.');
}

/**
 * All top-level Petty Cash parent ids in the current COA DB.
 *
 * @return list<int>
 */
function petty_cash_list_petty_parent_ids(PDO $pdo): array
{
    if (!function_exists('tableExists') || !tableExists('financial_accounts', $pdo)) {
        return [];
    }

    if (function_exists('coa_ensure_parent_id_column')) {
        coa_ensure_parent_id_column($pdo);
    }

    $ids = [];
    try {
        $hasParent = function_exists('columnExists') && columnExists('financial_accounts', 'parent_id', $pdo);
        $sql = "SELECT id FROM financial_accounts
                WHERE status = 'active'
                  AND (
                    name LIKE '1100 - Petty Cash%'
                    OR name = 'Petty Cash'
                    OR LOWER(name) LIKE '%petty cash%'
                  )";
        if ($hasParent) {
            $sql .= ' AND (parent_id IS NULL OR parent_id = 0)';
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = $pdo->query($sql);
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $ids;
}

function petty_cash_module_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (function_exists('pettyCashMigrateTableColumns')) {
        pettyCashMigrateTableColumns($pdo, 'petty_cash_vouchers', [
            'petty_cash_account_id' => 'INT NULL',
            'expense_account_id' => 'INT NULL',
            'is_posted' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'payment_transaction_id' => 'INT NULL',
            'expense_transaction_id' => 'INT NULL',
        ]);
    }

    $done = true;

    // Move legacy petty-cash rows out of erp_expenses into this module.
    if (function_exists('petty_cash_migrate_expenses_from_erp')) {
        petty_cash_migrate_expenses_from_erp($pdo);
    }
}

/**
 * @param array<string, mixed> $row
 * @return array{id:int,name:string,type:string,balance:float,parent_id:int,label:string}
 */
function petty_cash_format_account_option(array $row): array
{
    $name = (string) ($row['name'] ?? $row['label'] ?? '');
    $parentId = (int) ($row['parent_id'] ?? 0);

    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => $name,
        'label' => (string) ($row['label'] ?? $name),
        'type' => (string) ($row['type'] ?? ''),
        'balance' => (float) ($row['live_balance'] ?? $row['current_balance'] ?? $row['balance'] ?? 0),
        'parent_id' => $parentId,
    ];
}

/**
 * Active company for petty cash / Balances lookups.
 */
function petty_cash_current_company_id(): int
{
    return function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
}

/**
 * Align sub-account company_id with the petty cash parent for the active company.
 */
function petty_cash_sync_sub_accounts_for_parent(PDO $pdo, int $parentId): void
{
    if ($parentId <= 0 || !function_exists('columnExists') || !columnExists('financial_accounts', 'company_id', $pdo)) {
        return;
    }

    $companyId = petty_cash_current_company_id();
    if ($companyId <= 0) {
        return;
    }

    try {
        $pdo->prepare(
            'UPDATE financial_accounts child
             INNER JOIN financial_accounts parent ON parent.id = child.parent_id
             SET child.company_id = ?
             WHERE child.parent_id = ?
               AND child.status = \'active\'
               AND (child.company_id IS NULL OR child.company_id = 0)
               AND (parent.company_id IS NULL OR parent.company_id = 0 OR parent.company_id = ?)'
        )->execute([$companyId, $parentId, $companyId]);
    } catch (Throwable $e) {
        // Non-fatal; picker will still show rows that already match company scope.
    }
}

/**
 * Sub-accounts under a Balances parent (Fuel, Transport, etc.).
 *
 * @return list<array{id:int,name:string,type:string,balance:float,parent_id:int,label:string}>
 */
function petty_cash_fetch_category_accounts_for_parent(PDO $pdo, int $parentId): array
{
    if ($parentId <= 0) {
        return [];
    }

    petty_cash_balances_bootstrap();
    $pdo = petty_cash_sync_balances_pdo($pdo);
    if (function_exists('coa_ensure_parent_id_column')) {
        coa_ensure_parent_id_column($pdo);
    }

    $parentIds = [$parentId];
    foreach (petty_cash_list_petty_parent_ids($pdo) as $altId) {
        if ($altId > 0 && !in_array($altId, $parentIds, true)) {
            $parentIds[] = $altId;
        }
    }

    $rows = [];
    $seen = [];
    foreach ($parentIds as $pid) {
        $chunk = [];
        if (function_exists('balances_fetch_raw_child_accounts')) {
            $chunk = balances_fetch_raw_child_accounts($pdo, $pid, true);
        } elseif (function_exists('tableExists') && tableExists('financial_accounts', $pdo)) {
            try {
                $hasParent = function_exists('columnExists') && columnExists('financial_accounts', 'parent_id', $pdo);
                if ($hasParent) {
                    $where = ['parent_id = ?', "status = 'active'"];
                    $params = [$pid];
                    if (function_exists('balances_accounts_company_filter_sql')) {
                        [$companySql, $companyParams] = balances_accounts_company_filter_sql('', true, $pdo);
                        if ($companySql !== '') {
                            $where[] = $companySql;
                            $params = array_merge($params, $companyParams);
                        }
                    }
                    $stmt = $pdo->prepare(
                        'SELECT id, name, type, current_balance, parent_id
                         FROM financial_accounts
                         WHERE ' . implode(' AND ', $where) . '
                         ORDER BY name ASC'
                    );
                    $stmt->execute($params);
                    $chunk = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            } catch (Throwable $e) {
                $chunk = [];
            }
        }

        foreach ($chunk as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $rows[] = $row;
        }

        // Prefer children under the requested parent; only scan other parents if empty.
        if ($pid === $parentId && $rows !== []) {
            break;
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $formatted = petty_cash_format_account_option($row);
        if ($formatted['id'] > 0 && $formatted['name'] !== '') {
            $out[] = $formatted;
        }
    }

    return $out;
}

/**
 * Payload for create-voucher React form.
 *
 * @return array<string, mixed>
 */
function petty_cash_build_create_form_init(PDO $pdo, int $userId): array
{
    petty_cash_balances_bootstrap();
    $pdo = petty_cash_sync_balances_pdo($pdo);
    petty_cash_module_ensure_schema($pdo);

    $mainAccount = petty_cash_ensure_default_main_account($pdo);
    $defaultPettyId = (int) ($mainAccount['id'] ?? 0);
    if ($defaultPettyId <= 0) {
        $defaultPettyId = petty_cash_resolve_custodian_petty_account_id($pdo, $userId);
        if ($defaultPettyId > 0 && function_exists('expenses_resolve_financial_account')) {
            $row = expenses_resolve_financial_account($pdo, $defaultPettyId);
            $mainAccount = $row ? petty_cash_format_account_option($row) : null;
        }
    }

    $pettyAccounts = $mainAccount ? [$mainAccount] : [];
    $childrenByParent = [];
    if ($defaultPettyId > 0) {
        petty_cash_sync_sub_accounts_for_parent($pdo, $defaultPettyId);
        $children = petty_cash_fetch_category_accounts_for_parent($pdo, $defaultPettyId);
        $childrenByParent[(string) $defaultPettyId] = $children;
    }

    $balancesUrl = function_exists('app_url')
        ? app_url('/modules/balances/accounts.php?module=balances')
        : '../balances/accounts.php?module=balances';
    $slug = function_exists('pettyCashDeskCompanySlug') ? pettyCashDeskCompanySlug() : '';
    if ($slug !== '') {
        $balancesUrl .= (str_contains($balancesUrl, '?') ? '&' : '?') . 'company_slug=' . rawurlencode($slug);
    }

    $createSubUrl = function_exists('app_url')
        ? app_url('/modules/balances/coa_create.php?module=balances')
        : '../balances/coa_create.php?module=balances';
    if ($defaultPettyId > 0) {
        $createSubUrl .= (str_contains($createSubUrl, '?') ? '&' : '?') . 'parent_id=' . $defaultPettyId;
    }
    if ($slug !== '') {
        $createSubUrl .= (str_contains($createSubUrl, '?') ? '&' : '?') . 'company_slug=' . rawurlencode($slug);
    }

    return [
        'balance' => getPettyCashBalance($userId),
        'has_financial_accounts' => $defaultPettyId > 0,
        'petty_cash_account_locked' => true,
        'petty_cash_account' => $mainAccount,
        'petty_accounts' => $pettyAccounts,
        'category_accounts' => $childrenByParent[(string) $defaultPettyId] ?? [],
        'category_accounts_by_parent' => $childrenByParent,
        'expense_fallback_accounts' => [],
        'default_petty_cash_account_id' => $defaultPettyId > 0 ? (string) $defaultPettyId : '',
        'balances_url' => $balancesUrl,
        'create_sub_account_url' => $createSubUrl,
        'urls' => [
            'desk' => pettyCashModuleUrl('index.php'),
            'view_voucher' => pettyCashModuleUrl('view-voucher.php'),
        ],
    ];
}

/**
 * Validate selected Balances accounts for a new voucher.
 *
 * @return array{ok:bool,message:string,petty_cash_account_id:int,expense_account_id:int,category:string}
 */
function petty_cash_validate_voucher_accounts(PDO $pdo, int $pettyCashAccountId, int $categoryAccountId): array
{
    petty_cash_balances_bootstrap();
    $pdo = petty_cash_sync_balances_pdo($pdo);

    if ($pettyCashAccountId <= 0) {
        return [
            'ok' => false,
            'message' => 'Select a petty cash account from Balances.',
            'petty_cash_account_id' => 0,
            'expense_account_id' => 0,
            'category' => '',
        ];
    }
    if ($categoryAccountId <= 0) {
        return [
            'ok' => false,
            'message' => 'Select a category sub-account (e.g. Fuel, Transport).',
            'petty_cash_account_id' => $pettyCashAccountId,
            'expense_account_id' => 0,
            'category' => '',
        ];
    }

    $petty = function_exists('pettyCashGetFinancialAccount')
        ? pettyCashGetFinancialAccount($pettyCashAccountId)
        : null;
    if (!$petty && function_exists('expenses_resolve_financial_account')) {
        $petty = expenses_resolve_financial_account($pdo, $pettyCashAccountId);
    }
    if (!$petty) {
        return [
            'ok' => false,
            'message' => 'Petty cash account was not found in Balances.',
            'petty_cash_account_id' => 0,
            'expense_account_id' => 0,
            'category' => '',
        ];
    }

    $category = function_exists('expenses_resolve_financial_account')
        ? expenses_resolve_financial_account($pdo, $categoryAccountId)
        : (function_exists('pettyCashGetFinancialAccount') ? pettyCashGetFinancialAccount($categoryAccountId) : null);

    if (!$category) {
        return [
            'ok' => false,
            'message' => 'Category account was not found in Balances.',
            'petty_cash_account_id' => $pettyCashAccountId,
            'expense_account_id' => 0,
            'category' => '',
        ];
    }

    $categoryParentId = (int) ($category['parent_id'] ?? 0);
    $isChildOfPetty = $categoryParentId === $pettyCashAccountId;
    $isExpense = false;
    if (function_exists('expenses_is_expense_account_type')) {
        $isExpense = expenses_is_expense_account_type((string) ($category['type'] ?? ''));
    } else {
        $type = strtolower((string) ($category['type'] ?? ''));
        $isExpense = in_array($type, ['expense', 'cost', 'overhead'], true);
    }

    if (!$isChildOfPetty && !$isExpense) {
        return [
            'ok' => false,
            'message' => 'Category must be a sub-account under the selected petty cash account, or an expense account in Balances.',
            'petty_cash_account_id' => $pettyCashAccountId,
            'expense_account_id' => 0,
            'category' => '',
        ];
    }

    if ($isChildOfPetty && $categoryAccountId === $pettyCashAccountId) {
        return [
            'ok' => false,
            'message' => 'Choose a category sub-account, not the main petty cash account.',
            'petty_cash_account_id' => $pettyCashAccountId,
            'expense_account_id' => 0,
            'category' => '',
        ];
    }

    return [
        'ok' => true,
        'message' => '',
        'petty_cash_account_id' => $pettyCashAccountId,
        'expense_account_id' => $categoryAccountId,
        'category' => (string) ($category['name'] ?? ''),
    ];
}

function petty_cash_voucher_already_posted(PDO $pdo, int $voucherId): bool
{
    if ($voucherId <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT is_posted FROM petty_cash_vouchers WHERE id = ? LIMIT 1');
        $stmt->execute([$voucherId]);

        return (int) ($stmt->fetchColumn() ?: 0) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Ensure the default Petty Cash parent exists in Balances (same pattern as Revenue).
 *
 * @return array{id:int,name:string,type:string,balance:float,parent_id:int,label:string}|null
 */
function petty_cash_ensure_default_main_account(PDO $pdo): ?array
{
    petty_cash_balances_bootstrap();
    $pdo = petty_cash_sync_balances_pdo($pdo);
    if (function_exists('coa_ensure_parent_id_column')) {
        coa_ensure_parent_id_column($pdo);
    }

    $companyId = petty_cash_current_company_id();

    if (function_exists('balances_ensure_default_accounts')) {
        try {
            balances_ensure_default_accounts($pdo, $companyId > 0 ? $companyId : null);
        } catch (Throwable $e) {
            error_log('petty_cash_ensure_default_main_account seed: ' . $e->getMessage());
        }
    }

    $candidates = [];

    if (function_exists('coa_find_seed_account')) {
        $seedId = (int) (coa_find_seed_account(
            $pdo,
            '1100',
            'Petty Cash',
            $companyId > 0 ? $companyId : null
        ) ?: 0);
        if ($seedId > 0) {
            try {
                if (function_exists('columnExists') && columnExists('financial_accounts', 'is_system', $pdo)) {
                    $pdo->prepare('UPDATE financial_accounts SET is_system = 1, status = \'active\' WHERE id = ?')->execute([$seedId]);
                }
            } catch (Throwable $e) {
                // ignore lock sync failure
            }
            $row = function_exists('expenses_resolve_financial_account')
                ? expenses_resolve_financial_account($pdo, $seedId)
                : (function_exists('pettyCashGetFinancialAccount') ? pettyCashGetFinancialAccount($seedId) : null);
            if ($row) {
                return petty_cash_format_account_option($row);
            }
        }
    }

    if (function_exists('tableExists') && tableExists('financial_accounts', $pdo)) {
        try {
            $where = [
                "status = 'active'",
                '(parent_id IS NULL OR parent_id = 0)',
                "(
                    name LIKE '1100 - Petty Cash%'
                    OR name = 'Petty Cash'
                    OR LOWER(name) LIKE '%petty cash%'
                )",
            ];
            $params = [];
            if (function_exists('balances_accounts_company_filter_sql')) {
                [$companySql, $companyParams] = balances_accounts_company_filter_sql('', true, $pdo);
                if ($companySql !== '') {
                    $where[] = $companySql;
                    $params = array_merge($params, $companyParams);
                }
            }
            $stmt = $pdo->prepare(
                'SELECT id, name, type, current_balance, parent_id, status
                 FROM financial_accounts
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY
                    CASE
                        WHEN name LIKE \'1100 - Petty Cash%\' THEN 0
                        WHEN name = \'Petty Cash\' THEN 1
                        ELSE 2
                    END,
                    id ASC
                 LIMIT 5'
            );
            $stmt->execute($params);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $candidates = [];
        }
    }

    if ($candidates === [] && function_exists('pettyCashListFinancialAccounts')) {
        foreach (pettyCashListFinancialAccounts('petty') as $row) {
            if ((int) ($row['parent_id'] ?? 0) <= 0) {
                $candidates[] = $row;
                break;
            }
        }
    }

    if ($candidates === []) {
        return null;
    }

    return petty_cash_format_account_option($candidates[0]);
}

/**
 * Latest approved replenishment petty-cash GL account for a custodian.
 */
function petty_cash_resolve_custodian_petty_account_id(PDO $pdo, int $custodianId): int
{
    if ($custodianId > 0) {
        try {
            $stmt = $pdo->prepare(
                "SELECT petty_cash_account_id FROM petty_cash_replenishments
                 WHERE custodian_id = ? AND status = 'approved' AND petty_cash_account_id IS NOT NULL
                 ORDER BY COALESCE(approved_at, created_at) DESC, id DESC LIMIT 1"
            );
            $stmt->execute([$custodianId]);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        } catch (Throwable $e) {
            // fall through
        }
    }

    $default = petty_cash_ensure_default_main_account($pdo);
    if ($default && (int) ($default['id'] ?? 0) > 0) {
        return (int) $default['id'];
    }

    if (!function_exists('pettyCashListFinancialAccounts')) {
        return 0;
    }

    foreach (pettyCashListFinancialAccounts('petty') as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
    }

    foreach (pettyCashListFinancialAccounts('all') as $row) {
        $name = strtolower((string) ($row['name'] ?? ''));
        if (str_contains($name, 'petty')) {
            return (int) ($row['id'] ?? 0);
        }
    }

    return 0;
}

/**
 * Map petty cash category label to an expense account in balances.
 */
function petty_cash_resolve_expense_account_id(PDO $pdo, string $categoryName): int
{
    $categoryName = trim($categoryName);
    if ($categoryName === '') {
        return 0;
    }

    petty_cash_balances_bootstrap();

    if (function_exists('expenses_fetch_expense_sub_accounts')) {
        foreach (expenses_fetch_expense_sub_accounts($pdo) as $row) {
            $label = strtolower(trim((string) ($row['label'] ?? $row['name'] ?? '')));
            if ($label === strtolower($categoryName)) {
                return (int) ($row['id'] ?? 0);
            }
        }
    }

    if (!function_exists('tableExists') || !tableExists('financial_accounts', $pdo)) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id FROM financial_accounts
             WHERE status = 'active' AND LOWER(type) IN ('expense', 'cost', 'overhead')
             AND LOWER(name) = LOWER(?)
             ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$categoryName]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }

        $stmt = $pdo->prepare(
            "SELECT id FROM financial_accounts
             WHERE status = 'active' AND LOWER(type) IN ('expense', 'cost', 'overhead')
             AND LOWER(name) LIKE ?
             ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute(['%' . strtolower($categoryName) . '%']);

        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Post an approved voucher to balances (petty cash payment account + expense account).
 *
 * @param array<string, mixed> $voucher
 * @return array{success:bool, message:string}
 */
function petty_cash_post_voucher_to_balances(PDO $pdo, array $voucher): array
{
    petty_cash_balances_bootstrap();
    $pdo = petty_cash_sync_balances_pdo($pdo);

    $voucherId = (int) ($voucher['id'] ?? 0);
    if ($voucherId <= 0) {
        return ['success' => false, 'message' => 'Invalid voucher.'];
    }
    if (petty_cash_voucher_already_posted($pdo, $voucherId)) {
        return ['success' => true, 'message' => 'Voucher already posted to balances.'];
    }

    $amount = (float) ($voucher['amount'] ?? 0);
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Voucher amount must be greater than zero.'];
    }

    $pettyAccountId = (int) ($voucher['petty_cash_account_id'] ?? 0);
    $expenseAccountId = (int) ($voucher['expense_account_id'] ?? 0);
    if ($pettyAccountId <= 0) {
        $pettyAccountId = petty_cash_resolve_custodian_petty_account_id($pdo, (int) ($voucher['custodian_id'] ?? 0));
    }
    if ($expenseAccountId <= 0) {
        $expenseAccountId = petty_cash_resolve_expense_account_id($pdo, (string) ($voucher['category'] ?? ''));
    }

    // If the selected category is a cash sub-wallet under petty cash, map to a matching expense account by name.
    if ($expenseAccountId > 0 && function_exists('expenses_resolve_financial_account') && function_exists('expenses_is_expense_account_type')) {
        $expenseRow = expenses_resolve_financial_account($pdo, $expenseAccountId);
        if ($expenseRow && !expenses_is_expense_account_type((string) ($expenseRow['type'] ?? ''))) {
            $mapped = petty_cash_resolve_expense_account_id($pdo, (string) ($expenseRow['name'] ?? $voucher['category'] ?? ''));
            if ($mapped > 0) {
                $expenseAccountId = $mapped;
            }
        }
    }

    if ($pettyAccountId <= 0 || $expenseAccountId <= 0) {
        return [
            'success' => false,
            'message' => 'Could not resolve petty cash and expense accounts in Balances. '
                . 'Approve a top-up with accounts selected, or map the category to an expense account.',
        ];
    }

    if (!function_exists('expenses_post_to_balances')) {
        return ['success' => false, 'message' => 'Expenses/Balances integration is not available.'];
    }

    $desc = trim((string) ($voucher['description'] ?? ''));
    $label = 'Petty cash voucher ' . (string) ($voucher['voucher_number'] ?? ('#' . $voucherId));
    $txDate = (string) ($voucher['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txDate)) {
        $txDate = date('Y-m-d');
    }
    $txDateTime = $txDate . ' ' . date('H:i:s');

    $companyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;

    if (!function_exists('balancesRecordTransaction')) {
        return ['success' => false, 'message' => 'Balances module is not available.'];
    }

    if (function_exists('expenses_resolve_financial_account')) {
        $pettyRow = expenses_resolve_financial_account($pdo, $pettyAccountId);
        $expenseRow = expenses_resolve_financial_account($pdo, $expenseAccountId);
        if (!$pettyRow || !$expenseRow) {
            return ['success' => false, 'message' => 'Selected accounts were not found in Balances.'];
        }
    }

    $paymentOk = balancesRecordTransaction(
        $pdo,
        $pettyAccountId,
        'debit',
        $amount,
        'Petty cash payment: ' . ($desc !== '' ? $desc : $label),
        'petty_cash_voucher',
        $voucherId,
        $txDateTime,
        $companyId > 0 ? $companyId : null
    );
    if (!$paymentOk) {
        return ['success' => false, 'message' => 'Could not post payment from the petty cash account.'];
    }

    $expenseOk = balancesRecordTransaction(
        $pdo,
        $expenseAccountId,
        'debit',
        $amount,
        $label . ($desc !== '' ? ' � ' . $desc : ''),
        'petty_cash_voucher',
        $voucherId,
        $txDateTime,
        $companyId > 0 ? $companyId : null
    );
    if (!$expenseOk) {
        return ['success' => false, 'message' => 'Payment posted but expense account could not be updated.'];
    }

    if (function_exists('balancesRecalculateAccount')) {
        balancesRecalculateAccount($pdo, $pettyAccountId, $companyId > 0 ? $companyId : null);
        balancesRecalculateAccount($pdo, $expenseAccountId, $companyId > 0 ? $companyId : null);
    }

    try {
        $upd = $pdo->prepare(
            'UPDATE petty_cash_vouchers SET
                petty_cash_account_id = ?,
                expense_account_id = ?,
                is_posted = 1,
                updated_at = NOW()
             WHERE id = ?'
        );
        $upd->execute([$pettyAccountId, $expenseAccountId, $voucherId]);
    } catch (Throwable $e) {
        error_log('petty_cash_post_voucher_to_balances mark posted: ' . $e->getMessage());
    }

    return ['success' => true, 'message' => 'Voucher posted to Balances.'];
}

/**
 * Map legacy erp_expenses status values into petty_cash_vouchers.status.
 */
function petty_cash_map_expense_status_to_voucher(string $status, int $isPosted): string
{
    $status = strtolower(trim($status));
    if ($status === 'deleted') {
        return 'cancelled';
    }
    if ($status === 'rejected') {
        return 'rejected';
    }
    if ($status === 'approved' || $isPosted === 1) {
        return 'approved';
    }

    return 'pending';
}

/**
 * Import petty-cash rows that historically lived in erp_expenses into petty_cash_vouchers.
 *
 * @return array{imported:int,skipped:int}
 */
function petty_cash_migrate_expenses_from_erp(PDO $pdo): array
{
    static $ran = false;
    if ($ran) {
        return ['imported' => 0, 'skipped' => 0];
    }
    $ran = true;

    $result = ['imported' => 0, 'skipped' => 0];

    try {
        $hasExpenses = $pdo->query("SHOW TABLES LIKE 'erp_expenses'")->fetchColumn();
        $hasVouchers = $pdo->query("SHOW TABLES LIKE 'petty_cash_vouchers'")->fetchColumn();
        if (!$hasExpenses || !$hasVouchers) {
            return $result;
        }
    } catch (Throwable $e) {
        return $result;
    }

    petty_cash_module_ensure_schema($pdo);

    $pettyIds = [];
    if (function_exists('expenses_petty_cash_account_ids')) {
        $pettyIds = expenses_petty_cash_account_ids($pdo);
    }
    if ($pettyIds === []) {
        try {
            $stmt = $pdo->query(
                "SELECT id FROM financial_accounts WHERE LOWER(name) LIKE '%petty%'"
            );
            $pettyIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            $pettyIds = [];
        }
    }

    $where = [
        "e.status != 'deleted'",
        'e.amount > 0',
        "(e.expense_number LIKE 'PC-%' OR e.expense_number LIKE 'PCV-%'",
    ];
    if ($pettyIds !== []) {
        $idList = implode(',', array_map('intval', $pettyIds));
        $where[2] .= " OR e.source_account_id IN ({$idList}))";
    } else {
        $where[2] .= ')';
    }

    try {
        $sql = 'SELECT e.* FROM erp_expenses e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.id ASC';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('petty_cash_migrate_expenses_from_erp select: ' . $e->getMessage());

        return $result;
    }

    if ($rows === []) {
        return $result;
    }

    $existing = [];
    try {
        $existing = $pdo->query('SELECT voucher_number FROM petty_cash_vouchers WHERE voucher_number IS NOT NULL')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $existing = array_fill_keys(array_map('strval', $existing), true);
    } catch (Throwable $e) {
        $existing = [];
    }

    $hasPettyAccCol = true;
    $hasExpenseAccCol = true;
    $hasIsPostedCol = true;
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM petty_cash_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $colSet = array_fill_keys($cols, true);
        $hasPettyAccCol = isset($colSet['petty_cash_account_id']);
        $hasExpenseAccCol = isset($colSet['expense_account_id']);
        $hasIsPostedCol = isset($colSet['is_posted']);
    } catch (Throwable $e) {
        // use defaults
    }

    $categoryCache = [];
    $resolveCategory = static function (PDO $pdo, int $accountId) use (&$categoryCache): string {
        if ($accountId <= 0) {
            return 'General';
        }
        if (isset($categoryCache[$accountId])) {
            return $categoryCache[$accountId];
        }
        $name = 'General';
        try {
            if (function_exists('expenses_resolve_category_name')) {
                $resolved = expenses_resolve_category_name($pdo, $accountId);
                if ($resolved !== '') {
                    $name = $resolved;
                }
            } else {
                $stmt = $pdo->prepare('SELECT name FROM financial_accounts WHERE id = ? LIMIT 1');
                $stmt->execute([$accountId]);
                $resolved = trim((string) ($stmt->fetchColumn() ?: ''));
                if ($resolved !== '') {
                    $name = $resolved;
                }
            }
        } catch (Throwable $e) {
            $name = 'General';
        }
        if (function_exists('strlen') && function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 100);
        } else {
            $name = substr($name, 0, 100);
        }
        $categoryCache[$accountId] = $name !== '' ? $name : 'General';

        return $categoryCache[$accountId];
    };

    foreach ($rows as $row) {
        $voucherNumber = trim((string) ($row['expense_number'] ?? ''));
        if ($voucherNumber === '') {
            $result['skipped']++;
            continue;
        }
        if (isset($existing[$voucherNumber])) {
            $result['skipped']++;
            continue;
        }

        $custodianId = (int) ($row['created_by'] ?? 0);
        if ($custodianId <= 0) {
            $custodianId = (int) ($row['approved_by'] ?? 0);
        }
        if ($custodianId <= 0) {
            $result['skipped']++;
            continue;
        }

        $status = petty_cash_map_expense_status_to_voucher(
            (string) ($row['status'] ?? 'pending'),
            (int) ($row['is_posted'] ?? 0)
        );
        $isPosted = (int) ($row['is_posted'] ?? 0) === 1 ? 1 : 0;
        $category = $resolveCategory($pdo, (int) ($row['account_id'] ?? 0));
        $description = trim((string) ($row['description'] ?? ''));
        if ($description === '') {
            $payee = trim((string) ($row['payee'] ?? ''));
            $description = $payee !== '' ? $payee : $voucherNumber;
        }
        $date = (string) ($row['date'] ?? date('Y-m-d'));
        $amount = (float) ($row['amount'] ?? 0);
        $receiptPath = $row['attachment'] ?? null;
        $approvedBy = $row['approved_by'] !== null && $row['approved_by'] !== ''
            ? (int) $row['approved_by']
            : null;
        $approvedAt = !empty($row['approved_at']) ? (string) $row['approved_at'] : null;
        if ($status === 'approved' && $approvedAt === null) {
            $approvedAt = !empty($row['created_at']) ? (string) $row['created_at'] : date('Y-m-d H:i:s');
        }
        $pettyAccountId = (int) ($row['source_account_id'] ?? 0);
        $expenseAccountId = (int) ($row['account_id'] ?? 0);
        $createdAt = !empty($row['created_at']) ? (string) $row['created_at'] : date('Y-m-d H:i:s');

        $columns = [
            'voucher_number',
            'date',
            'custodian_id',
            'category',
            'description',
            'amount',
            'receipt_path',
            'status',
            'created_by',
            'approved_by',
            'approved_at',
            'created_at',
        ];
        $values = [
            $voucherNumber,
            $date,
            $custodianId,
            $category,
            $description,
            $amount,
            $receiptPath !== '' ? $receiptPath : null,
            $status,
            $custodianId,
            $approvedBy,
            $approvedAt,
            $createdAt,
        ];

        if ($hasPettyAccCol) {
            $columns[] = 'petty_cash_account_id';
            $values[] = $pettyAccountId > 0 ? $pettyAccountId : null;
        }
        if ($hasExpenseAccCol) {
            $columns[] = 'expense_account_id';
            $values[] = $expenseAccountId > 0 ? $expenseAccountId : null;
        }
        if ($hasIsPostedCol) {
            $columns[] = 'is_posted';
            $values[] = $isPosted;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colSql = implode(', ', $columns);

        try {
            $stmt = $pdo->prepare("INSERT INTO petty_cash_vouchers ({$colSql}) VALUES ({$placeholders})");
            $stmt->execute($values);
            $existing[$voucherNumber] = true;
            $result['imported']++;
        } catch (Throwable $e) {
            error_log('petty_cash_migrate_expenses_from_erp insert ' . $voucherNumber . ': ' . $e->getMessage());
            $result['skipped']++;
        }
    }

    return $result;
}
