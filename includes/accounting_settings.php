<?php
/**
 * Company-scoped accounting configuration (default GL accounts, etc.).
 */

if (!function_exists('accounting_settings_key')) {
    function accounting_settings_key(string $suffix): string
    {
        return 'accounting.' . ltrim($suffix, '.');
    }
}

if (!function_exists('accounting_settings_company_id')) {
    function accounting_settings_company_id(): int
    {
        return function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
    }
}

if (!function_exists('accounting_settings_get')) {
    function accounting_settings_get(PDO $pdo, string $key): ?string
    {
        $companyId = accounting_settings_company_id();
        if ($companyId <= 0 || !function_exists('fetchCompanySettingsMap')) {
            return null;
        }
        $map = fetchCompanySettingsMap($pdo, $companyId);
        $fullKey = accounting_settings_key($key);
        if (!isset($map[$fullKey])) {
            return null;
        }
        $value = trim((string) $map[$fullKey]);

        return $value === '' ? null : $value;
    }
}

if (!function_exists('accounting_settings_set')) {
    function accounting_settings_set(PDO $pdo, string $key, string $value): bool
    {
        $companyId = accounting_settings_company_id();
        if ($companyId <= 0 || !function_exists('saveCompanySettingValue')) {
            return false;
        }

        return saveCompanySettingValue($pdo, $companyId, accounting_settings_key($key), $value);
    }
}

if (!function_exists('accounting_settings_is_revenue_gl_account')) {
    function accounting_settings_is_revenue_gl_account(PDO $pdo, int $accountId): bool
    {
        if ($accountId <= 0 || !function_exists('tableExists') || !tableExists('erp_accounts', $pdo)) {
            return false;
        }
        $st = $pdo->prepare("SELECT 1 FROM erp_accounts WHERE id = ? AND type = 'revenue' LIMIT 1");
        $st->execute([$accountId]);

        return (bool) $st->fetchColumn();
    }
}

if (!function_exists('accounting_settings_format_gl_account_label')) {
    function accounting_settings_format_gl_account_label(array $row): string
    {
        $code = trim((string) ($row['code'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        if ($code !== '' && $name !== '') {
            return $code . ' - ' . $name;
        }

        return $name !== '' ? $name : ($code !== '' ? $code : 'Account');
    }
}

if (!function_exists('accounting_settings_revenue_gl_options')) {
    /**
     * Revenue GL accounts for settings dropdowns.
     *
     * @return array<int, array{id:int, label:string, code:string, name:string}>
     */
    function accounting_settings_revenue_gl_options(PDO $pdo): array
    {
        if (!function_exists('tableExists') || !tableExists('erp_accounts', $pdo)) {
            return [];
        }

        $sql = "SELECT id, code, name FROM erp_accounts WHERE type = 'revenue' ORDER BY CAST(code AS UNSIGNED), code, name";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'label' => accounting_settings_format_gl_account_label($row),
            ];
        }

        return $out;
    }
}

if (!function_exists('accounting_settings_gl_id_from_financial_code')) {
    /** Resolve GL account id from a chart-of-accounts code (e.g. 4001). */
    function accounting_settings_gl_id_from_financial_code(PDO $pdo, string $code): ?int
    {
        if (!function_exists('coa_find_account_id_by_code') || !function_exists('fa_gl_link_financial_account')) {
            return null;
        }
        $faId = coa_find_account_id_by_code($pdo, $code);
        if ($faId === null || $faId <= 0) {
            return null;
        }

        $glId = fa_gl_link_financial_account($pdo, $faId);
        if ($glId && accounting_settings_is_revenue_gl_account($pdo, (int) $glId)) {
            return (int) $glId;
        }

        if (!function_exists('columnExists') || !columnExists('financial_accounts', 'gl_account_id', $pdo)) {
            return null;
        }
        $st = $pdo->prepare('SELECT gl_account_id FROM financial_accounts WHERE id = ? LIMIT 1');
        $st->execute([$faId]);
        $linked = (int) ($st->fetchColumn() ?: 0);

        return $linked > 0 && accounting_settings_is_revenue_gl_account($pdo, $linked) ? $linked : null;
    }
}

if (!function_exists('accounting_settings_discover_default_sales_revenue_gl_id')) {
    /**
     * Discover default sales revenue GL without reading company settings (bootstrap / fallback).
     */
    function accounting_settings_discover_default_sales_revenue_gl_id(PDO $pdo): ?int
    {
        if (!function_exists('tableExists') || !tableExists('erp_accounts', $pdo)) {
            return null;
        }
        if (!function_exists('invoice_gl_find_account_id')) {
            require_once __DIR__ . '/invoice_gl_posting.php';
        }

        foreach (['4001'] as $faCode) {
            $fromFa = accounting_settings_gl_id_from_financial_code($pdo, $faCode);
            if ($fromFa) {
                return $fromFa;
            }
        }

        $namePatterns = ['Sales Revenue', 'Product Sales', 'Sales', 'Revenue'];
        foreach ($namePatterns as $pattern) {
            $id = invoice_gl_find_account_id($pdo, 'revenue', [], [$pattern]);
            if ($id) {
                return $id;
            }
        }

        return null;
    }
}

if (!function_exists('accounting_get_default_sales_revenue_gl_account_id')) {
    /** Configured default sales revenue GL account (erp_accounts.id), or null if unset. */
    function accounting_get_default_sales_revenue_gl_account_id(PDO $pdo): ?int
    {
        $stored = accounting_settings_get($pdo, 'default_sales_revenue_gl_account_id');
        if ($stored !== null && (int) $stored > 0) {
            $id = (int) $stored;
            if (accounting_settings_is_revenue_gl_account($pdo, $id)) {
                return $id;
            }
        }

        return null;
    }
}

if (!function_exists('accounting_set_default_sales_revenue_gl_account_id')) {
    function accounting_set_default_sales_revenue_gl_account_id(PDO $pdo, int $erpAccountId): bool
    {
        if ($erpAccountId <= 0 || !accounting_settings_is_revenue_gl_account($pdo, $erpAccountId)) {
            return false;
        }

        return accounting_settings_set($pdo, 'default_sales_revenue_gl_account_id', (string) $erpAccountId);
    }
}

if (!function_exists('accounting_resolve_default_sales_revenue_gl_account_id')) {
    /**
     * GL revenue account for invoice / sales recognition posting.
     * Reads company setting first; falls back to chart discovery (no hardcoded posting target in callers).
     */
    function accounting_resolve_default_sales_revenue_gl_account_id(PDO $pdo): ?int
    {
        $configured = accounting_get_default_sales_revenue_gl_account_id($pdo);
        if ($configured) {
            return $configured;
        }

        return accounting_settings_discover_default_sales_revenue_gl_id($pdo);
    }
}

if (!function_exists('accounting_ensure_default_settings')) {
    /** Seed accounting settings from chart defaults when not yet configured. */
    function accounting_ensure_default_settings(PDO $pdo): void
    {
        if (accounting_get_default_sales_revenue_gl_account_id($pdo)) {
            return;
        }

        $discovered = accounting_settings_discover_default_sales_revenue_gl_id($pdo);
        if ($discovered) {
            accounting_set_default_sales_revenue_gl_account_id($pdo, $discovered);
        }
    }
}

if (!function_exists('accounting_gl_roles')) {
    /**
     * Posting roles configurable on Accounting Settings. Codes / name patterns are the
     * auto-detect fallback used when the company has not chosen an account.
     *
     * @return array<string, array{label:string, help:string, type:string, codes:list<string>, names:list<string>}>
     */
    function accounting_gl_roles(): array
    {
        return [
            'receivable' => [
                'label' => 'Accounts Receivable',
                'help' => 'Debited when an invoice is issued. Credited when the customer pays.',
                'type' => 'asset',
                'codes' => ['1200', '1010', '1100'],
                'names' => ['Receivable'],
                'system_key' => 'accounts_receivable',
                'match' => '/receivable|debtor/i',
            ],
            'output_tax' => [
                'label' => 'VAT / Output Tax Payable',
                'help' => 'Credited with the VAT on each invoice.',
                'type' => 'liability',
                'codes' => ['2200', '2020'],
                'names' => ['Tax'],
                'system_key' => 'vat_payable',
                'match' => '/\bvat\b|tax/i',
            ],
            'cogs' => [
                'label' => 'Cost of Goods Sold',
                'help' => 'Debited with the cost of stock sold.',
                'type' => 'expense',
                'codes' => ['6000', '5001'],
                'names' => ['Cost of Goods', 'COGS'],
                'system_key' => 'cogs',
                'match' => '/cost of (goods|sales|revenue)|\bcogs\b|direct cost|purchases|freight|landed cost|carriage in/i',
            ],
            'inventory' => [
                'label' => 'Inventory',
                'help' => 'Credited with the cost of stock sold.',
                'type' => 'asset',
                'codes' => ['1300'],
                'names' => ['Inventory'],
                'system_key' => 'inventory',
                'match' => '/inventor|stock|merchandise|goods (for|held)|warehouse/i',
            ],
            'payment_bank' => [
                'label' => 'Unassigned Receipts (Undeposited Funds)',
                'help' => 'Holds customer payments saved without a bank / cash account. Anything here needs checking: move it to the right bank once you know where the money went.',
                'type' => 'asset',
                'codes' => ['1005', '1002', '1001'],
                'names' => ['Undeposited', 'Bank', 'Cash'],
                'system_key' => 'undeposited_funds',
                'match' => '/undeposited|clearing|\bbank|cash|mobile money|m-?pesa|tigo|airtel|halo|till/i',
            ],
            'accounts_payable' => [
                'label' => 'Accounts Payable',
                'help' => 'Money owed to suppliers. Keep it separate from loans and other payables.',
                'type' => 'liability',
                'codes' => [],
                'names' => ['Accounts Payable', 'Trade Payable', 'Creditors'],
                'system_key' => 'accounts_payable',
                'match' => '/accounts payable|trade payable|creditor|supplier/i',
                'optional' => true,
                'default' => ['2100', 'Accounts Payable'],
            ],
            'opening_equity' => [
                'label' => 'Opening Balance Equity',
                'help' => 'Balances the opening balances of your bank, cash and other accounts.',
                'type' => 'equity',
                'codes' => ['3900'],
                'names' => ['Opening Balance'],
                'system_key' => 'opening_balance_equity',
                'match' => '/opening/i',
                'optional' => true,
                'default' => ['3900', 'Opening Balance Equity'],
            ],
            'retained_earnings' => [
                'label' => 'Retained Earnings',
                'help' => 'Receives each year\'s profit or loss when the year is closed.',
                'type' => 'equity',
                'codes' => ['3200'],
                'names' => ['Retained Earnings', 'Retained Profit'],
                'system_key' => 'retained_earnings',
                'match' => '/retained|accumulated (profit|earnings)/i',
                'optional' => true,
                'default' => ['3200', 'Retained Earnings'],
            ],
            'fx_gain_loss' => [
                'label' => 'Foreign Exchange Gain / Loss',
                'help' => 'Takes the difference when money moves between currencies.',
                'type' => 'expense',
                'codes' => ['5990'],
                'names' => ['Exchange'],
                'system_key' => 'fx_gain_loss',
                'match' => '/exchange|forex|\bfx\b|currency/i',
                'optional' => true,
                'default' => ['5990', 'Foreign Exchange Gain/Loss'],
            ],
            'suspense' => [
                'label' => 'Suspense',
                'help' => 'Holds transactions not yet sorted. Clear it to zero regularly.',
                'type' => 'asset',
                'codes' => ['1999'],
                'names' => ['Suspense'],
                'system_key' => 'suspense',
                'match' => '/suspense|awaiting|unclassified/i',
                'optional' => true,
                'default' => ['1999', 'Suspense - Items Awaiting Classification'],
            ],
            'transfer_clearing' => [
                'label' => 'Inter-Account Transfers Clearing',
                'help' => 'Holds money moving between your own bank and cash accounts.',
                'type' => 'asset',
                'codes' => ['1950'],
                'names' => ['Transfer'],
                'system_key' => 'transfer_clearing',
                'match' => '/transfer|clearing/i',
                'optional' => true,
                'default' => ['1950', 'Inter-Account Transfers Clearing'],
            ],
        ];
    }
}

if (!function_exists('accounting_gl_account_has_type')) {
    function accounting_gl_account_has_type(PDO $pdo, int $accountId, string $type): bool
    {
        if ($accountId <= 0 || !function_exists('tableExists') || !tableExists('erp_accounts', $pdo)) {
            return false;
        }
        $st = $pdo->prepare('SELECT 1 FROM erp_accounts WHERE id = ? AND type = ? LIMIT 1');
        $st->execute([$accountId, $type]);

        return (bool) $st->fetchColumn();
    }
}

if (!function_exists('accounting_settings_gl_options')) {
    /** @return array<int, array{id:int, label:string, code:string, name:string}> */
    function accounting_settings_gl_options(PDO $pdo, string $type): array
    {
        if (!function_exists('tableExists') || !tableExists('erp_accounts', $pdo)) {
            return [];
        }
        $st = $pdo->prepare('SELECT id, code, name FROM erp_accounts WHERE type = ? ORDER BY CAST(code AS UNSIGNED), code, name');
        $st->execute([$type]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $out[] = [
                    'id' => $id,
                    'code' => (string) ($row['code'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'label' => accounting_settings_format_gl_account_label($row),
                ];
            }
        }

        return $out;
    }
}

if (!function_exists('accounting_gl_role_options')) {
    /**
     * Accounts that make sense for a posting role. Filters out suspense, inactive and
     * non-postable accounts and accounts reserved for another role (system_key), then keeps
     * name / system_key matches. When nothing matches, every eligible account of the type is
     * returned with filtered=false so the setting can still be completed.
     *
     * @return array{options: array<int, array{id:int, label:string, code:string, name:string}>, filtered: bool}
     */
    function accounting_gl_role_options(PDO $pdo, string $role): array
    {
        $roles = accounting_gl_roles();
        if (!isset($roles[$role]) || !function_exists('tableExists') || !tableExists('erp_accounts', $pdo)) {
            return ['options' => [], 'filtered' => false];
        }
        $def = $roles[$role];
        $hasKey = function_exists('columnExists') && columnExists('erp_accounts', 'system_key', $pdo);
        $hasPostable = function_exists('columnExists') && columnExists('erp_accounts', 'is_postable', $pdo);
        $hasStatus = function_exists('columnExists') && columnExists('erp_accounts', 'status', $pdo);

        $sql = 'SELECT id, code, name' . ($hasKey ? ', system_key' : ", NULL AS system_key") . ' FROM erp_accounts WHERE type = ?';
        if ($hasPostable) {
            $sql .= ' AND is_postable = 1';
        }
        if ($hasStatus) {
            $sql .= " AND (status IS NULL OR status = '' OR status = 'active')";
        }
        $sql .= ' ORDER BY CAST(code AS UNSIGNED), code, name';
        $st = $pdo->prepare($sql);
        $st->execute([$def['type']]);

        $isSuspenseRole = $role === 'suspense';
        $otherKeys = [];
        foreach ($roles as $otherRole => $otherDef) {
            if ($otherRole !== $role && !empty($otherDef['system_key'])) {
                $otherKeys[] = $otherDef['system_key'];
            }
        }

        $eligible = [];
        $matched = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            $key = strtolower(trim((string) ($row['system_key'] ?? '')));
            $name = (string) ($row['name'] ?? '');
            if ($id <= 0 || ($key !== '' && in_array($key, $otherKeys, true)) || (!$isSuspenseRole && preg_match('/suspense/i', $name))) {
                continue;
            }
            $opt = [
                'id' => $id,
                'code' => (string) ($row['code'] ?? ''),
                'name' => $name,
                'label' => accounting_settings_format_gl_account_label($row),
            ];
            $eligible[] = $opt;
            if (($key !== '' && $key === ($def['system_key'] ?? '')) || (!empty($def['match']) && preg_match($def['match'], $name))) {
                $matched[] = $opt;
            }
        }

        return $matched !== []
            ? ['options' => $matched, 'filtered' => true]
            : ['options' => $eligible, 'filtered' => false];
    }
}

if (!function_exists('accounting_gl_role_allows')) {
    function accounting_gl_role_allows(PDO $pdo, string $role, int $accountId): bool
    {
        foreach (accounting_gl_role_options($pdo, $role)['options'] as $opt) {
            if ($opt['id'] === $accountId) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('accounting_get_gl_role_account_id')) {
    /** Account explicitly chosen for a posting role, or null when unset / no longer valid. */
    function accounting_get_gl_role_account_id(PDO $pdo, string $role): ?int
    {
        $roles = accounting_gl_roles();
        if (!isset($roles[$role])) {
            return null;
        }
        $stored = (int) (accounting_settings_get($pdo, 'gl_role.' . $role) ?? 0);

        return $stored > 0 && accounting_gl_account_has_type($pdo, $stored, $roles[$role]['type']) ? $stored : null;
    }
}

if (!function_exists('accounting_set_gl_role_account_id')) {
    function accounting_set_gl_role_account_id(PDO $pdo, string $role, int $accountId): bool
    {
        $roles = accounting_gl_roles();
        if (!isset($roles[$role]) || !accounting_gl_role_allows($pdo, $role, $accountId)) {
            return false;
        }

        return accounting_settings_set($pdo, 'gl_role.' . $role, (string) $accountId);
    }
}

if (!function_exists('accounting_clear_gl_role_account_id')) {
    /** Optional roles only: go back to the system default account. */
    function accounting_clear_gl_role_account_id(PDO $pdo, string $role): bool
    {
        $roles = accounting_gl_roles();
        if (empty($roles[$role]['optional'])) {
            return false;
        }

        return accounting_settings_set($pdo, 'gl_role.' . $role, '');
    }
}

if (!function_exists('accounting_role_for_gl_system_key')) {
    /** GL system account key (gl_system_account) => Accounting Settings role. */
    function accounting_role_for_gl_system_key(string $key): ?string
    {
        foreach (accounting_gl_roles() as $role => $def) {
            if (($def['system_key'] ?? '') === $key) {
                return $role;
            }
        }

        return null;
    }
}

if (!function_exists('accounting_resolve_gl_role_account_id')) {
    /**
     * Posting account for a role: company setting first, then the account tagged with the
     * role's system key, then chart auto-detection. Optional roles never fall back to an
     * unrelated account of the same type (null means "system default, created when needed").
     */
    function accounting_resolve_gl_role_account_id(PDO $pdo, string $role): ?int
    {
        $configured = accounting_get_gl_role_account_id($pdo, $role);
        if ($configured) {
            return $configured;
        }
        $roles = accounting_gl_roles();
        if (!isset($roles[$role])) {
            return null;
        }
        $def = $roles[$role];

        if (!empty($def['system_key']) && function_exists('columnExists') && columnExists('erp_accounts', 'system_key', $pdo)) {
            $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE system_key = ? AND type = ? LIMIT 1');
            $st->execute([$def['system_key'], $def['type']]);
            $id = (int) ($st->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        if (!empty($def['optional'])) {
            $list = accounting_gl_role_options($pdo, $role);
            if ($list['filtered']) {
                foreach ($def['codes'] as $code) {
                    foreach ($list['options'] as $opt) {
                        if ($opt['code'] === $code) {
                            return $opt['id'];
                        }
                    }
                }
                return $list['options'][0]['id'];
            }

            return null;
        }

        if (!function_exists('invoice_gl_find_account_id')) {
            require_once __DIR__ . '/invoice_gl_posting.php';
        }

        return invoice_gl_find_account_id($pdo, $def['type'], $def['codes'], $def['names']);
    }
}

if (!function_exists('accounting_default_sales_revenue_catalog_code')) {
    /** Suggested chart code for the default sales revenue sub-account (Balances COA seed only). */
    function accounting_default_sales_revenue_catalog_code(): string
    {
        return '4001';
    }
}
