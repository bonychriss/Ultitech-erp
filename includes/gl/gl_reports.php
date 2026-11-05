<?php
/**
 * Read-only integrity checks comparing the double-entry ledger with operational records.
 */

require_once __DIR__ . '/gl_bridge.php';

if (!function_exists('gl_report_source')) {
    /** 'ledger' (double-entry ledger) or 'legacy' (old erp_journal_* tables) for the financial reports. */
    function gl_report_source(PDO $pdo): string
    {
        if (!gl_ensure_schema($pdo)) {
            return 'legacy';
        }
        return gl_meta_get($pdo, 'report_source') === 'ledger' ? 'ledger' : 'legacy';
    }
}

if (!function_exists('gl_normal_type')) {
    function gl_normal_type(string $type): string
    {
        $t = strtolower(trim($type));
        $map = ['income' => 'revenue', 'sales' => 'revenue', 'other income' => 'revenue', 'cogs' => 'expense', 'cost of sales' => 'expense',
                'liabilities' => 'liability', 'assets' => 'asset', 'expenses' => 'expense'];
        return $map[$t] ?? $t;
    }
}

if (!function_exists('gl_account_movements')) {
    /**
     * Per account: balance before $from, debits and credits within [$from, $to], closing balance at $to.
     * Pass $from = null for "everything up to $to".
     *
     * @return list<array<string, mixed>>
     */
    function gl_account_movements(PDO $pdo, ?string $from, string $to): array
    {
        $from = $from ?: '0001-01-01';
        $st = $pdo->prepare("SELECT a.id, COALESCE(a.code, '') AS code, a.name, a.type,
                                    SUM(IF(e.entry_date < ?, l.debit - l.credit, 0)) AS opening,
                                    SUM(IF(e.entry_date BETWEEN ? AND ?, l.debit, 0)) AS debit,
                                    SUM(IF(e.entry_date BETWEEN ? AND ?, l.credit, 0)) AS credit
                             FROM gl_journal_lines l
                             JOIN gl_journal_entries e ON e.id = l.entry_id
                             JOIN erp_accounts a ON a.id = l.account_id
                             WHERE e.entry_date <= ?
                             GROUP BY a.id, a.code, a.name, a.type
                             ORDER BY a.code + 0, a.code, a.name");
        $st->execute([$from, $from, $to, $from, $to, $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $opening = round((float) $r['opening'], 2);
            $dr = round((float) $r['debit'], 2);
            $cr = round((float) $r['credit'], 2);
            if (abs($opening) < 0.005 && $dr < 0.005 && $cr < 0.005) {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'],
                'code' => (string) $r['code'],
                'name' => (string) $r['name'],
                'type' => gl_normal_type((string) $r['type']),
                'opening' => $opening,
                'debit' => $dr,
                'credit' => $cr,
                'closing' => round($opening + $dr - $cr, 2),
            ];
        }
        return $out;
    }
}

if (!function_exists('gl_profit_and_loss')) {
    /** @return array{revenue:list<array>, expense:list<array>, total_revenue:float, total_expense:float, net:float} */
    function gl_profit_and_loss(PDO $pdo, string $from, string $to): array
    {
        $rev = [];
        $exp = [];
        foreach (gl_account_movements($pdo, $from, $to) as $a) {
            $period = round($a['debit'] - $a['credit'], 2);
            if (abs($period) < 0.005) {
                continue;
            }
            if ($a['type'] === 'revenue') {
                $rev[] = $a + ['amount' => -$period];
            } elseif ($a['type'] === 'expense') {
                $exp[] = $a + ['amount' => $period];
            }
        }
        $tr = round(array_sum(array_column($rev, 'amount')), 2);
        $te = round(array_sum(array_column($exp, 'amount')), 2);
        return ['revenue' => $rev, 'expense' => $exp, 'total_revenue' => $tr, 'total_expense' => $te, 'net' => round($tr - $te, 2)];
    }
}

if (!function_exists('gl_balance_sheet')) {
    /**
     * Balance sheet as at a date. Profit not yet closed to retained earnings is shown as current earnings.
     *
     * @return array<string, mixed>
     */
    function gl_balance_sheet(PDO $pdo, string $asOf): array
    {
        $groups = ['asset' => [], 'liability' => [], 'equity' => []];
        $earnings = 0.0;
        foreach (gl_account_movements($pdo, null, $asOf) as $a) {
            $bal = $a['closing'];
            if (abs($bal) < 0.005) {
                continue;
            }
            if ($a['type'] === 'asset') {
                $groups['asset'][] = $a + ['amount' => $bal];
            } elseif ($a['type'] === 'liability' || $a['type'] === 'equity') {
                $groups[$a['type']][] = $a + ['amount' => -$bal];
            } elseif ($a['type'] === 'revenue' || $a['type'] === 'expense') {
                $earnings -= $bal;
            }
        }
        $ta = round(array_sum(array_column($groups['asset'], 'amount')), 2);
        $tl = round(array_sum(array_column($groups['liability'], 'amount')), 2);
        $teAcc = round(array_sum(array_column($groups['equity'], 'amount')), 2);
        $earnings = round($earnings, 2);
        $te = round($teAcc + $earnings, 2);
        return [
            'assets' => $groups['asset'],
            'liabilities' => $groups['liability'],
            'equity' => $groups['equity'],
            'current_earnings' => $earnings,
            'total_assets' => $ta,
            'total_liabilities' => $tl,
            'total_equity' => $te,
            'difference' => round($ta - $tl - $te, 2),
        ];
    }
}

if (!function_exists('gl_account_statement')) {
    /** @return array{opening:float, lines:list<array>, closing:float} */
    function gl_account_statement(PDO $pdo, int $accountId, string $from, string $to, ?int $financialAccountId = null): array
    {
        $fa = $financialAccountId ? ' AND l.financial_account_id = ' . (int) $financialAccountId : '';
        $st = $pdo->prepare("SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM gl_journal_lines l JOIN gl_journal_entries e ON e.id = l.entry_id
                             WHERE l.account_id = ? AND e.entry_date < ?{$fa}");
        $st->execute([$accountId, $from]);
        $opening = round((float) $st->fetchColumn(), 2);

        $st = $pdo->prepare("SELECT e.id AS entry_id, e.entry_number, e.entry_date, e.description, e.source_ref, e.entry_kind,
                                    l.memo, l.debit, l.credit, l.financial_account_id
                             FROM gl_journal_lines l JOIN gl_journal_entries e ON e.id = l.entry_id
                             WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ?{$fa}
                             ORDER BY e.entry_date, e.id, l.line_no");
        $st->execute([$accountId, $from, $to]);
        $running = $opening;
        $lines = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $running = round($running + (float) $r['debit'] - (float) $r['credit'], 2);
            $r['running'] = $running;
            $lines[] = $r;
        }
        return ['opening' => $opening, 'lines' => $lines, 'closing' => $running];
    }
}

if (!function_exists('gl_money_financial_account_ids')) {
    /** Financial accounts that hold money (bank, cash, mobile money, and asset accounts that are not receivables/stock). */
    function gl_money_financial_account_ids(PDO $pdo): array
    {
        $exclude = [gl_system_account($pdo, 'accounts_receivable'), gl_system_account($pdo, 'inventory')];
        $ids = [];
        foreach (gl_bridge_fa_rows($pdo) as $id => $fa) {
            $type = strtolower((string) $fa['type']);
            if (in_array($type, ['bank', 'cash', 'mobile'], true)) {
                $ids[] = $id;
                continue;
            }
            if ($fa['_nature'] === 'asset' && !preg_match('/receivable|undeposit|inventory|stock/i', (string) $fa['name'])
                && !in_array((int) ($fa['gl_account_id'] ?? 0), $exclude, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}

if (!function_exists('gl_cash_flow')) {
    /**
     * Direct-method cash flow: every movement on money accounts, grouped by what caused it.
     *
     * @return array<string, mixed>
     */
    function gl_cash_flow(PDO $pdo, string $from, string $to): array
    {
        $ids = gl_money_financial_account_ids($pdo);
        $empty = ['opening' => 0.0, 'closing' => 0.0, 'sections' => [], 'net' => 0.0, 'accounts' => []];
        if (!$ids) {
            return $empty;
        }
        $in = implode(',', array_map('intval', $ids));

        $opening = (float) $pdo->query("SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM gl_journal_lines l JOIN gl_journal_entries e ON e.id = l.entry_id
                                        WHERE l.financial_account_id IN ($in) AND e.entry_date < " . $pdo->quote($from))->fetchColumn();

        $labels = [
            'customers' => ['operating', 'Receipts from customers'],
            'expenses' => ['operating', 'Payments for expenses and services'],
            'stock' => ['operating', 'Payments for stock purchases'],
            'other_operating' => ['operating', 'Other operating movements'],
            'opening' => ['financing', 'Opening balances brought in'],
            'transfers' => ['internal', 'Transfers between own accounts (net)'],
        ];
        // Reversals are classified like the entry they reverse, so a corrected payment nets out in its own line.
        $st = $pdo->prepare("SELECT COALESCE(o.source_type, e.source_type) AS source_type, COALESCE(o.entry_kind, e.entry_kind) AS entry_kind,
                                    SUM(l.debit - l.credit) AS net
                             FROM gl_journal_lines l
                             JOIN gl_journal_entries e ON e.id = l.entry_id
                             LEFT JOIN gl_journal_entries o ON o.id = e.reversal_of_id
                             WHERE l.financial_account_id IN ($in) AND e.entry_date BETWEEN ? AND ?
                             GROUP BY e.id, o.source_type, e.source_type, o.entry_kind, e.entry_kind");
        $st->execute([$from, $to]);
        $buckets = array_fill_keys(array_keys($labels), ['in' => 0.0, 'out' => 0.0]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $type = (string) $r['source_type'];
            $kind = (string) $r['entry_kind'];
            $net = (float) $r['net'];
            if ($kind === 'opening') {
                $b = 'opening';
            } elseif (in_array($type, ['invoice_payment', 'revenue_collection', 'revenue_entry', 'revenue_payment', 'sales_payment'], true)) {
                $b = 'customers';
            } elseif (in_array($type, ['purchase_order', 'supplier_payment'], true)) {
                $b = 'stock';
            } elseif ($type === 'payment_voucher' || $type === 'expense') {
                $b = 'expenses';
            } elseif (strpos($type, 'transfer') !== false || strpos($type, 'petty_cash') !== false) {
                $b = 'transfers';
            } else {
                $b = 'other_operating';
            }
            if ($net >= 0) {
                $buckets[$b]['in'] += $net;
            } else {
                $buckets[$b]['out'] += $net;
            }
        }

        $sections = [];
        $total = 0.0;
        foreach ($buckets as $key => $v) {
            $net = round($v['in'] + $v['out'], 2);
            if (abs($v['in']) < 0.005 && abs($v['out']) < 0.005) {
                continue;
            }
            [$section, $label] = $labels[$key];
            $sections[$section][] = ['label' => $label, 'in' => round($v['in'], 2), 'out' => round($v['out'], 2), 'net' => $net];
            $total += $net;
        }

        $closing = (float) $pdo->query("SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM gl_journal_lines l JOIN gl_journal_entries e ON e.id = l.entry_id
                                        WHERE l.financial_account_id IN ($in) AND e.entry_date <= " . $pdo->quote($to))->fetchColumn();

        $accounts = [];
        $rows = gl_bridge_fa_rows($pdo);
        $st = $pdo->prepare("SELECT l.financial_account_id AS id,
                                    SUM(IF(e.entry_date < ?, l.debit - l.credit, 0)) AS opening,
                                    SUM(l.debit - l.credit) AS closing
                             FROM gl_journal_lines l JOIN gl_journal_entries e ON e.id = l.entry_id
                             WHERE l.financial_account_id IN ($in) AND e.entry_date <= ?
                             GROUP BY l.financial_account_id");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $accounts[] = ['name' => (string) ($rows[(int) $r['id']]['name'] ?? '#' . $r['id']), 'opening' => round((float) $r['opening'], 2), 'closing' => round((float) $r['closing'], 2)];
        }

        return ['opening' => round($opening, 2), 'closing' => round($closing, 2), 'sections' => $sections, 'net' => round($total, 2), 'accounts' => $accounts];
    }
}

if (!function_exists('gl_report_money_accounts')) {
    /**
     * Per financial account: stored balance, balance computed from its transactions, and ledger balance
     * (shown in the same sign convention as the Balances module).
     *
     * @return list<array<string, mixed>>
     */
    function gl_report_money_accounts(PDO $pdo): array
    {
        $computed = [];
        $txCount = [];
        $mislabelled = [];
        $supplierPaymentSql = gl_column_exists($pdo, 'account_transactions', 'supplier_payment_id') ? ' OR supplier_payment_id IS NOT NULL' : '';
        foreach ($pdo->query("SELECT account_id,
                                     SUM(IF(type = 'credit', amount, -amount)) AS net,
                                     COUNT(*) AS n,
                                     SUM(IF(type = 'credit' AND (reference_type IN ('purchase_order','supplier_payment'){$supplierPaymentSql}), amount, 0)) AS mislabelled
                              FROM account_transactions GROUP BY account_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $computed[(int) $r['account_id']] = (float) $r['net'];
            $txCount[(int) $r['account_id']] = (int) $r['n'];
            $mislabelled[(int) $r['account_id']] = (float) $r['mislabelled'];
        }
        $ledger = gl_financial_account_net($pdo);

        $out = [];
        foreach (gl_bridge_fa_rows($pdo, true) as $id => $fa) {
            $stored = round((float) ($fa['current_balance'] ?? 0), 2);
            $calc = round((float) ($fa['opening_balance'] ?? 0) + ($computed[$id] ?? 0), 2);
            $gl = $ledger[$id] ?? 0.0;
            $glApp = round($fa['_nature'] === 'asset' ? $gl : -$gl, 2);
            if (abs($stored) < 0.005 && abs($calc) < 0.005 && abs($glApp) < 0.005 && empty($txCount[$id])) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string) $fa['name'],
                'type' => (string) $fa['type'],
                'currency' => gl_normalize_currency($fa['currency'] ?? '') ?: gl_base_currency($pdo),
                'nature' => $fa['_nature'],
                'status' => (string) ($fa['status'] ?? ''),
                'is_heading' => !empty($fa['_has_children']),
                'tx_count' => $txCount[$id] ?? 0,
                'stored' => $stored,
                'computed' => $calc,
                'ledger' => $glApp,
                'mislabelled_outflows' => round($mislabelled[$id] ?? 0, 2),
                'stored_gap' => round($stored - $glApp, 2),
            ];
        }
        return $out;
    }
}

if (!function_exists('gl_report_suspense_lines')) {
    /** Net suspense items (reversed pairs excluded). @return list<array<string, mixed>> */
    function gl_report_suspense_lines(PDO $pdo): array
    {
        $suspense = gl_system_account($pdo, 'suspense');
        $st = $pdo->prepare("SELECT e.id, e.entry_number, e.entry_date, e.source_ref, e.source_type, l.memo, l.debit, l.credit,
                                    (SELECT s.source_id FROM gl_source_links s WHERE s.source_table = 'gl_reclass' AND s.entry_id = e.id LIMIT 1) AS reclass_of
                             FROM gl_journal_lines l
                             JOIN gl_journal_entries e ON e.id = l.entry_id
                             WHERE l.account_id = ? AND e.entry_kind <> 'reversal'
                               AND NOT EXISTS (SELECT 1 FROM gl_journal_entries r WHERE r.reversal_of_id = e.id)
                             ORDER BY e.entry_date DESC, e.id DESC");
        $st->execute([$suspense]);
        $items = [];
        $adjust = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $net = (float) $r['debit'] - (float) $r['credit'];
            if ($r['reclass_of'] !== null) {
                $adjust[(int) $r['reclass_of']] = ($adjust[(int) $r['reclass_of']] ?? 0) + $net;
                continue;
            }
            $id = (int) $r['id'];
            if (!isset($items[$id])) {
                $items[$id] = $r + ['amount' => 0.0];
            }
            $items[$id]['amount'] += $net;
        }
        $out = [];
        foreach ($items as $id => $it) {
            $it['amount'] = round($it['amount'] + ($adjust[$id] ?? 0), 2);
            if (abs($it['amount']) >= 0.005) {
                $out[] = $it;
            }
        }
        return $out;
    }
}

if (!function_exists('gl_report_receivables_check')) {
    /**
     * Compare the ledger receivable balance with what revenue entries say is still owed.
     * A difference means documents were marked paid without the money being recorded in an account.
     *
     * @return array{ledger:float, outstanding:float, unrecorded_receipts:float, entries:int}
     */
    function gl_report_receivables_check(PDO $pdo): array
    {
        $ar = gl_system_account($pdo, 'accounts_receivable');
        $st = $pdo->prepare('SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM gl_journal_lines WHERE account_id = ?');
        $st->execute([$ar]);
        $ledger = round((float) $st->fetchColumn(), 2);

        $outstanding = 0.0;
        $count = 0;
        if (gl_table_exists($pdo, 'revenue_entries')) {
            $hasFx = gl_column_exists($pdo, 'revenue_entries', 'exchange_rate');
            $fx = $hasFx ? 'IF(exchange_rate > 0, exchange_rate, 1)' : '1';
            $row = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM((amount_total - COALESCE(total_paid, 0)) * {$fx}), 0) AS owed
                                FROM revenue_entries
                                WHERE amount_total > 0
                                  AND LOWER(COALESCE(approval_status, '')) NOT IN ('rejected','cancelled','canceled','void','voided','deleted')")
                ->fetch(PDO::FETCH_ASSOC);
            $outstanding = round((float) ($row['owed'] ?? 0), 2);
            $count = (int) ($row['n'] ?? 0);
        }

        return [
            'ledger' => $ledger,
            'outstanding' => $outstanding,
            'unrecorded_receipts' => round($ledger - $outstanding, 2),
            'entries' => $count,
        ];
    }
}

if (!function_exists('gl_report_duplicate_codes')) {
    /** Active financial accounts sharing the same code prefix. @return array<string, list<string>> */
    function gl_report_duplicate_codes(PDO $pdo): array
    {
        $byCode = [];
        foreach (gl_bridge_fa_rows($pdo) as $fa) {
            if (strtolower((string) ($fa['status'] ?? 'active')) !== 'active') {
                continue;
            }
            if (preg_match('/^\s*([0-9]{3,10})\s*-/', (string) $fa['name'], $m)) {
                $byCode[$m[1]][] = (string) $fa['name'];
            }
        }
        return array_filter($byCode, static fn ($names) => count($names) > 1);
    }
}

if (!function_exists('gl_report_legacy_ledger')) {
    /** @return array{entries:int, unbalanced:int} */
    function gl_report_legacy_ledger(PDO $pdo): array
    {
        if (!gl_table_exists($pdo, 'erp_journal_entries') || !gl_table_exists($pdo, 'erp_journal_items')) {
            return ['entries' => 0, 'unbalanced' => 0];
        }
        $entries = (int) $pdo->query('SELECT COUNT(*) FROM erp_journal_entries')->fetchColumn();
        $unbalanced = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT journal_id FROM erp_journal_items GROUP BY journal_id
                                          HAVING ABS(COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0)) > 0.009) x')->fetchColumn();
        return ['entries' => $entries, 'unbalanced' => $unbalanced];
    }
}

if (!function_exists('gl_report_pending_sources')) {
    /** Source rows not yet represented in the ledger. */
    function gl_report_pending_sources(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM account_transactions t
                                  WHERE NOT EXISTS (SELECT 1 FROM gl_source_links l
                                                    WHERE l.source_table = 'account_transactions' AND l.source_id = t.id AND l.active = 1)")
            ->fetchColumn();
    }
}
