<?php
/**
 * Double-entry general ledger (append-only).
 *
 * Rules enforced here:
 *  - every entry balances (total debits = total credits, in cents) and has at least two lines;
 *  - posted entries and lines are never updated or deleted (DB triggers where permitted);
 *    corrections are made by posting a reversing entry;
 *  - each entry carries a unique source_key, so re-posting the same source is a no-op;
 *  - entries dated inside a locked period are moved to the first open day (original date kept);
 *  - accounts flagged is_postable = 0 (headings) cannot receive postings.
 *
 * The chart of accounts is the existing erp_accounts table. Legacy erp_journal_* tables are untouched.
 */

if (!defined('GL_SCHEMA_VERSION')) {
    define('GL_SCHEMA_VERSION', 4);
}

if (!function_exists('gl_table_exists')) {
    function gl_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
            $st->execute([$table]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('gl_column_exists')) {
    function gl_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
            $st->execute([$table, $column]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('gl_meta_get')) {
    function gl_meta_get(PDO $pdo, string $key): ?string
    {
        try {
            $st = $pdo->prepare('SELECT meta_value FROM gl_meta WHERE meta_key = ? LIMIT 1');
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false ? null : (string) $v;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('gl_meta_set')) {
    function gl_meta_set(PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare('INSERT INTO gl_meta (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)')
            ->execute([$key, $value]);
    }
}

if (!function_exists('gl_ensure_schema')) {
    /**
     * Create ledger tables (additive only). Safe to call on every request; does work only once per version.
     */
    function gl_ensure_schema(PDO $pdo): bool
    {
        static $done = [];
        $oid = spl_object_id($pdo);
        if (!empty($done[$oid])) {
            return true;
        }

        if (gl_table_exists($pdo, 'gl_meta') && (int) (gl_meta_get($pdo, 'schema_version') ?? 0) >= GL_SCHEMA_VERSION) {
            $done[$oid] = true;
            return true;
        }

        if ($pdo->inTransaction()) {
            // DDL would implicitly commit the caller's transaction.
            return false;
        }

        try {
            if (!gl_table_exists($pdo, 'erp_accounts')) {
                require_once dirname(__DIR__) . '/general_ledger_setup.php';
                general_ledger_ensure_accounts_table($pdo);
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS gl_meta (
                meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
                meta_value TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS gl_journal_entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                entry_number VARCHAR(30) NOT NULL,
                entry_date DATE NOT NULL,
                original_date DATE NULL,
                description VARCHAR(500) NOT NULL,
                source_type VARCHAR(50) NOT NULL,
                source_key VARCHAR(191) NOT NULL,
                source_ref VARCHAR(120) NULL,
                entry_kind VARCHAR(20) NOT NULL DEFAULT 'system',
                reversal_of_id BIGINT UNSIGNED NULL,
                total_amount DECIMAL(18,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'TZS',
                company_id INT NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_gl_entry_number (entry_number),
                UNIQUE KEY uq_gl_source_key (source_key),
                KEY idx_gl_entry_date (entry_date),
                KEY idx_gl_source_type (source_type),
                KEY idx_gl_reversal_of (reversal_of_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS gl_journal_lines (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                entry_id BIGINT UNSIGNED NOT NULL,
                line_no SMALLINT UNSIGNED NOT NULL,
                account_id INT NOT NULL,
                financial_account_id INT NULL,
                debit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                credit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                memo VARCHAR(255) NULL,
                KEY idx_gl_line_entry (entry_id),
                KEY idx_gl_line_account (account_id),
                KEY idx_gl_line_fa (financial_account_id),
                CONSTRAINT fk_gl_line_entry FOREIGN KEY (entry_id) REFERENCES gl_journal_entries (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT chk_gl_line_amounts CHECK (debit >= 0 AND credit >= 0 AND ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS gl_source_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                source_table VARCHAR(40) NOT NULL,
                source_id BIGINT NOT NULL,
                entry_id BIGINT UNSIGNED NOT NULL,
                fingerprint CHAR(32) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_gl_link_source (source_table, source_id, active),
                KEY idx_gl_link_entry (entry_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS gl_period_locks (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                locked_through DATE NOT NULL,
                note VARCHAR(255) NULL,
                locked_by INT NULL,
                locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS gl_category_map (
                category VARCHAR(100) NOT NULL PRIMARY KEY,
                account_id INT NOT NULL,
                updated_by INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS gl_exchange_rates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                currency CHAR(3) NOT NULL,
                rate_date DATE NOT NULL,
                rate DECIMAL(18,8) NOT NULL,
                note VARCHAR(255) NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_gl_fx_currency_date (currency, rate_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Foreign-currency lines keep the original amount and the rate used; debit/credit are in the base currency.
            foreach ([
                'currency' => 'CHAR(3) NULL DEFAULT NULL',
                'fx_amount' => 'DECIMAL(18,2) NULL DEFAULT NULL',
                'fx_rate' => 'DECIMAL(18,8) NULL DEFAULT NULL',
            ] as $col => $def) {
                if (!gl_column_exists($pdo, 'gl_journal_lines', $col)) {
                    $pdo->exec("ALTER TABLE gl_journal_lines ADD COLUMN {$col} {$def}");
                }
            }

            // Journal the entry belongs to (e.g. SAL). Older entries stay NULL; reports derive theirs from source_type.
            if (!gl_column_exists($pdo, 'gl_journal_entries', 'journal_code')) {
                $pdo->exec('ALTER TABLE gl_journal_entries ADD COLUMN journal_code VARCHAR(8) NULL DEFAULT NULL, ADD KEY idx_gl_journal_code (journal_code)');
            }
            if (!gl_column_exists($pdo, 'erp_accounts', 'system_key')) {
                $pdo->exec('ALTER TABLE erp_accounts ADD COLUMN system_key VARCHAR(50) NULL DEFAULT NULL');
            }
            if (!gl_column_exists($pdo, 'erp_accounts', 'is_postable')) {
                $pdo->exec('ALTER TABLE erp_accounts ADD COLUMN is_postable TINYINT(1) NOT NULL DEFAULT 1');
            }

            try {
                $pdo->exec('ALTER TABLE gl_journal_lines ADD CONSTRAINT fk_gl_line_account FOREIGN KEY (account_id) REFERENCES erp_accounts (id) ON DELETE RESTRICT ON UPDATE RESTRICT');
            } catch (Throwable $e) {
                // Already present, or erp_accounts not InnoDB-compatible; posting validation still checks accounts.
            }

            gl_install_immutability_triggers($pdo);

            gl_meta_set($pdo, 'schema_version', (string) GL_SCHEMA_VERSION);
            $done[$oid] = true;
            return true;
        } catch (Throwable $e) {
            error_log('gl_ensure_schema: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('gl_install_immutability_triggers')) {
    function gl_install_immutability_triggers(PDO $pdo): void
    {
        $defs = [
            'trg_gl_entries_no_update' => 'BEFORE UPDATE ON gl_journal_entries',
            'trg_gl_entries_no_delete' => 'BEFORE DELETE ON gl_journal_entries',
            'trg_gl_lines_no_update' => 'BEFORE UPDATE ON gl_journal_lines',
            'trg_gl_lines_no_delete' => 'BEFORE DELETE ON gl_journal_lines',
        ];
        foreach ($defs as $name => $when) {
            try {
                $exists = $pdo->prepare('SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?');
                $exists->execute([$name]);
                if ($exists->fetchColumn()) {
                    continue;
                }
                $pdo->exec("CREATE TRIGGER {$name} {$when} FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted ledger records are immutable; post a reversing entry instead.'");
            } catch (Throwable $e) {
                error_log("gl_install_immutability_triggers {$name}: " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('gl_system_account_defs')) {
    /** @return array<string, array{0:string,1:string,2:string}> key => [code, name, type] */
    function gl_system_account_defs(): array
    {
        return [
            'accounts_receivable' => ['1200', 'Accounts Receivable', 'asset'],
            'inventory' => ['1300', 'Inventory / Stock Purchases', 'asset'],
            'transfer_clearing' => ['1950', 'Inter-Account Transfers Clearing', 'asset'],
            'suspense' => ['1999', 'Suspense - Items Awaiting Classification', 'asset'],
            'accounts_payable' => ['2100', 'Accounts Payable', 'liability'],
            'vat_payable' => ['2200', 'Tax Payable', 'liability'],
            'opening_balance_equity' => ['3900', 'Opening Balance Equity', 'equity'],
            'retained_earnings' => ['3200', 'Retained Earnings', 'equity'],
            'sales_revenue' => ['4002', 'Sales Revenue', 'revenue'],
            'fx_gain_loss' => ['5990', 'Foreign Exchange Gain/Loss', 'expense'],
        ];
    }
}

if (!function_exists('gl_base_currency')) {
    /** Currency the ledger (and every report) is kept in. */
    function gl_base_currency(PDO $pdo): string
    {
        static $cache = [];
        $oid = spl_object_id($pdo);
        if (!isset($cache[$oid])) {
            $cur = strtoupper(trim((string) (gl_meta_get($pdo, 'base_currency') ?? '')));
            $cache[$oid] = preg_match('/^[A-Z]{3}$/', $cur) ? $cur : 'TZS';
        }
        return $cache[$oid];
    }
}

if (!function_exists('gl_normalize_currency')) {
    function gl_normalize_currency($currency): string
    {
        $cur = strtoupper(trim((string) $currency));
        return preg_match('/^[A-Z]{3}$/', $cur) ? $cur : '';
    }
}

if (!function_exists('gl_exchange_rate')) {
    /**
     * Base-currency value of one unit of $currency, using the latest rate dated on or before $date.
     * Returns 1.0 for the base currency and null when no rate has been entered yet.
     */
    function gl_exchange_rate(PDO $pdo, string $currency, string $date): ?float
    {
        $currency = gl_normalize_currency($currency);
        if ($currency === '' || $currency === gl_base_currency($pdo)) {
            return 1.0;
        }
        static $cache = [];
        $ck = spl_object_id($pdo) . ':' . $currency . ':' . $date;
        if (isset($cache[$ck])) {
            return $cache[$ck];
        }
        try {
            $st = $pdo->prepare('SELECT rate FROM gl_exchange_rates WHERE currency = ? AND rate_date <= ? ORDER BY rate_date DESC LIMIT 1');
            $st->execute([$currency, $date]);
            $rate = $st->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
        if ($rate === false || (float) $rate <= 0) {
            return null;
        }
        return $cache[$ck] = (float) $rate;
    }
}

if (!function_exists('gl_latest_exchange_rates')) {
    /** @return array<string, array{rate:float, rate_date:string}> latest rate per currency */
    function gl_latest_exchange_rates(PDO $pdo): array
    {
        $out = [];
        try {
            $rows = $pdo->query('SELECT r.currency, r.rate, r.rate_date FROM gl_exchange_rates r
                                 JOIN (SELECT currency, MAX(rate_date) AS d FROM gl_exchange_rates GROUP BY currency) m
                                   ON m.currency = r.currency AND m.d = r.rate_date')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $out[(string) $r['currency']] = ['rate' => (float) $r['rate'], 'rate_date' => (string) $r['rate_date']];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}

if (!function_exists('gl_system_account')) {
    /**
     * Resolve (or create) a system GL account by stable key. Never falls back to an unrelated account.
     */
    function gl_system_account(PDO $pdo, string $key): int
    {
        static $cache = [];
        $ck = spl_object_id($pdo) . ':' . $key;
        if (isset($cache[$ck])) {
            return $cache[$ck];
        }

        $defs = gl_system_account_defs();
        if (!isset($defs[$key])) {
            throw new InvalidArgumentException('Unknown system account key: ' . $key);
        }
        [$code, $name, $type] = $defs[$key];

        if (!function_exists('accounting_get_gl_role_account_id')) {
            require_once dirname(__DIR__) . '/accounting_settings.php';
        }
        $configured = 0;
        if ($key === 'sales_revenue') {
            $configured = (int) (accounting_get_default_sales_revenue_gl_account_id($pdo) ?: 0);
        } elseif ($role = accounting_role_for_gl_system_key($key)) {
            $configured = (int) (accounting_get_gl_role_account_id($pdo, $role) ?: 0);
        }
        if ($configured > 0) {
            $cache[$ck] = $configured;
            return $configured;
        }

        $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE system_key = ? LIMIT 1');
        $st->execute([$key]);
        $id = (int) ($st->fetchColumn() ?: 0);

        if ($id <= 0 && $key === 'sales_revenue' && function_exists('accounting_resolve_default_sales_revenue_gl_account_id')) {
            $id = (int) (accounting_resolve_default_sales_revenue_gl_account_id($pdo) ?: 0);
        }
        if ($id <= 0) {
            // Only adopt an untagged account by code when its name also fits (e.g. 2100 "Loans Payable" is not Accounts Payable).
            $nameHints = [
                'accounts_receivable' => 'receivable', 'inventory' => 'inventor', 'transfer_clearing' => 'transfer',
                'suspense' => 'suspense', 'accounts_payable' => 'accounts payable', 'vat_payable' => 'tax',
                'opening_balance_equity' => 'opening', 'retained_earnings' => 'retained', 'sales_revenue' => 'revenue', 'fx_gain_loss' => 'exchange',
            ];
            $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE code = ? AND LOWER(type) = ? AND system_key IS NULL AND LOWER(name) LIKE ? ORDER BY id LIMIT 1');
            $st->execute([$code, $type, '%' . ($nameHints[$key] ?? strtolower($name)) . '%']);
            $id = (int) ($st->fetchColumn() ?: 0);
        }
        if ($id <= 0) {
            $st = $pdo->prepare('SELECT 1 FROM erp_accounts WHERE code = ? LIMIT 1');
            $st->execute([$code]);
            $useCode = $st->fetchColumn() ? $code . '9' : $code;
            $pdo->prepare("INSERT INTO erp_accounts (code, name, type, description, is_system, status, system_key, is_postable)
                           VALUES (?, ?, ?, ?, '1', 'active', ?, 1)")
                ->execute([$useCode, $name, $type, 'System account (general ledger)', $key]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE erp_accounts SET system_key = ? WHERE id = ? AND system_key IS NULL')->execute([$key, $id]);
        }

        $cache[$ck] = $id;
        return $id;
    }
}

if (!function_exists('gl_fiscal_year_start_month')) {
    /** Month (1-12) the financial year starts in; defaults to January. */
    function gl_fiscal_year_start_month(PDO $pdo): int
    {
        $m = (int) (gl_meta_get($pdo, 'fiscal_year_start_month') ?? 1);
        return ($m >= 1 && $m <= 12) ? $m : 1;
    }
}

if (!function_exists('gl_fiscal_year_bounds')) {
    /** @return array{0:string,1:string} first and last day of the financial year containing $date */
    function gl_fiscal_year_bounds(PDO $pdo, ?string $date = null): array
    {
        $ts = $date ? strtotime($date) : time();
        $ts = $ts ?: time();
        $m = gl_fiscal_year_start_month($pdo);
        $year = (int) date('Y', $ts) - ((int) date('n', $ts) < $m ? 1 : 0);
        $start = sprintf('%04d-%02d-01', $year, $m);
        $end = date('Y-m-d', strtotime($start . ' +1 year -1 day'));
        return [$start, $end];
    }
}

if (!function_exists('gl_locked_through')) {
    function gl_locked_through(PDO $pdo): ?string
    {
        try {
            $v = $pdo->query('SELECT MAX(locked_through) FROM gl_period_locks')->fetchColumn();
            return $v ? (string) $v : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('gl_lock_period_through')) {
    function gl_lock_period_through(PDO $pdo, string $date, string $note = ''): bool
    {
        $ts = strtotime($date);
        if (!$ts) {
            return false;
        }
        $current = gl_locked_through($pdo);
        if ($current !== null && $current >= date('Y-m-d', $ts)) {
            return false;
        }
        $pdo->prepare('INSERT INTO gl_period_locks (locked_through, note, locked_by) VALUES (?, ?, ?)')
            ->execute([date('Y-m-d', $ts), $note, $_SESSION['user_id'] ?? null]);
        return true;
    }
}

if (!function_exists('gl_to_cents')) {
    function gl_to_cents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}

if (!function_exists('gl_next_entry_number')) {
    function gl_next_entry_number(PDO $pdo, string $date): string
    {
        $year = substr($date, 0, 4);
        $st = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED)) FROM gl_journal_entries WHERE entry_number LIKE ?");
        $st->execute(['GL-' . $year . '-%']);
        $n = (int) ($st->fetchColumn() ?: 0) + 1;
        return sprintf('GL-%s-%06d', $year, $n);
    }
}

if (!function_exists('gl_entry_id_by_source_key')) {
    function gl_entry_id_by_source_key(PDO $pdo, string $sourceKey): int
    {
        $st = $pdo->prepare('SELECT id FROM gl_journal_entries WHERE source_key = ? LIMIT 1');
        $st->execute([$sourceKey]);
        return (int) ($st->fetchColumn() ?: 0);
    }
}

if (!function_exists('gl_post_entry')) {
    /**
     * Post a balanced journal entry.
     *
     * $entry: date, description, source_type, source_key, [source_ref, entry_kind, reversal_of_id, company_id]
     * $lines: list of [account_id, debit, credit, financial_account_id?, memo?]
     *
     * @return array{ok:bool, entry_id:int, existing:bool, error:?string}
     */
    function gl_post_entry(PDO $pdo, array $entry, array $lines): array
    {
        $fail = static fn (string $msg): array => ['ok' => false, 'entry_id' => 0, 'existing' => false, 'error' => $msg];

        $sourceKey = trim((string) ($entry['source_key'] ?? ''));
        $sourceType = trim((string) ($entry['source_type'] ?? ''));
        $description = trim((string) ($entry['description'] ?? ''));
        if ($sourceKey === '' || $sourceType === '' || $description === '') {
            return $fail('source_key, source_type and description are required.');
        }

        $existing = gl_entry_id_by_source_key($pdo, $sourceKey);
        if ($existing > 0) {
            return ['ok' => true, 'entry_id' => $existing, 'existing' => true, 'error' => null];
        }

        $ts = strtotime((string) ($entry['date'] ?? ''));
        if (!$ts) {
            return $fail('Invalid entry date.');
        }
        $date = date('Y-m-d', $ts);
        $rateDate = $date;
        $originalDate = null;
        $lockedThrough = gl_locked_through($pdo);
        if ($lockedThrough !== null && $date <= $lockedThrough) {
            $originalDate = $date;
            $date = date('Y-m-d', strtotime($lockedThrough . ' +1 day'));
        }

        // Line amounts are in the line's currency (default: base). Foreign lines are converted at the rate
        // dated on or before the entry date, unless an fx_rate is given (reversals reuse the original rate).
        $base = gl_base_currency($pdo);
        $clean = [];
        $totalDr = 0;
        $totalCr = 0;
        $accountIds = [];
        $currencyNet = [];
        foreach ($lines as $line) {
            $accountId = (int) ($line['account_id'] ?? 0);
            $dr = gl_to_cents($line['debit'] ?? 0);
            $cr = gl_to_cents($line['credit'] ?? 0);
            if ($dr < 0 || $cr < 0) {
                return $fail('Negative amounts are not allowed; swap debit/credit instead.');
            }
            if ($dr > 0 && $cr > 0) {
                $net = $dr - $cr;
                $dr = max(0, $net);
                $cr = max(0, -$net);
            }
            if ($dr === 0 && $cr === 0) {
                continue;
            }
            if ($accountId <= 0) {
                return $fail('Every line needs a GL account.');
            }

            $cur = gl_normalize_currency($line['currency'] ?? '');
            $cur = $cur === $base ? '' : $cur;
            $currencyNet[$cur === '' ? $base : $cur] = ($currencyNet[$cur === '' ? $base : $cur] ?? 0) + $dr - $cr;
            $fxRate = null;
            $fxCents = null;
            if ($cur !== '') {
                $fxRate = (isset($line['fx_rate']) && (float) $line['fx_rate'] > 0) ? (float) $line['fx_rate'] : gl_exchange_rate($pdo, $cur, $rateDate);
                if ($fxRate === null) {
                    return $fail("No exchange rate for {$cur} on or before {$rateDate}. Add one under Exchange Rates, then sync again.");
                }
                $fxCents = $dr > 0 ? $dr : $cr;
                $baseCents = (int) round($fxCents * $fxRate);
                if ($baseCents === 0) {
                    continue;
                }
                if ($dr > 0) {
                    $dr = $baseCents;
                } else {
                    $cr = $baseCents;
                }
            }

            $accountIds[$accountId] = true;
            $totalDr += $dr;
            $totalCr += $cr;
            $clean[] = [
                'account_id' => $accountId,
                'financial_account_id' => !empty($line['financial_account_id']) ? (int) $line['financial_account_id'] : null,
                'debit' => $dr,
                'credit' => $cr,
                'memo' => isset($line['memo']) ? mb_substr((string) $line['memo'], 0, 255) : null,
                'currency' => $cur !== '' ? $cur : null,
                'fx_amount' => $fxCents,
                'fx_rate' => $fxRate,
            ];
        }

        if (count($clean) < 2) {
            return $fail('A journal entry needs at least two non-zero lines.');
        }
        $hasForeign = count(array_diff(array_keys($currencyNet), [$base])) > 0;
        if (count($currencyNet) === 1 && reset($currencyNet) !== 0) {
            return $fail(sprintf('Entry is not balanced in %s (debits minus credits %.2f).', (string) key($currencyNet), reset($currencyNet) / 100));
        }
        if ($totalDr !== $totalCr && $hasForeign) {
            // Rounding on conversion, or a real gain/loss when one entry mixes currencies (e.g. a USD to TZS transfer).
            $diff = $totalDr - $totalCr;
            $fxAcc = gl_system_account($pdo, 'fx_gain_loss');
            $accountIds[$fxAcc] = true;
            $clean[] = [
                'account_id' => $fxAcc, 'financial_account_id' => null,
                'debit' => $diff < 0 ? -$diff : 0, 'credit' => $diff > 0 ? $diff : 0,
                'memo' => $diff > 0 ? 'Exchange gain' : 'Exchange loss',
                'currency' => null, 'fx_amount' => null, 'fx_rate' => null,
            ];
            $totalDr = $totalCr = max($totalDr, $totalCr);
        }
        if ($totalDr !== $totalCr) {
            return $fail(sprintf('Entry is not balanced (debits %.2f, credits %.2f).', $totalDr / 100, $totalCr / 100));
        }
        $foreignOnly = array_values(array_diff(array_keys($currencyNet), [$base]));
        $entryCurrency = (count($currencyNet) === 1 && count($foreignOnly) === 1) ? $foreignOnly[0] : $base;

        $ids = array_keys($accountIds);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, is_postable FROM erp_accounts WHERE id IN ($in)");
        $st->execute($ids);
        $found = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $found[(int) $row['id']] = (int) $row['is_postable'];
        }
        foreach ($ids as $id) {
            if (!isset($found[$id])) {
                return $fail("GL account #{$id} does not exist.");
            }
            if ($found[$id] === 0) {
                return $fail("GL account #{$id} is a heading and cannot receive postings.");
            }
        }

        $journalCode = null;
        try {
            if (!function_exists('accounting_journal_code_for_source') && is_file(dirname(__DIR__) . '/accounting_journals.php')) {
                require_once dirname(__DIR__) . '/accounting_settings.php';
                require_once dirname(__DIR__) . '/accounting_journals.php';
            }
            if (!empty($entry['journal_code'])) {
                $journalCode = strtoupper(mb_substr((string) $entry['journal_code'], 0, 8));
            } elseif (function_exists('accounting_journal_code_for_source')) {
                $journalCode = accounting_journal_code_for_source($pdo, $sourceType, !empty($entry['reversal_of_id']) ? (int) $entry['reversal_of_id'] : null);
            }
        } catch (Throwable $e) {
            error_log('gl_post_entry journal lookup: ' . $e->getMessage());
        }

        $own = !$pdo->inTransaction();
        try {
            if ($own) {
                $pdo->beginTransaction();
            }

            $entryId = 0;
            for ($attempt = 0; $attempt < 5 && $entryId === 0; $attempt++) {
                try {
                    $pdo->prepare('INSERT INTO gl_journal_entries
                        (entry_number, entry_date, original_date, description, source_type, source_key, source_ref,
                         entry_kind, reversal_of_id, total_amount, currency, company_id, created_by, journal_code)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([
                            gl_next_entry_number($pdo, $date),
                            $date,
                            $originalDate,
                            mb_substr($description, 0, 500),
                            $sourceType,
                            $sourceKey,
                            isset($entry['source_ref']) ? mb_substr((string) $entry['source_ref'], 0, 120) : null,
                            (string) ($entry['entry_kind'] ?? 'system'),
                            !empty($entry['reversal_of_id']) ? (int) $entry['reversal_of_id'] : null,
                            number_format($totalDr / 100, 2, '.', ''),
                            $entryCurrency,
                            !empty($entry['company_id']) ? (int) $entry['company_id'] : null,
                            $entry['created_by'] ?? ($_SESSION['user_id'] ?? null),
                            $journalCode,
                        ]);
                    $entryId = (int) $pdo->lastInsertId();
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'uq_gl_source_key') !== false) {
                        if ($own) {
                            $pdo->rollBack();
                        }
                        return ['ok' => true, 'entry_id' => gl_entry_id_by_source_key($pdo, $sourceKey), 'existing' => true, 'error' => null];
                    }
                    if (strpos($e->getMessage(), 'uq_gl_entry_number') === false) {
                        throw $e;
                    }
                }
            }
            if ($entryId === 0) {
                throw new RuntimeException('Could not allocate an entry number.');
            }

            $ins = $pdo->prepare('INSERT INTO gl_journal_lines (entry_id, line_no, account_id, financial_account_id, debit, credit, memo, currency, fx_amount, fx_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($clean as $i => $l) {
                $ins->execute([
                    $entryId,
                    $i + 1,
                    $l['account_id'],
                    $l['financial_account_id'],
                    number_format($l['debit'] / 100, 2, '.', ''),
                    number_format($l['credit'] / 100, 2, '.', ''),
                    $l['memo'],
                    $l['currency'],
                    $l['fx_amount'] !== null ? number_format($l['fx_amount'] / 100, 2, '.', '') : null,
                    $l['fx_rate'] !== null ? number_format($l['fx_rate'], 8, '.', '') : null,
                ]);
            }

            if ($own) {
                $pdo->commit();
            }
            return ['ok' => true, 'entry_id' => $entryId, 'existing' => false, 'error' => null];
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('gl_post_entry: ' . $e->getMessage());
            return $fail($e->getMessage());
        }
    }
}

if (!function_exists('gl_entry_is_reversed')) {
    function gl_entry_is_reversed(PDO $pdo, int $entryId): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM gl_journal_entries WHERE reversal_of_id = ? LIMIT 1');
        $st->execute([$entryId]);
        return (bool) $st->fetchColumn();
    }
}

if (!function_exists('gl_reverse_entry')) {
    /**
     * Post the exact mirror of an entry. The original stays on the books.
     *
     * @return array{ok:bool, entry_id:int, existing:bool, error:?string}
     */
    function gl_reverse_entry(PDO $pdo, int $entryId, string $reason, ?string $date = null): array
    {
        $st = $pdo->prepare('SELECT * FROM gl_journal_entries WHERE id = ? LIMIT 1');
        $st->execute([$entryId]);
        $orig = $st->fetch(PDO::FETCH_ASSOC);
        if (!$orig) {
            return ['ok' => false, 'entry_id' => 0, 'existing' => false, 'error' => 'Entry not found.'];
        }
        if ((string) $orig['entry_kind'] === 'reversal') {
            return ['ok' => false, 'entry_id' => 0, 'existing' => false, 'error' => 'A reversal cannot itself be reversed; post a new entry instead.'];
        }

        $st = $pdo->prepare('SELECT account_id, financial_account_id, debit, credit, memo, currency, fx_amount, fx_rate FROM gl_journal_lines WHERE entry_id = ? ORDER BY line_no');
        $st->execute([$entryId]);
        $lines = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $line = [
                'account_id' => (int) $l['account_id'],
                'financial_account_id' => $l['financial_account_id'] !== null ? (int) $l['financial_account_id'] : null,
                'debit' => $l['credit'],
                'credit' => $l['debit'],
                'memo' => $l['memo'],
            ];
            if (!empty($l['currency']) && $l['fx_amount'] !== null && (float) $l['fx_rate'] > 0) {
                $isDebit = (float) $l['debit'] > 0;
                $line['currency'] = $l['currency'];
                $line['fx_rate'] = (float) $l['fx_rate'];
                $line['debit'] = $isDebit ? 0 : $l['fx_amount'];
                $line['credit'] = $isDebit ? $l['fx_amount'] : 0;
            }
            $lines[] = $line;
        }

        return gl_post_entry($pdo, [
            'date' => $date ?: date('Y-m-d'),
            'description' => 'Reversal of ' . $orig['entry_number'] . ': ' . $reason,
            'source_type' => 'reversal',
            'source_key' => 'reversal:' . $entryId,
            'source_ref' => $orig['entry_number'],
            'entry_kind' => 'reversal',
            'reversal_of_id' => $entryId,
        ], $lines);
    }
}

if (!function_exists('gl_trial_balance')) {
    /**
     * @return list<array{account_id:int, code:string, name:string, type:string, debit:float, credit:float}>
     */
    function gl_trial_balance(PDO $pdo, ?string $from = null, ?string $to = null): array
    {
        $where = [];
        $params = [];
        if ($from) {
            $where[] = 'e.entry_date >= ?';
            $params[] = $from;
        }
        if ($to) {
            $where[] = 'e.entry_date <= ?';
            $params[] = $to;
        }
        $sql = 'SELECT a.id AS account_id, COALESCE(a.code, \'\') AS code, a.name, LOWER(a.type) AS type,
                       SUM(l.debit) AS debit, SUM(l.credit) AS credit
                FROM gl_journal_lines l
                JOIN gl_journal_entries e ON e.id = l.entry_id
                JOIN erp_accounts a ON a.id = l.account_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' GROUP BY a.id, a.code, a.name, a.type
                ORDER BY a.code + 0, a.code, a.name';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'account_id' => (int) $r['account_id'],
                'code' => (string) $r['code'],
                'name' => (string) $r['name'],
                'type' => (string) $r['type'],
                'debit' => (float) $r['debit'],
                'credit' => (float) $r['credit'],
            ];
        }
        return $rows;
    }
}

if (!function_exists('gl_financial_account_net')) {
    /**
     * Net (debit - credit) per financial account sub-ledger, in each line's own currency
     * (so it compares directly with the account balance). Pass $inBase = true for base-currency values.
     *
     * @return array<int, float>
     */
    function gl_financial_account_net(PDO $pdo, bool $inBase = false): array
    {
        $out = [];
        $amount = $inBase || !gl_column_exists($pdo, 'gl_journal_lines', 'fx_amount')
            ? 'debit - credit'
            : 'CASE WHEN currency IS NOT NULL AND fx_amount IS NOT NULL THEN IF(debit > 0, fx_amount, -fx_amount) ELSE debit - credit END';
        $st = $pdo->query("SELECT financial_account_id, SUM({$amount}) AS net FROM gl_journal_lines WHERE financial_account_id IS NOT NULL GROUP BY financial_account_id");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['financial_account_id']] = round((float) $r['net'], 2);
        }
        return $out;
    }
}
