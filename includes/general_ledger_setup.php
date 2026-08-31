<?php
/**
 * Safe general ledger bootstrap for tenant databases (no DROP TABLE).
 */

if (!function_exists('general_ledger_tables_ready')) {
    function general_ledger_tables_ready(PDO $pdo): bool
    {
        return function_exists('tableExists')
            && tableExists('erp_accounts', $pdo)
            && tableExists('erp_journal_entries', $pdo)
            && tableExists('erp_journal_items', $pdo);
    }
}

if (!function_exists('general_ledger_setup_needed')) {
    function general_ledger_setup_needed(PDO $pdo): bool
    {
        if (!general_ledger_tables_ready($pdo)) {
            return true;
        }
        try {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM erp_accounts')->fetchColumn();
            return $count === 0;
        } catch (Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('general_ledger_setup_url')) {
    function general_ledger_setup_url(): string
    {
        $path = 'modules/analytics/setup_general_ledger.php?module=analytics';
        if (function_exists('company_url')) {
            return company_url($path);
        }
        if (function_exists('app_url')) {
            return app_url('/' . ltrim($path, '/'));
        }

        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('general_ledger_chart_url')) {
    function general_ledger_chart_url(): string
    {
        if (function_exists('company_url')) {
            return company_url('accounting/chart-of-accounts.php');
        }
        if (function_exists('app_url')) {
            return app_url('/accounting/chart-of-accounts.php');
        }

        return '/accounting/chart-of-accounts.php';
    }
}

if (!function_exists('general_ledger_ensure_accounts_table')) {
    function general_ledger_ensure_accounts_table(PDO $pdo): void
    {
        if (!function_exists('tableExists') || tableExists('erp_accounts', $pdo)) {
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS erp_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NULL,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'expense',
            description TEXT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('general_ledger_ensure_journal_tables')) {
    function general_ledger_ensure_journal_tables(PDO $pdo): void
    {
        if (!function_exists('tableExists') || !tableExists('erp_journal_entries', $pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS erp_journal_entries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entry_number VARCHAR(50) NULL,
                date DATE NULL,
                description VARCHAR(255) NULL,
                reference VARCHAR(100) NULL,
                status VARCHAR(50) NULL DEFAULT 'posted',
                created_by INT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if (!function_exists('tableExists') || !tableExists('erp_journal_items', $pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS erp_journal_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NULL,
                account_id INT NULL,
                debit DECIMAL(15,2) NULL DEFAULT 0.00,
                credit DECIMAL(15,2) NULL DEFAULT 0.00,
                description VARCHAR(255) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_journal_id (journal_id),
                INDEX idx_account_id (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }
}

if (!function_exists('general_ledger_seed_default_accounts')) {
    /**
     * @return int Number of accounts inserted
     */
    function general_ledger_seed_default_accounts(PDO $pdo): int
    {
        $defaults = [
            ['1000', 'Cash', 'asset'],
            ['1200', 'Accounts Receivable', 'asset'],
            ['2100', 'Accounts Payable', 'liability'],
            ['3000', 'Retained Earnings', 'equity'],
            ['4000', 'Sales Revenue', 'revenue'],
            ['5000', 'Cost of Goods Sold', 'expense'],
            ['6000', 'Operating Expenses', 'expense'],
        ];

        $hasCode = function_exists('columnExists') && columnExists('erp_accounts', 'code', $pdo);
        $hasSystem = function_exists('columnExists') && columnExists('erp_accounts', 'is_system', $pdo);
        $inserted = 0;

        foreach ($defaults as [$code, $name, $type]) {
            if ($hasCode) {
                $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE code = ? LIMIT 1');
                $st->execute([$code]);
            } else {
                $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE LOWER(name) = LOWER(?) AND type = ? LIMIT 1');
                $st->execute([$name, $type]);
            }
            if ($st->fetchColumn()) {
                continue;
            }

            $fields = ['name', 'type'];
            $values = [$name, $type];
            if ($hasCode) {
                $fields[] = 'code';
                $values[] = $code;
            }
            if ($hasSystem) {
                $fields[] = 'is_system';
                $values[] = 1;
            }

            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare('INSERT INTO erp_accounts (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')')
                ->execute($values);
            $inserted++;
        }

        return $inserted;
    }
}

if (!function_exists('general_ledger_run_setup')) {
    /**
     * @return array{ok:bool,message:string,accounts_added:int}
     */
    function general_ledger_run_setup(PDO $pdo): array
    {
        try {
            general_ledger_ensure_accounts_table($pdo);
            general_ledger_ensure_journal_tables($pdo);
            $added = general_ledger_seed_default_accounts($pdo);

            if (function_exists('accounting_ensure_default_settings')) {
                require_once dirname(__DIR__) . '/accounting_settings.php';
                accounting_ensure_default_settings($pdo);
            }

            if (!general_ledger_tables_ready($pdo)) {
                return [
                    'ok' => false,
                    'message' => 'Ledger tables could not be created. Check database permissions.',
                    'accounts_added' => 0,
                ];
            }

            $msg = $added > 0
                ? "General ledger is ready. {$added} default account(s) were added."
                : 'General ledger is ready. Chart of accounts already had the required accounts.';

            return ['ok' => true, 'message' => $msg, 'accounts_added' => $added];
        } catch (Throwable $e) {
            error_log('general_ledger_run_setup: ' . $e->getMessage());

            return [
                'ok' => false,
                'message' => 'Setup failed: ' . $e->getMessage(),
                'accounts_added' => 0,
            ];
        }
    }
}
