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

if (!function_exists('accounting_default_sales_revenue_catalog_code')) {
    /** Suggested chart code for the default sales revenue sub-account (Balances COA seed only). */
    function accounting_default_sales_revenue_catalog_code(): string
    {
        return '4001';
    }
}
