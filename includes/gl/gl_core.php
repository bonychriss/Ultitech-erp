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
    define('GL_SCHEMA_VERSION', 2);
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
            'sales_revenue' => ['4002', 'Sales Revenue', 'revenue'],
        ];
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

        $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE system_key = ? LIMIT 1');
        $st->execute([$key]);
        $id = (int) ($st->fetchColumn() ?: 0);

        if ($id <= 0 && $key === 'sales_revenue' && function_exists('accounting_resolve_default_sales_revenue_gl_account_id')) {
            $id = (int) (accounting_resolve_default_sales_revenue_gl_account_id($pdo) ?: 0);
        }
        if ($id <= 0) {
            $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE code = ? AND LOWER(type) = ? AND system_key IS NULL ORDER BY id LIMIT 1');
            $st->execute([$code, $type]);
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
        $originalDate = null;
        $lockedThrough = gl_locked_through($pdo);
        if ($lockedThrough !== null && $date <= $lockedThrough) {
            $originalDate = $date;
            $date = date('Y-m-d', strtotime($lockedThrough . ' +1 day'));
        }

        $clean = [];
        $totalDr = 0;
        $totalCr = 0;
        $accountIds = [];
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
            $accountIds[$accountId] = true;
            $totalDr += $dr;
            $totalCr += $cr;
            $clean[] = [
                'account_id' => $accountId,
                'financial_account_id' => !empty($line['financial_account_id']) ? (int) $line['financial_account_id'] : null,
                'debit' => $dr,
                'credit' => $cr,
                'memo' => isset($line['memo']) ? mb_substr((string) $line['memo'], 0, 255) : null,
            ];
        }

        if (count($clean) < 2) {
            return $fail('A journal entry needs at least two non-zero lines.');
        }
        if ($totalDr !== $totalCr) {
            return $fail(sprintf('Entry is not balanced (debits %.2f, credits %.2f).', $totalDr / 100, $totalCr / 100));
        }

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
                         entry_kind, reversal_of_id, total_amount, currency, company_id, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
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
                            (string) ($entry['currency'] ?? 'TZS'),
                            !empty($entry['company_id']) ? (int) $entry['company_id'] : null,
                            $entry['created_by'] ?? ($_SESSION['user_id'] ?? null),
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

            $ins = $pdo->prepare('INSERT INTO gl_journal_lines (entry_id, line_no, account_id, financial_account_id, debit, credit, memo) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($clean as $i => $l) {
                $ins->execute([
                    $entryId,
                    $i + 1,
                    $l['account_id'],
                    $l['financial_account_id'],
                    number_format($l['debit'] / 100, 2, '.', ''),
                    number_format($l['credit'] / 100, 2, '.', ''),
                    $l['memo'],
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

        $st = $pdo->prepare('SELECT account_id, financial_account_id, debit, credit, memo FROM gl_journal_lines WHERE entry_id = ? ORDER BY line_no');
        $st->execute([$entryId]);
        $lines = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $lines[] = [
                'account_id' => (int) $l['account_id'],
                'financial_account_id' => $l['financial_account_id'] !== null ? (int) $l['financial_account_id'] : null,
                'debit' => $l['credit'],
                'credit' => $l['debit'],
                'memo' => $l['memo'],
            ];
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
    /** Net (debit - credit) per financial account sub-ledger. @return array<int, float> */
    function gl_financial_account_net(PDO $pdo): array
    {
        $out = [];
        $st = $pdo->query('SELECT financial_account_id, SUM(debit) - SUM(credit) AS net FROM gl_journal_lines WHERE financial_account_id IS NOT NULL GROUP BY financial_account_id');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['financial_account_id']] = round((float) $r['net'], 2);
        }
        return $out;
    }
}
