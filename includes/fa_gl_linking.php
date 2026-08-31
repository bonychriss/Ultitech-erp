<?php
/**
 * Link financial_accounts (Balances / Chart of Accounts) to erp_accounts (General Ledger).
 */

if (!function_exists('fa_gl_strip_code')) {
    function fa_gl_strip_code(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^\s*[0-9]{3,10}\s*-\s*(.+)$/', $name, $m)) {
            return trim($m[1]);
        }

        return $name;
    }
}

if (!function_exists('fa_gl_extract_code')) {
    function fa_gl_extract_code(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^\s*([0-9]{3,10})\s*-\s*/', $name, $m)) {
            return trim($m[1]);
        }

        return '';
    }
}

if (!function_exists('fa_gl_normalize_key')) {
    function fa_gl_normalize_key(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}

if (!function_exists('fa_gl_tables_ready')) {
    function fa_gl_tables_ready(PDO $pdo): bool
    {
        return function_exists('tableExists')
            && tableExists('financial_accounts', $pdo)
            && tableExists('erp_accounts', $pdo);
    }
}

if (!function_exists('fa_gl_has_gl_link_column')) {
    function fa_gl_has_gl_link_column(PDO $pdo): bool
    {
        return function_exists('columnExists') && columnExists('financial_accounts', 'gl_account_id', $pdo);
    }
}

if (!function_exists('fa_gl_ensure_gl_link_column')) {
    function fa_gl_ensure_gl_link_column(PDO $pdo): void
    {
        if (fa_gl_has_gl_link_column($pdo) || !fa_gl_tables_ready($pdo)) {
            return;
        }

        $after = '';
        if (function_exists('columnExists')) {
            if (columnExists('financial_accounts', 'parent_id', $pdo)) {
                $after = ' AFTER parent_id';
            } elseif (columnExists('financial_accounts', 'type', $pdo)) {
                $after = ' AFTER type';
            }
        }

        try {
            $pdo->exec(
                'ALTER TABLE financial_accounts ADD COLUMN gl_account_id INT NULL DEFAULT NULL' . $after
            );
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE financial_accounts ADD COLUMN gl_account_id INT NULL DEFAULT NULL');
            } catch (Throwable $e2) {
                error_log('fa_gl_ensure_gl_link_column: ' . $e2->getMessage());
            }
        }
    }
}

if (!function_exists('fa_gl_fa_select_columns')) {
    function fa_gl_fa_select_columns(PDO $pdo): string
    {
        fa_gl_ensure_gl_link_column($pdo);
        $cols = 'id, name, type, parent_id';
        if (fa_gl_has_gl_link_column($pdo)) {
            $cols .= ', gl_account_id';
        }

        return $cols;
    }
}

if (!function_exists('fa_gl_journal_status_sql')) {
    function fa_gl_journal_status_sql(string $jeAlias = 'je'): string
    {
        $alias = preg_replace('/[^a-z0-9_]/i', '', $jeAlias) ?: 'je';

        return " AND LOWER(COALESCE({$alias}.status, 'posted')) NOT IN ('draft', 'cancelled', 'rejected', 'void') ";
    }
}

if (!function_exists('fa_gl_find_erp_account')) {
    /**
     * @param list<string> $codes
     * @param list<string> $namePatterns partial names
     */
    function fa_gl_find_erp_account(PDO $pdo, string $type, array $codes = [], array $namePatterns = []): ?int
    {
        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE type = ? AND code = ? LIMIT 1');
            $st->execute([$type, $code]);
            $id = (int) ($st->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        foreach ($namePatterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            $st = $pdo->prepare(
                'SELECT id FROM erp_accounts WHERE type = ? AND LOWER(name) LIKE ? ORDER BY code ASC LIMIT 1'
            );
            $st->execute([$type, '%' . strtolower($pattern) . '%']);
            $id = (int) ($st->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }
}

if (!function_exists('fa_gl_next_code_for_type')) {
    function fa_gl_next_code_for_type(PDO $pdo, string $type, string $prefix = '1'): string
    {
        $prefix = preg_replace('/[^0-9]/', '', $prefix) ?: '1';
        $st = $pdo->prepare(
            'SELECT code FROM erp_accounts WHERE type = ? AND code LIKE ? ORDER BY code DESC LIMIT 1'
        );
        $st->execute([$type, $prefix . '%']);
        $last = trim((string) ($st->fetchColumn() ?: ''));
        if ($last !== '' && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $last, $m)) {
            return $prefix . str_pad((string) ((int) $m[1] + 1), strlen($m[1]), '0', STR_PAD_LEFT);
        }

        return $prefix . '001';
    }
}

if (!function_exists('fa_gl_create_erp_account')) {
    function fa_gl_create_erp_account(
        PDO $pdo,
        string $code,
        string $name,
        string $type,
        ?string $description = null
    ): int {
        $code = trim($code);
        $name = trim($name);
        if ($code === '' || $name === '') {
            return 0;
        }

        $existing = fa_gl_find_erp_account($pdo, $type, [$code], [$name]);
        if ($existing) {
            return $existing;
        }

        $cols = $pdo->query('SHOW COLUMNS FROM erp_accounts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $fields = ['code', 'name', 'type'];
        $values = [$code, $name, $type];
        if (in_array('description', $cols, true)) {
            $fields[] = 'description';
            $values[] = $description ?: ('Linked from Chart of Accounts: ' . $name);
        }
        if (in_array('is_system', $cols, true)) {
            $fields[] = 'is_system';
            $values[] = '0';
        }
        if (in_array('status', $cols, true)) {
            $fields[] = 'status';
            $values[] = 'active';
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $pdo->prepare('INSERT INTO erp_accounts (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')')
            ->execute($values);

        return (int) $pdo->lastInsertId();
    }
}

if (!function_exists('fa_gl_infer_erp_account')) {
    /** Resolve or create the erp_accounts row for a financial_accounts row. */
    function fa_gl_infer_erp_account(PDO $pdo, array $fa): ?int
    {
        if (!fa_gl_tables_ready($pdo)) {
            return null;
        }

        $display = fa_gl_strip_code((string) ($fa['name'] ?? ''));
        $faCode = fa_gl_extract_code((string) ($fa['name'] ?? ''));
        $type = strtolower(trim((string) ($fa['type'] ?? 'asset')));
        $key = fa_gl_normalize_key($display);

        if ($key === '') {
            return null;
        }

        if (preg_match('/receivable|debtor|a\/r|\bar\b/', $key)) {
            $id = fa_gl_find_erp_account($pdo, 'asset', ['1200'], ['Accounts Receivable', 'Receivable']);
            return $id ?: fa_gl_create_erp_account($pdo, '1200', 'Accounts Receivable', 'asset');
        }

        if (preg_match('/undeposit/', $key)) {
            $id = fa_gl_find_erp_account($pdo, 'asset', ['1006', '1005'], ['Undeposited Funds', 'Undeposited']);
            return $id ?: fa_gl_create_erp_account(
                $pdo,
                $faCode !== '' ? $faCode : '1006',
                $display !== '' ? $display : 'Undeposited Funds',
                'asset'
            );
        }

        if (preg_match('/payable|creditor|a\/p|\bap\b/', $key)) {
            $id = fa_gl_find_erp_account($pdo, 'liability', ['2000'], ['Accounts Payable', 'Payable']);
            return $id ?: fa_gl_create_erp_account($pdo, '2000', 'Accounts Payable (A/P)', 'liability');
        }

        if (preg_match('/inventory|stock/', $key)) {
            $id = fa_gl_find_erp_account($pdo, 'asset', ['1300'], ['Inventory']);
            return $id ?: fa_gl_create_erp_account($pdo, '1300', 'Inventory Asset', 'asset');
        }

        if (in_array($type, ['bank', 'cash', 'mobile'], true)) {
            if (preg_match('/\bcash\b/', $key) && !preg_match('/bank|crdb|uba|nmb|equity|mobile/', $key)) {
                $id = fa_gl_find_erp_account($pdo, 'asset', ['1001'], ['Cash']);
                return $id ?: fa_gl_create_erp_account($pdo, '1001', 'Cash', 'asset');
            }

            if (preg_match('/crdb/', $key)) {
                $id = fa_gl_find_erp_account($pdo, 'asset', ['1002', '1008'], ['CRDB', 'Bank']);
                if ($id) {
                    return $id;
                }
            }

            if (preg_match('/uba/', $key)) {
                $id = fa_gl_find_erp_account($pdo, 'asset', ['1003'], ['UBA']);
                if ($id) {
                    return $id;
                }
                return fa_gl_create_erp_account($pdo, '1003', $display !== '' ? $display : 'UBA Bank', 'asset');
            }

            $id = fa_gl_find_erp_account($pdo, 'asset', [], [$display, 'Bank']);
            if ($id) {
                return $id;
            }

            $newCode = $faCode !== '' ? $faCode : fa_gl_next_code_for_type($pdo, 'asset', '100');
            $existingByCode = fa_gl_find_erp_account($pdo, 'asset', [$newCode], []);
            if ($existingByCode) {
                return $existingByCode;
            }

            return fa_gl_create_erp_account($pdo, $newCode, $display, 'asset');
        }

        $glType = $type;
        if ($glType === 'revenue') {
            $id = fa_gl_find_erp_account($pdo, 'revenue', [], [$display]);
            if ($id) {
                return $id;
            }

            $newCode = $faCode !== '' ? $faCode : fa_gl_next_code_for_type($pdo, 'revenue', '400');
            $existingByCode = fa_gl_find_erp_account($pdo, 'revenue', [$newCode], []);
            if ($existingByCode) {
                $st = $pdo->prepare('SELECT name FROM erp_accounts WHERE id = ? LIMIT 1');
                $st->execute([$existingByCode]);
                $existingName = fa_gl_normalize_key(fa_gl_strip_code((string) ($st->fetchColumn() ?: '')));
                if ($existingName === $key || str_contains($existingName, $key) || str_contains($key, $existingName)) {
                    return $existingByCode;
                }
                $newCode = fa_gl_next_code_for_type($pdo, 'revenue', '400');
            }

            return fa_gl_create_erp_account($pdo, $newCode, $display, 'revenue');
        }
        if ($glType === 'expense') {
            $id = fa_gl_find_erp_account($pdo, 'expense', [$faCode], [$display]);
            return $id ?: fa_gl_create_erp_account(
                $pdo,
                $faCode !== '' ? $faCode : fa_gl_next_code_for_type($pdo, 'expense', '500'),
                $display,
                'expense'
            );
        }
        if ($glType === 'liability') {
            $id = fa_gl_find_erp_account($pdo, 'liability', [$faCode], [$display]);
            return $id ?: fa_gl_create_erp_account(
                $pdo,
                $faCode !== '' ? $faCode : fa_gl_next_code_for_type($pdo, 'liability', '200'),
                $display,
                'liability'
            );
        }
        if ($glType === 'equity') {
            $id = fa_gl_find_erp_account($pdo, 'equity', [$faCode], [$display]);
            return $id ?: fa_gl_create_erp_account(
                $pdo,
                $faCode !== '' ? $faCode : fa_gl_next_code_for_type($pdo, 'equity', '300'),
                $display,
                'equity'
            );
        }

        if ($type === 'asset') {
            if (preg_match('/crdb/', $key)) {
                $id = fa_gl_find_erp_account($pdo, 'asset', ['1002', '1008'], ['CRDB', 'Bank']);
                if ($id) {
                    return $id;
                }
            }

            if (preg_match('/uba/', $key)) {
                $id = fa_gl_find_erp_account($pdo, 'asset', ['1003'], ['UBA']);
                if ($id) {
                    return $id;
                }
                return fa_gl_create_erp_account($pdo, '1003', $display !== '' ? $display : 'UBA Bank', 'asset');
            }

            if (preg_match('/\bcash\b/', $key) && !preg_match('/bank|crdb|uba|mobile/', $key)) {
                $id = fa_gl_find_erp_account($pdo, 'asset', ['1001'], ['Cash']);
                if ($id) {
                    return $id;
                }
            }

            $id = fa_gl_find_erp_account($pdo, 'asset', [], [$display]);
            if ($id) {
                return $id;
            }

            $newCode = fa_gl_next_code_for_type($pdo, 'asset', '101');
            return fa_gl_create_erp_account($pdo, $newCode, $display, 'asset');
        }

        return null;
    }
}

if (!function_exists('fa_gl_link_is_valid')) {
    function fa_gl_link_is_valid(PDO $pdo, array $fa, int $glAccountId): bool
    {
        if ($glAccountId <= 0) {
            return false;
        }

        $st = $pdo->prepare('SELECT id, type, name FROM erp_accounts WHERE id = ? LIMIT 1');
        $st->execute([$glAccountId]);
        $gl = $st->fetch(PDO::FETCH_ASSOC);
        if (!$gl) {
            return false;
        }

        $faType = strtolower(trim((string) ($fa['type'] ?? '')));
        $glType = strtolower(trim((string) ($gl['type'] ?? '')));
        $display = fa_gl_normalize_key(fa_gl_strip_code((string) ($fa['name'] ?? '')));
        $glName = fa_gl_normalize_key((string) ($gl['name'] ?? ''));

        $typeMap = [
            'bank' => 'asset',
            'cash' => 'asset',
            'mobile' => 'asset',
            'income' => 'revenue',
            'sales' => 'revenue',
        ];
        $expectedGlType = $typeMap[$faType] ?? $faType;
        if ($expectedGlType !== $glType && !($expectedGlType === 'asset' && $glType === 'asset')) {
            return false;
        }

        if (in_array($faType, ['bank', 'cash', 'mobile'], true)) {
            if (preg_match('/\bcash\b/', $display) && !preg_match('/bank|crdb|uba|mobile/', $display)) {
                return str_contains($glName, 'cash');
            }
            if (preg_match('/uba/', $display)) {
                return str_contains($glName, 'uba') || str_contains($glName, 'bank');
            }
            if (preg_match('/crdb/', $display)) {
                return str_contains($glName, 'crdb') || str_contains($glName, 'bank');
            }
        }

        return true;
    }
}

if (!function_exists('fa_gl_persist_link')) {
    function fa_gl_persist_link(PDO $pdo, int $financialAccountId, int $glAccountId): void
    {
        if ($financialAccountId <= 0 || $glAccountId <= 0 || !fa_gl_has_gl_link_column($pdo)) {
            return;
        }

        try {
            $pdo->prepare(
                'UPDATE financial_accounts SET gl_account_id = ? WHERE id = ?'
            )->execute([$glAccountId, $financialAccountId]);
        } catch (Throwable $e) {
            error_log('fa_gl_persist_link: ' . $e->getMessage());
        }
    }
}

if (!function_exists('fa_gl_should_link_account')) {
    function fa_gl_should_link_account(PDO $pdo, array $fa): bool
    {
        $faId = (int) ($fa['id'] ?? 0);
        if ($faId <= 0) {
            return false;
        }

        $type = strtolower(trim((string) ($fa['type'] ?? '')));
        if (in_array($type, ['bank', 'cash', 'mobile'], true)) {
            return true;
        }

        $parentId = (int) ($fa['parent_id'] ?? 0);
        if ($parentId > 0) {
            return true;
        }

        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM financial_accounts WHERE parent_id = ?');
            $st->execute([$faId]);
            if ((int) $st->fetchColumn() > 0) {
                return false;
            }
        } catch (Throwable $e) {
            return true;
        }

        return true;
    }
}

if (!function_exists('fa_gl_link_financial_account')) {
    /**
     * Map a financial_accounts row to erp_accounts.id and persist gl_account_id.
     */
    function fa_gl_link_financial_account(PDO $pdo, int $financialAccountId, array $options = []): ?int
    {
        $force = !empty($options['force']);
        if ($financialAccountId <= 0 || !fa_gl_tables_ready($pdo)) {
            return null;
        }

        fa_gl_ensure_gl_link_column($pdo);

        $st = $pdo->prepare(
            'SELECT ' . fa_gl_fa_select_columns($pdo) . ' FROM financial_accounts WHERE id = ? LIMIT 1'
        );
        $st->execute([$financialAccountId]);
        $fa = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fa) {
            return null;
        }

        if (!fa_gl_should_link_account($pdo, $fa)) {
            return null;
        }

        $linked = (int) ($fa['gl_account_id'] ?? 0);
        if (!$force && $linked > 0 && fa_gl_link_is_valid($pdo, $fa, $linked)) {
            return $linked;
        }

        $glId = fa_gl_infer_erp_account($pdo, $fa);
        if ($glId && fa_gl_has_gl_link_column($pdo)) {
            fa_gl_persist_link($pdo, $financialAccountId, $glId);
        }

        return $glId ?: null;
    }
}

if (!function_exists('fa_gl_sync_all')) {
    /**
     * @return array{linked:int, skipped:int, errors:list<string>}
     */
    function fa_gl_sync_all(PDO $pdo, array $options = []): array
    {
        $stats = ['linked' => 0, 'skipped' => 0, 'errors' => []];
        if (!fa_gl_tables_ready($pdo)) {
            $stats['errors'][] = 'Required tables are missing.';

            return $stats;
        }

        $force = !empty($options['force']);
        fa_gl_ensure_gl_link_column($pdo);
        $rows = $pdo->query(
            'SELECT ' . fa_gl_fa_select_columns($pdo) . ' FROM financial_accounts WHERE status = \'active\' OR status IS NULL ORDER BY parent_id IS NULL DESC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $fa) {
            $faId = (int) ($fa['id'] ?? 0);
            if ($faId <= 0) {
                continue;
            }

            if (!fa_gl_should_link_account($pdo, $fa)) {
                if (fa_gl_has_gl_link_column($pdo) && (int) ($fa['gl_account_id'] ?? 0) > 0) {
                    try {
                        $pdo->prepare('UPDATE financial_accounts SET gl_account_id = NULL WHERE id = ?')->execute([$faId]);
                    } catch (Throwable $e) {
                    }
                }
                $stats['skipped']++;
                continue;
            }

            try {
                $before = (int) ($fa['gl_account_id'] ?? 0);
                $glId = fa_gl_link_financial_account($pdo, $faId, ['force' => $force]);
                if ($glId) {
                    if ($force || $before !== $glId) {
                        $stats['linked']++;
                    } else {
                        $stats['skipped']++;
                    }
                } else {
                    $stats['skipped']++;
                }
            } catch (Throwable $e) {
                $stats['errors'][] = 'Account #' . $faId . ': ' . $e->getMessage();
            }
        }

        return $stats;
    }
}

if (!function_exists('fa_gl_balance_as_of')) {
    function fa_gl_balance_as_of(PDO $pdo, int $glAccountId, string $asOf, bool $creditNormal = false): float
    {
        if ($glAccountId <= 0 || !tableExists('erp_journal_items', $pdo)) {
            return 0.0;
        }

        $sql = 'SELECT COALESCE(SUM(ji.debit), 0) AS d, COALESCE(SUM(ji.credit), 0) AS c
                FROM erp_journal_items ji
                INNER JOIN erp_journal_entries je ON je.id = ji.journal_id AND je.date <= ?
                WHERE ji.account_id = ?';
        $params = [$asOf, $glAccountId];
        if (function_exists('analytics_append_company_scope')) {
            analytics_append_company_scope($sql, $params, 'erp_journal_entries', 'je', $pdo);
        }
        $sql .= fa_gl_journal_status_sql('je');

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['d' => 0, 'c' => 0];
        $debit = (float) ($row['d'] ?? 0);
        $credit = (float) ($row['c'] ?? 0);

        return $creditNormal ? ($credit - $debit) : ($debit - $credit);
    }
}

if (!function_exists('fa_gl_fetch_erp_asset_balances')) {
    /**
     * @return array<int, array{id:int, label:string, amount:float}>
     */
    function fa_gl_fetch_erp_asset_balances(PDO $pdo, string $asOf): array
    {
        if (!tableExists('erp_journal_items', $pdo)) {
            return [];
        }

        $sql = "SELECT a.id, a.name, p.name AS parent_name,
                       COALESCE(SUM(ji.debit), 0) AS d,
                       COALESCE(SUM(ji.credit), 0) AS c
                FROM erp_accounts a
                LEFT JOIN erp_accounts p ON p.id = a.parent_id
                INNER JOIN erp_journal_items ji ON a.id = ji.account_id
                INNER JOIN erp_journal_entries je ON ji.journal_id = je.id AND je.date <= ?
                WHERE a.type = 'asset'";
        $params = [$asOf];
        if (function_exists('analytics_append_company_scope')) {
            analytics_append_company_scope($sql, $params, 'erp_accounts', 'a', $pdo);
            analytics_append_company_scope($sql, $params, 'erp_journal_entries', 'je', $pdo);
        }
        $sql .= fa_gl_journal_status_sql('je');
        $sql .= ' GROUP BY a.id, a.name, p.name';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $debit = (float) ($r['d'] ?? 0);
            $credit = (float) ($r['c'] ?? 0);
            $bal = $debit - $credit;
            if (abs($bal) < 0.00001) {
                continue;
            }
            $label = (string) ($r['name'] ?? '');
            if (!empty($r['parent_name'])) {
                $label = trim((string) $r['parent_name']) . fa_gl_coa_label_sep() . $label;
            }
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'label' => $label,
                'amount' => $bal,
            ];
        }

        return $out;
    }
}

if (!function_exists('fa_gl_pick_canonical_row')) {
    /**
     * When several financial_accounts share one GL account, pick the best row for display.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, true> $parentIdsWithChildren
     */
    function fa_gl_pick_canonical_row(array $rows, array $parentIdsWithChildren): array
    {
        if ($rows === []) {
            return [];
        }
        if (count($rows) === 1) {
            return $rows[0];
        }

        usort($rows, static function (array $a, array $b) use ($parentIdsWithChildren): int {
            $aParent = (int) ($a['parent_id'] ?? 0);
            $bParent = (int) ($b['parent_id'] ?? 0);
            $aChild = $aParent > 0 && isset($parentIdsWithChildren[$aParent]) ? 1 : 0;
            $bChild = $bParent > 0 && isset($parentIdsWithChildren[$bParent]) ? 1 : 0;
            if ($aChild !== $bChild) {
                return $bChild <=> $aChild;
            }

            $aBal = abs((float) ($a['balance'] ?? 0));
            $bBal = abs((float) ($b['balance'] ?? 0));
            if (abs($aBal - $bBal) > 0.00001) {
                return $bBal <=> $aBal;
            }

            return ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        });

        return $rows[0];
    }
}

if (!function_exists('fa_gl_linked_erp_ids')) {
    /** @return array<int, true> */
    function fa_gl_linked_erp_ids(PDO $pdo): array
    {
        $linked = [];
        if (!fa_gl_has_gl_link_column($pdo)) {
            return $linked;
        }

        foreach ($pdo->query('SELECT gl_account_id FROM financial_accounts WHERE gl_account_id IS NOT NULL AND gl_account_id > 0') as $r) {
            $linked[(int) $r['gl_account_id']] = true;
        }

        return $linked;
    }
}

if (!function_exists('fa_gl_build_asset_map')) {
    /**
     * Build balance-sheet asset lines from linked COA accounts + unlinked GL balances.
     * Avoids double-counting when multiple FA rows share one GL account.
     *
     * @param array<int, array<string, mixed>> $faRows
     * @return array<string, float>
     */
    function fa_gl_build_asset_map(PDO $pdo, string $asOf, array $faRows, array $parentIdsWithChildren): array
    {
        $assets = [];
        $claimedGl = [];
        $grouped = [];

        foreach ($faRows as $row) {
            if (!function_exists('smart_report_finance_fa_bs_type')) {
                continue;
            }
            if (smart_report_finance_fa_bs_type((string) ($row['type'] ?? '')) !== 'asset') {
                continue;
            }

            $faId = (int) ($row['id'] ?? 0);
            $glId = (int) ($row['gl_account_id'] ?? 0);

            if ($glId > 0) {
                $grouped[$glId][] = $row;
                continue;
            }

            $parentId = (int) ($row['parent_id'] ?? 0);
            $isCategoryChild = $parentId > 0 && isset($parentIdsWithChildren[$parentId]);
            $balance = (float) ($row['balance'] ?? 0);
            if (!$isCategoryChild && abs($balance) < 0.00001) {
                continue;
            }

            if (!function_exists('smart_report_finance_fa_account_label')) {
                continue;
            }
            $label = smart_report_finance_fa_account_label($row);
            $assets[$label] = ($assets[$label] ?? 0) + $balance;
        }

        foreach ($grouped as $glId => $rows) {
            $canonical = fa_gl_pick_canonical_row($rows, $parentIdsWithChildren);
            if ($canonical === []) {
                continue;
            }
            $label = smart_report_finance_fa_account_label($canonical);
            $glBalance = fa_gl_balance_as_of($pdo, (int) $glId, $asOf, false);
            $cashBalance = (float) ($canonical['balance'] ?? 0);
            $amount = abs($glBalance) >= 0.00001 ? $glBalance : $cashBalance;
            $assets[$label] = ($assets[$label] ?? 0) + $amount;
            $claimedGl[(int) $glId] = true;
        }

        foreach (fa_gl_fetch_erp_asset_balances($pdo, $asOf) as $glId => $entry) {
            if (isset($claimedGl[$glId])) {
                continue;
            }
            $label = (string) ($entry['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $amount = (float) ($entry['amount'] ?? 0);
            $coaParent = fa_gl_coa_category_parent_label($faRows, $parentIdsWithChildren);
            $mapLabel = $coaParent !== ''
                ? fa_gl_coa_map_label($coaParent, $label)
                : ($label . ' (GL)');
            $assets[$mapLabel] = ($assets[$mapLabel] ?? 0) + $amount;
        }

        return $assets;
    }
}

if (!function_exists('fa_gl_coa_label_sep')) {
    function fa_gl_coa_label_sep(): string
    {
        return ' -> ';
    }
}

if (!function_exists('fa_gl_coa_child_label')) {
    function fa_gl_coa_child_label(string $label): string
    {
        $label = preg_replace('/\s*\(GL\)\s*$/', '', trim($label));
        if (preg_match('/\s*[\x{00BB}\x{203A}\x{003E}\x{2013}\x{2014}\-]\s*/u', $label)) {
            $parts = preg_split('/\s*[\x{00BB}\x{203A}\x{003E}\x{2013}\x{2014}\-]\s*/u', $label) ?: [];
            $label = trim((string) end($parts));
        }
        if (function_exists('smart_report_finance_fa_display_name')) {
            $label = smart_report_finance_fa_display_name($label);
        }

        return $label;
    }
}

if (!function_exists('fa_gl_coa_map_label')) {
    function fa_gl_coa_map_label(string $parent, string $child): string
    {
        return trim($parent) . fa_gl_coa_label_sep() . fa_gl_coa_child_label($child);
    }
}

if (!function_exists('fa_gl_coa_category_parent_label')) {
    /**
     * @param array<int, array<string, mixed>> $faRows
     * @param array<int, true> $parentIdsWithChildren
     */
    function fa_gl_coa_category_parent_label(array $faRows, array $parentIdsWithChildren): string
    {
        foreach ($faRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || !isset($parentIdsWithChildren[$id])) {
                continue;
            }
            if (function_exists('smart_report_finance_fa_display_name')) {
                return smart_report_finance_fa_display_name((string) ($row['name'] ?? ''));
            }

            return fa_gl_strip_code((string) ($row['name'] ?? ''));
        }

        return 'Assets';
    }
}
