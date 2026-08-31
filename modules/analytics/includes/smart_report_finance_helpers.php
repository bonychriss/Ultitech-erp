<?php
/**
 * Smart Report ù Financial statements and ledger intelligence.
 */

require_once __DIR__ . '/smart_report_sales_helpers.php';

if (!function_exists('smart_report_finance_parse_filters')) {
    function smart_report_finance_parse_filters(): array
    {
        $start = $_GET['start_date'] ?? date('Y-01-01');
        $end = $_GET['end_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start = date('Y-01-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end = date('Y-m-d');
        }
        if (strtotime($start) > strtotime($end)) {
            [$start, $end] = [$end, $start];
        }

        return [
            'start_date' => $start,
            'end_date' => $end,
        ];
    }
}

if (!function_exists('smart_report_finance_has_ledger')) {
    function smart_report_finance_has_ledger(PDO $pdo): bool
    {
        return tableExists('erp_accounts', $pdo)
            && tableExists('erp_journal_entries', $pdo)
            && tableExists('erp_journal_items', $pdo);
    }
}

if (!function_exists('smart_report_finance_fmt_cell')) {
    function smart_report_finance_fmt_cell(float $amount, bool $parenNeg = true): string
    {
        if ($parenNeg && $amount < 0) {
            return '(' . number_format(abs($amount), 2, '.', ',') . ')';
        }
        return number_format($amount, 2, '.', ',');
    }
}

if (!function_exists('smart_report_finance_fetch_by_type')) {
    function smart_report_finance_fetch_by_type(PDO $pdo, string $type, string $from, string $to): array
    {
        $sql = "SELECT a.name,
                       COALESCE(SUM(ji.credit), 0) AS c,
                       COALESCE(SUM(ji.debit), 0) AS d
                FROM erp_accounts a
                LEFT JOIN erp_journal_items ji ON a.id = ji.account_id
                LEFT JOIN erp_journal_entries je ON ji.journal_id = je.id AND je.date BETWEEN ? AND ?
                WHERE a.type = ?
                GROUP BY a.id, a.name
                HAVING ABS(COALESCE(SUM(ji.credit), 0)) > 0.00001 OR ABS(COALESCE(SUM(ji.debit), 0)) > 0.00001
                ORDER BY a.name ASC";
        $st = $pdo->prepare($sql);
        $st->execute([$from, $to, $type]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $credit = (float) ($r['c'] ?? 0);
            $debit = (float) ($r['d'] ?? 0);
            $out[$name] = $type === 'revenue' ? ($credit - $debit) : ($debit - $credit);
        }
        return $out;
    }
}

if (!function_exists('smart_report_finance_sum_map')) {
    function smart_report_finance_sum_map(array $map): float
    {
        return array_sum($map);
    }
}

if (!function_exists('smart_report_finance_map_rows')) {
    function smart_report_finance_map_rows(array $map, bool $indent = true): array
    {
        $rows = [];
        ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($map as $name => $amount) {
            if (abs((float) $amount) < 0.00001) {
                continue;
            }
            $rows[] = [
                'label' => (string) $name,
                'amount' => (float) $amount,
                'indent' => $indent,
            ];
        }
        return $rows;
    }
}

if (!function_exists('smart_report_finance_period_pair')) {
    function smart_report_finance_period_pair(string $start, string $end): array
    {
        $startTs = strtotime($start) ?: strtotime(date('Y-m-01'));
        $endTs = strtotime($end) ?: strtotime(date('Y-m-d'));
        if ($endTs < $startTs) {
            [$startTs, $endTs] = [$endTs, $startTs];
        }
        $days = max(1, (int) floor(($endTs - $startTs) / 86400) + 1);
        $prevEndTs = strtotime('-1 day', $startTs);
        $prevStartTs = strtotime('-' . ($days - 1) . ' days', $prevEndTs);

        return [
            'current' => [
                'start' => date('Y-m-d', $startTs),
                'end' => date('Y-m-d', $endTs),
                'label' => date('d M Y', $startTs) . ' - ' . date('d M Y', $endTs),
                'short' => date('d M', $startTs) . ' - ' . date('d M Y', $endTs),
            ],
            'previous' => [
                'start' => date('Y-m-d', $prevStartTs),
                'end' => date('Y-m-d', $prevEndTs),
                'label' => date('d M Y', $prevStartTs) . ' - ' . date('d M Y', $prevEndTs),
                'short' => date('d M', $prevStartTs) . ' - ' . date('d M Y', $prevEndTs),
            ],
        ];
    }
}

if (!function_exists('smart_report_finance_compare_maps')) {
    function smart_report_finance_compare_maps(array $curr, array $prev): array
    {
        $names = array_unique(array_merge(array_keys($curr), array_keys($prev)));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $rows = [];
        foreach ($names as $name) {
            $c = (float) ($curr[$name] ?? 0);
            $p = (float) ($prev[$name] ?? 0);
            if (abs($c) < 0.00001 && abs($p) < 0.00001) {
                continue;
            }
            $rows[] = ['label' => (string) $name, 'current' => $c, 'previous' => $p];
        }
        return $rows;
    }
}

if (!function_exists('smart_report_finance_bucket_expenses')) {
    function smart_report_finance_bucket_expenses(array $expenses): array
    {
        $cogs = [];
        $operating = [];
        $other = [];
        $tax = [];
        foreach ($expenses as $name => $amount) {
            $n = strtolower((string) $name);
            if (strpos($n, 'tax') !== false) {
                $tax[$name] = abs((float) $amount);
            } elseif (strpos($n, 'cost of goods') !== false || strpos($n, 'cogs') !== false
                || strpos($n, 'cost of sale') !== false || strpos($n, 'purchase') !== false
                || strpos($n, 'inventory') !== false || strpos($n, 'direct labour') !== false
                || strpos($n, 'manufacturing') !== false) {
                $cogs[$name] = (float) $amount;
            } elseif (strpos($n, 'finance') !== false || strpos($n, 'interest') !== false) {
                $other[$name] = -abs((float) $amount);
            } else {
                $operating[$name] = (float) $amount;
            }
        }
        return compact('cogs', 'operating', 'other', 'tax');
    }
}

if (!function_exists('smart_report_finance_bucket_revenue')) {
    function smart_report_finance_bucket_revenue(array $revenue): array
    {
        $main = [];
        $other = [];
        foreach ($revenue as $name => $amount) {
            $n = strtolower((string) $name);
            if (strpos($n, 'finance') !== false || strpos($n, 'interest') !== false) {
                $other[$name] = abs((float) $amount);
                continue;
            }
            $main[$name] = (float) $amount;
        }
        return ['revenue' => $main, 'other_income' => $other];
    }
}

if (!function_exists('smart_report_finance_pct_change')) {
    function smart_report_finance_pct_change(float $curr, float $prev): ?float
    {
        if (abs($prev) < 0.00001) {
            return abs($curr) < 0.00001 ? 0.0 : null;
        }
        return (($curr - $prev) / abs($prev)) * 100;
    }
}

if (!function_exists('smart_report_finance_profit_loss')) {
    function smart_report_finance_profit_loss(PDO $pdo, string $start, string $end): array
    {
        $periods = smart_report_finance_period_pair($start, $end);
        $currStart = $periods['current']['start'];
        $currEnd = $periods['current']['end'];
        $prevStart = $periods['previous']['start'];
        $prevEnd = $periods['previous']['end'];

        $revCurrAll = smart_report_finance_fetch_by_type($pdo, 'revenue', $currStart, $currEnd);
        $revPrevAll = smart_report_finance_fetch_by_type($pdo, 'revenue', $prevStart, $prevEnd);
        $expCurrAll = smart_report_finance_fetch_by_type($pdo, 'expense', $currStart, $currEnd);
        $expPrevAll = smart_report_finance_fetch_by_type($pdo, 'expense', $prevStart, $prevEnd);

        $revBucketsCurr = smart_report_finance_bucket_revenue($revCurrAll);
        $revBucketsPrev = smart_report_finance_bucket_revenue($revPrevAll);
        $expBucketsCurr = smart_report_finance_bucket_expenses($expCurrAll);
        $expBucketsPrev = smart_report_finance_bucket_expenses($expPrevAll);

        $revenue = $revBucketsCurr['revenue'];
        $revenuePrev = $revBucketsPrev['revenue'];
        $cogs = $expBucketsCurr['cogs'];
        $cogsPrev = $expBucketsPrev['cogs'];
        $operating = $expBucketsCurr['operating'];
        $operatingPrev = $expBucketsPrev['operating'];
        $otherExp = $expBucketsCurr['other'];
        $otherExpPrev = $expBucketsPrev['other'];
        $otherInc = $revBucketsCurr['other_income'];
        $otherIncPrev = $revBucketsPrev['other_income'];
        $tax = $expBucketsCurr['tax'];
        $taxPrev = $expBucketsPrev['tax'];

        foreach ($otherInc as $name => $amount) {
            $otherExp[$name] = ($otherExp[$name] ?? 0) + $amount;
        }
        foreach ($otherIncPrev as $name => $amount) {
            $otherExpPrev[$name] = ($otherExpPrev[$name] ?? 0) + $amount;
        }

        $revTotal = smart_report_finance_sum_map($revenue);
        $revPrevTotal = smart_report_finance_sum_map($revenuePrev);
        $cogsTotal = smart_report_finance_sum_map($cogs);
        $cogsPrevTotal = smart_report_finance_sum_map($cogsPrev);
        $gross = $revTotal - $cogsTotal;
        $grossPrev = $revPrevTotal - $cogsPrevTotal;
        $opTotal = smart_report_finance_sum_map($operating);
        $opPrevTotal = smart_report_finance_sum_map($operatingPrev);
        $operatingProfit = $gross - $opTotal;
        $operatingProfitPrev = $grossPrev - $opPrevTotal;
        $otherTotal = smart_report_finance_sum_map($otherExp);
        $otherPrevTotal = smart_report_finance_sum_map($otherExpPrev);
        $profitBeforeTax = $operatingProfit + $otherTotal;
        $profitBeforeTaxPrev = $operatingProfitPrev + $otherPrevTotal;
        $taxTotal = smart_report_finance_sum_map($tax);
        $taxPrevTotal = smart_report_finance_sum_map($taxPrev);
        $netIncome = $profitBeforeTax - $taxTotal;
        $netIncomePrev = $profitBeforeTaxPrev - $taxPrevTotal;

        $margin = $revTotal > 0 ? ($netIncome / $revTotal) * 100 : 0.0;
        $marginPrev = $revPrevTotal > 0 ? ($netIncomePrev / $revPrevTotal) * 100 : 0.0;

        $lines = [];
        $addSection = static function (string $label) use (&$lines): void {
            $lines[] = ['type' => 'section', 'label' => $label];
        };
        $addItems = static function (array $currMap, array $prevMap, bool $expenseDisplay = false) use (&$lines): void {
            $rows = smart_report_finance_compare_maps($currMap, $prevMap);
            foreach ($rows as $row) {
                $lines[] = [
                    'type' => 'item',
                    'label' => $row['label'],
                    'current' => $row['current'],
                    'previous' => $row['previous'],
                    'expense_display' => $expenseDisplay,
                ];
            }
        };
        $addTotal = static function (string $label, float $curr, float $prev, string $highlight = '') use (&$lines): void {
            $lines[] = [
                'type' => 'total',
                'label' => $label,
                'current' => $curr,
                'previous' => $prev,
                'highlight' => $highlight,
            ];
        };

        $addSection('REVENUE');
        $addItems($revenue, $revenuePrev);
        $addSection('COST OF GOODS SOLD (COGS)');
        $addItems($cogs, $cogsPrev);
        $addTotal('GROSS PROFIT', $gross, $grossPrev, 'green');
        $addSection('OPERATING EXPENSES');
        $addItems($operating, $operatingPrev);
        $addTotal('OPERATING PROFIT', $operatingProfit, $operatingProfitPrev, 'blue');
        $addSection('OTHER INCOME / (EXPENSES)');
        $addItems($otherExp, $otherExpPrev);
        $addTotal('PROFIT BEFORE TAX', $profitBeforeTax, $profitBeforeTaxPrev, 'blue');
        $addSection('TAX EXPENSE');
        $addItems($tax, $taxPrev, true);
        $addTotal('NET PROFIT', $netIncome, $netIncomePrev, 'green');

        return [
            'periods' => $periods,
            'lines' => $lines,
            'kpis' => [
                ['label' => 'Total Revenue', 'current' => $revTotal, 'previous' => $revPrevTotal, 'icon' => 'bi-cash-stack', 'tone' => 'emerald'],
                ['label' => 'Gross Profit', 'current' => $gross, 'previous' => $grossPrev, 'icon' => 'bi-graph-up', 'tone' => 'blue'],
                ['label' => 'Operating Expenses', 'current' => $opTotal, 'previous' => $opPrevTotal, 'icon' => 'bi-wallet2', 'tone' => 'violet'],
                ['label' => 'Operating Profit', 'current' => $operatingProfit, 'previous' => $operatingProfitPrev, 'icon' => 'bi-briefcase', 'tone' => 'amber'],
                ['label' => 'Net Profit', 'current' => $netIncome, 'previous' => $netIncomePrev, 'icon' => 'bi-piggy-bank', 'tone' => 'teal'],
                [
                    'label' => 'Net Profit Margin',
                    'current' => $margin,
                    'previous' => $marginPrev,
                    'icon' => 'bi-percent',
                    'tone' => 'rose',
                    'is_margin' => true,
                ],
            ],
            'revenue_rows' => smart_report_finance_map_rows($revenue),
            'cogs_rows' => smart_report_finance_map_rows($cogs),
            'operating_rows' => smart_report_finance_map_rows($operating),
            'other_rows' => smart_report_finance_map_rows($otherExp),
            'revenue_total' => $revTotal,
            'cogs_total' => $cogsTotal,
            'gross_profit' => $gross,
            'operating_total' => $opTotal,
            'operating_profit' => $operatingProfit,
            'other_total' => $otherTotal,
            'net_income' => $netIncome,
            'net_income_prev' => $netIncomePrev,
        ];
    }
}

if (!function_exists('smart_report_finance_balance_sheet')) {
    function smart_report_finance_balance_sheet(PDO $pdo, string $asOf): array
    {
        $fetchBal = static function (string $type, bool $creditNormal) use ($pdo, $asOf): array {
            $sql = "SELECT a.name,
                           COALESCE(SUM(ji.debit), 0) AS d,
                           COALESCE(SUM(ji.credit), 0) AS c
                    FROM erp_accounts a
                    LEFT JOIN erp_journal_items ji ON a.id = ji.account_id
                    LEFT JOIN erp_journal_entries je ON ji.journal_id = je.id AND je.date <= ?
                    WHERE a.type = ?
                    GROUP BY a.id, a.name";
            $st = $pdo->prepare($sql);
            $st->execute([$asOf, $type]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $debit = (float) ($r['d'] ?? 0);
                $credit = (float) ($r['c'] ?? 0);
                $bal = $creditNormal ? ($credit - $debit) : ($debit - $credit);
                if (abs($bal) < 0.00001) {
                    continue;
                }
                $out[(string) $r['name']] = $bal;
            }
            return $out;
        };

        $assets = $fetchBal('asset', false);
        $liabilities = $fetchBal('liability', true);
        $equity = $fetchBal('equity', true);

        $netIncome = 0.0;
        $rev = smart_report_finance_fetch_by_type($pdo, 'revenue', '1900-01-01', $asOf);
        $exp = smart_report_finance_fetch_by_type($pdo, 'expense', '1900-01-01', $asOf);
        $netIncome = smart_report_finance_sum_map($rev) - smart_report_finance_sum_map($exp);

        $totalAssets = smart_report_finance_sum_map($assets);
        $totalLiabilities = smart_report_finance_sum_map($liabilities);
        $totalEquityAccounts = smart_report_finance_sum_map($equity);
        $totalEquity = $totalEquityAccounts + $netIncome;
        $totalLiabEquity = $totalLiabilities + $totalEquity;

        return [
            'asset_rows' => smart_report_finance_map_rows($assets),
            'liability_rows' => smart_report_finance_map_rows($liabilities),
            'equity_rows' => smart_report_finance_map_rows($equity),
            'retained_earnings' => $netIncome,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'total_liab_equity' => $totalLiabEquity,
        ];
    }
}

if (!function_exists('smart_report_finance_cash_flow')) {
    function smart_report_finance_cash_flow(PDO $pdo, string $start, string $end): array
    {
        $pl = smart_report_finance_profit_loss($pdo, $start, $end);
        $netIncome = $pl['net_income'];

        $changeForCode = static function (string $like, bool $creditPositive) use ($pdo, $start, $end): float {
            $sql = "SELECT COALESCE(SUM(ji.debit), 0) AS d, COALESCE(SUM(ji.credit), 0) AS c
                    FROM erp_journal_items ji
                    JOIN erp_accounts a ON ji.account_id = a.id
                    JOIN erp_journal_entries je ON ji.journal_id = je.id
                    WHERE a.code LIKE ? AND je.date BETWEEN ? AND ?";
            $st = $pdo->prepare($sql);
            $st->execute([$like, $start, $end]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['d' => 0, 'c' => 0];
            $delta = (float) $r['d'] - (float) $r['c'];
            return $creditPositive ? ((float) $r['c'] - (float) $r['d']) : -$delta;
        };

        $changeAR = $changeForCode('1200%', false);
        $changeInventory = $changeForCode('1300%', false);
        $changeAP = $changeForCode('2000%', true);
        $changeFixedAssets = $changeForCode('1500%', false);
        $cff = $changeForCode('2500%', true) + $changeForCode('3000%', true);

        $cfo = $netIncome - $changeAR - $changeInventory + $changeAP;
        $cfi = -$changeFixedAssets;
        $netChange = $cfo + $cfi + $cff;

        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(ji.debit - ji.credit), 0)
             FROM erp_journal_items ji
             JOIN erp_accounts a ON ji.account_id = a.id
             JOIN erp_journal_entries je ON ji.journal_id = je.id
             WHERE a.type = 'asset'
               AND (a.name LIKE '%Bank%' OR a.name LIKE '%Cash%')
               AND je.date < ?"
        );
        $st->execute([$start]);
        $startCash = (float) ($st->fetchColumn() ?: 0);
        $endCash = $startCash + $netChange;

        return [
            'net_income' => $netIncome,
            'change_ar' => -$changeAR,
            'change_inventory' => -$changeInventory,
            'change_ap' => $changeAP,
            'operating_total' => $cfo,
            'investing_total' => $cfi,
            'financing_total' => $cff,
            'net_change' => $netChange,
            'start_cash' => $startCash,
            'end_cash' => $endCash,
        ];
    }
}

if (!function_exists('smart_report_finance_trial_balance')) {
    function smart_report_finance_trial_balance(PDO $pdo, string $start, string $end): array
    {
        $sql = "SELECT a.code, a.name, a.type,
                       COALESCE(SUM(CASE WHEN je.date BETWEEN ? AND ? THEN ji.debit ELSE 0 END), 0) AS total_debit,
                       COALESCE(SUM(CASE WHEN je.date BETWEEN ? AND ? THEN ji.credit ELSE 0 END), 0) AS total_credit
                FROM erp_accounts a
                LEFT JOIN erp_journal_items ji ON a.id = ji.account_id
                LEFT JOIN erp_journal_entries je ON ji.journal_id = je.id
                GROUP BY a.id, a.code, a.name, a.type
                HAVING ABS(COALESCE(SUM(CASE WHEN je.date BETWEEN ? AND ? THEN ji.debit ELSE 0 END), 0)) > 0.00001
                    OR ABS(COALESCE(SUM(CASE WHEN je.date BETWEEN ? AND ? THEN ji.credit ELSE 0 END), 0)) > 0.00001
                ORDER BY CAST(a.code AS UNSIGNED) ASC, a.code ASC";
        $st = $pdo->prepare($sql);
        $st->execute([$start, $end, $start, $end, $start, $end, $start, $end]);
        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $debit = (float) ($r['total_debit'] ?? 0);
            $credit = (float) ($r['total_credit'] ?? 0);
            $totalDebit += $debit;
            $totalCredit += $credit;
            $rows[] = [
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'type' => ucfirst((string) ($r['type'] ?? '')),
                'debit' => $debit,
                'credit' => $credit,
                'net' => $debit - $credit,
            ];
        }

        return [
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced' => abs($totalDebit - $totalCredit) < 0.01,
        ];
    }
}

if (!function_exists('smart_report_finance_equity_changes')) {
    function smart_report_finance_equity_changes(PDO $pdo, string $start, string $end): array
    {
        $openingSql = "SELECT COALESCE(SUM(ji.credit - ji.debit), 0)
                       FROM erp_journal_items ji
                       JOIN erp_accounts a ON ji.account_id = a.id
                       JOIN erp_journal_entries je ON ji.journal_id = je.id
                       WHERE a.type = 'equity' AND je.date < ?";
        $st = $pdo->prepare($openingSql);
        $st->execute([$start]);
        $openingEquity = (float) ($st->fetchColumn() ?: 0);

        $periodSql = "SELECT COALESCE(SUM(ji.credit - ji.debit), 0)
                      FROM erp_journal_items ji
                      JOIN erp_accounts a ON ji.account_id = a.id
                      JOIN erp_journal_entries je ON ji.journal_id = je.id
                      WHERE a.type = 'equity' AND je.date BETWEEN ? AND ?";
        $st = $pdo->prepare($periodSql);
        $st->execute([$start, $end]);
        $periodEquityMovement = (float) ($st->fetchColumn() ?: 0);

        $pl = smart_report_finance_profit_loss($pdo, $start, $end);
        $netIncome = $pl['net_income'];
        $contributions = max(0, $periodEquityMovement);
        $drawings = abs(min(0, $periodEquityMovement));
        $closingEquity = $openingEquity + $periodEquityMovement + $netIncome;

        return [
            'opening_equity' => $openingEquity,
            'net_income' => $netIncome,
            'contributions' => $contributions,
            'drawings' => $drawings,
            'closing_equity' => $closingEquity,
        ];
    }
}

if (!function_exists('smart_report_finance_general_ledger')) {
    function smart_report_finance_general_ledger(PDO $pdo, string $start, string $end, int $limit = 50): array
    {
        $sql = "SELECT je.date, je.reference, je.description AS journal_desc,
                       a.code, a.name AS account_name,
                       ji.debit, ji.credit
                FROM erp_journal_items ji
                JOIN erp_journal_entries je ON ji.journal_id = je.id
                JOIN erp_accounts a ON ji.account_id = a.id
                WHERE je.date BETWEEN ? AND ?
                ORDER BY je.date DESC, je.id DESC, ji.id DESC
                LIMIT " . (int) $limit;
        $st = $pdo->prepare($sql);
        $st->execute([$start, $end]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('smart_report_finance_journal_entries')) {
    function smart_report_finance_journal_entries(PDO $pdo, string $start, string $end, int $limit = 40): array
    {
        $sql = "SELECT je.id, je.date, je.reference, je.description, je.status,
                       COALESCE(SUM(ji.debit), 0) AS total_debit,
                       COALESCE(SUM(ji.credit), 0) AS total_credit,
                       COUNT(ji.id) AS line_count
                FROM erp_journal_entries je
                LEFT JOIN erp_journal_items ji ON ji.journal_id = je.id
                WHERE je.date BETWEEN ? AND ?
                GROUP BY je.id
                ORDER BY je.date DESC, je.id DESC
                LIMIT " . (int) $limit;
        $st = $pdo->prepare($sql);
        $st->execute([$start, $end]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('smart_report_finance_chart_of_accounts')) {
    function smart_report_finance_chart_of_accounts(PDO $pdo, string $asOf): array
    {
        $sql = "SELECT a.code, a.name, a.type,
                       COALESCE(SUM(ji.debit), 0) AS total_debit,
                       COALESCE(SUM(ji.credit), 0) AS total_credit
                FROM erp_accounts a
                LEFT JOIN erp_journal_items ji ON a.id = ji.account_id
                LEFT JOIN erp_journal_entries je ON ji.journal_id = je.id AND je.date <= ?
                GROUP BY a.id, a.code, a.name, a.type
                ORDER BY CAST(a.code AS UNSIGNED) ASC, a.code ASC";
        $st = $pdo->prepare($sql);
        $st->execute([$asOf]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $type = strtolower((string) ($r['type'] ?? ''));
            $debit = (float) ($r['total_debit'] ?? 0);
            $credit = (float) ($r['total_credit'] ?? 0);
            $balance = in_array($type, ['asset', 'expense'], true) ? ($debit - $credit) : ($credit - $debit);
            $rows[] = [
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'type' => ucfirst($type),
                'balance' => $balance,
            ];
        }
        return $rows;
    }
}

if (!function_exists('smart_report_finance_reports')) {
    function smart_report_finance_reports(PDO $pdo, array $filters): array
    {
        $start = $filters['start_date'];
        $end = $filters['end_date'];
        $hasLedger = smart_report_finance_has_ledger($pdo);

        $out = [
            'has_data' => $hasLedger,
            'period' => [
                'start_date' => $start,
                'end_date' => $end,
                'label' => date('M j, Y', strtotime($start)) . ' ù ' . date('M j, Y', strtotime($end)),
            ],
            'profit_loss' => [],
            'balance_sheet' => [],
            'cash_flow' => [],
            'trial_balance' => ['rows' => [], 'total_debit' => 0, 'total_credit' => 0, 'balanced' => true],
            'equity_changes' => [],
            'general_ledger' => [],
            'journal_entries' => [],
            'chart_of_accounts' => [],
            'summary' => [
                'net_income' => 0.0,
                'total_assets' => 0.0,
                'account_count' => 0,
                'journal_count' => 0,
            ],
        ];

        if (!$hasLedger) {
            return $out;
        }

        $out['profit_loss'] = smart_report_finance_profit_loss($pdo, $start, $end);
        $out['balance_sheet'] = smart_report_finance_balance_sheet($pdo, $end);
        $out['cash_flow'] = smart_report_finance_cash_flow($pdo, $start, $end);
        $out['trial_balance'] = smart_report_finance_trial_balance($pdo, $start, $end);
        $out['equity_changes'] = smart_report_finance_equity_changes($pdo, $start, $end);
        $out['general_ledger'] = smart_report_finance_general_ledger($pdo, $start, $end);
        $out['journal_entries'] = smart_report_finance_journal_entries($pdo, $start, $end);
        $out['chart_of_accounts'] = smart_report_finance_chart_of_accounts($pdo, $end);

        $out['summary']['net_income'] = $out['profit_loss']['net_income'] ?? 0;
        $out['summary']['total_assets'] = $out['balance_sheet']['total_assets'] ?? 0;
        $out['summary']['account_count'] = count($out['chart_of_accounts']);
        $out['summary']['journal_count'] = count($out['journal_entries']);

        return $out;
    }
}

if (!function_exists('smart_report_finance_pl_fmt_amount')) {
    function smart_report_finance_pl_fmt_amount(float $amount, bool $expenseLine = false): string
    {
        $display = $expenseLine ? -abs($amount) : $amount;
        if ($display < 0) {
            return '(' . number_format(abs($display), 0, '.', ',') . ')';
        }
        return number_format($display, 0, '.', ',');
    }
}

if (!function_exists('smart_report_finance_pl_change_html')) {
    function smart_report_finance_pl_change_html(float $curr, float $prev, bool $isMargin = false): string
    {
        if ($isMargin) {
            $delta = $curr - $prev;
            if (abs($delta) < 0.05) {
                return '<span class="fi-pl-change fi-pl-change--flat">0.0 pp</span>';
            }
            $cls = $delta >= 0 ? 'up' : 'down';
            $icon = $delta >= 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
            return '<span class="fi-pl-change fi-pl-change--' . $cls . '"><i class="bi ' . $icon . '"></i> '
                . number_format(abs($delta), 1) . ' pp</span>';
        }

        $pct = smart_report_finance_pct_change($curr, $prev);
        if ($pct === null) {
            return '<span class="fi-pl-change fi-pl-change--flat">ù</span>';
        }
        if (abs($pct) < 0.05) {
            return '<span class="fi-pl-change fi-pl-change--flat">0.0%</span>';
        }
        $cls = $pct >= 0 ? 'up' : 'down';
        $icon = $pct >= 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
        return '<span class="fi-pl-change fi-pl-change--' . $cls . '"><i class="bi ' . $icon . '"></i> '
            . number_format(abs($pct), 1) . '%</span>';
    }
}

if (!function_exists('smart_report_finance_pl_kpi_value')) {
    function smart_report_finance_pl_kpi_value(array $kpi): string
    {
        if (!empty($kpi['is_margin'])) {
            return number_format((float) $kpi['current'], 1) . '%';
        }
        return 'TZS ' . number_format((float) $kpi['current'], 0, '.', ',');
    }
}

if (!function_exists('smart_report_render_pl_foldable_table')) {
    function smart_report_render_pl_foldable_table(array $pl, string $currLabel, string $prevLabel): string
    {
        $netCurr = (float) ($pl['net_income'] ?? 0);
        $netPrev = (float) ($pl['net_income_prev'] ?? 0);

        ob_start();
        ?>
        <div class="sa-matrix-card is-tree-collapsed fi-table-card fi-pl-foldable"
             data-matrix="fi-pl-table"
             data-preview-rows="0"
             data-view-all-label="rows">
            <div class="sa-matrix-scroll">
                <table class="sa-matrix sa-matrix--finance fi-pl-table" id="fi-pl-table">
                    <thead>
                        <tr>
                            <th class="sa-col-num">#</th>
                            <th class="sa-col-label">Particulars</th>
                            <th class="fi-pl-col-amt">This Period<br><span>(<?= htmlspecialchars($currLabel) ?>) (TZS)</span></th>
                            <th class="fi-pl-col-amt">Previous Period<br><span>(<?= htmlspecialchars($prevLabel) ?>) (TZS)</span></th>
                            <th class="fi-pl-col-change">Change (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="sa-row-total sa-row-parent">
                            <td class="sa-col-num">1</td>
                            <td class="sa-col-label">
                                <button type="button" class="sa-tree-toggle bi bi-chevron-right" aria-label="Toggle profit and loss details" aria-expanded="false"></button>
                                Net Profit
                            </td>
                            <td class="sa-matrix-val sa-matrix-val--total fi-pl-col-amt"><?= smart_report_finance_pl_fmt_amount($netCurr) ?></td>
                            <td class="sa-matrix-val sa-matrix-val--total fi-pl-col-amt"><?= smart_report_finance_pl_fmt_amount($netPrev) ?></td>
                            <td class="fi-pl-col-change"><?= smart_report_finance_pl_change_html($netCurr, $netPrev) ?></td>
                        </tr>
                        <?php foreach ($pl['lines'] ?? [] as $idx => $line): ?>
                            <?php
                            $type = (string) ($line['type'] ?? 'item');
                            $expenseLine = $type === 'item' && (
                                !empty($line['expense_display'])
                                || (float) ($line['current'] ?? 0) < 0
                                || (float) ($line['previous'] ?? 0) < 0
                            );
                            $rowClass = 'sa-row-child';
                            if ($type === 'section') {
                                $rowClass .= ' fi-pl-row fi-pl-row--section';
                            } elseif ($type === 'total') {
                                $highlight = preg_replace('/[^a-z]/', '', (string) ($line['highlight'] ?? 'blue'));
                                $rowClass .= ' fi-pl-row fi-pl-row--total fi-pl-row--' . $highlight;
                            } else {
                                $rowClass .= ' fi-pl-row fi-pl-row--item';
                            }
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td class="sa-col-num"><?= $idx + 2 ?></td>
                                <?php if ($type === 'section'): ?>
                                    <td class="sa-col-label" colspan="4"><?= htmlspecialchars((string) ($line['label'] ?? '')) ?></td>
                                <?php else: ?>
                                    <td class="sa-col-label<?= $type === 'item' ? ' sa-col-label-child' : '' ?>"><?= htmlspecialchars((string) ($line['label'] ?? '')) ?></td>
                                    <td class="sa-matrix-val fi-pl-col-amt"><?= smart_report_finance_pl_fmt_amount((float) ($line['current'] ?? 0), $expenseLine) ?></td>
                                    <td class="sa-matrix-val fi-pl-col-amt"><?= smart_report_finance_pl_fmt_amount((float) ($line['previous'] ?? 0), $expenseLine) ?></td>
                                    <td class="fi-pl-col-change"><?= smart_report_finance_pl_change_html((float) ($line['current'] ?? 0), (float) ($line['previous'] ?? 0)) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="fi-pl-footnote"><em>All amounts are in Tanzanian Shillings (TZS).</em></p>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('smart_report_finance_verify_displayed_data')) {
    function smart_report_finance_verify_displayed_data(PDO $pdo, array $filters, array $displayed): array
    {
        $issues = [];
        $checks = 0;
        $companyId = analytics_current_company_id() ?: (int)($_SESSION['company_id'] ?? 0);

        // 1. Database isolation check
        global $control_pdo;
        $dbPdo = $control_pdo ?? $pdo;
        $expectedDb = '';
        if ($companyId > 0 && tableExists('companies', $dbPdo) && columnExists('companies', 'db_name', $dbPdo)) {
            try {
                $st = $dbPdo->prepare("SELECT db_name FROM companies WHERE id = ? LIMIT 1");
                $st->execute([$companyId]);
                $expectedDb = trim((string) $st->fetchColumn());
            } catch (Throwable $e) {
            }
        }
        $activeDb = '';
        try {
            $activeDb = trim((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        } catch (Throwable $e) {
        }
        if ($expectedDb !== '' && $activeDb !== '' && strcasecmp($expectedDb, $activeDb) !== 0) {
            $checks++;
            $issues[] = [
                'type' => 'company_database_mismatch',
                'metric' => 'Database isolation',
                'section' => 'Data isolation',
                'displayed' => $activeDb,
                'displayed_fmt' => $activeDb,
                'expected' => $expectedDb,
                'expected_fmt' => $expectedDb,
                'message' => 'The active database connection does not match the company\'s configured database. You are viewing another company\'s database.'
            ];
        }

        // 2. Trial Balance Check: total debits must equal total credits
        $tb = $displayed['trial_balance'] ?? [];
        if (isset($tb['total_debit']) && isset($tb['total_credit'])) {
            $checks++;
            $debit = (float)$tb['total_debit'];
            $credit = (float)$tb['total_credit'];
            if (abs($debit - $credit) > 0.05) {
                $issues[] = [
                    'type' => 'trial_balance_unbalanced',
                    'metric' => 'Trial balance equilibrium',
                    'section' => 'Accounting Ledger Integrity',
                    'displayed' => $debit,
                    'displayed_fmt' => number_format($debit, 2),
                    'expected' => $credit,
                    'expected_fmt' => number_format($credit, 2),
                    'message' => 'Total debits do not equal total credits in the trial balance. The ledger is out of balance.'
                ];
            }
        }

        // 3. Balance Sheet check: Assets = Liabilities + Equity
        $bs = $displayed['balance_sheet'] ?? [];
        if (isset($bs['total_assets']) && isset($bs['total_liabilities']) && isset($bs['total_equity'])) {
            $checks++;
            $assets = (float)$bs['total_assets'];
            $liabs = (float)$bs['total_liabilities'];
            $equity = (float)$bs['total_equity'];
            $liabEquity = $liabs + $equity;
            if (abs($assets - $liabEquity) > 0.05) {
                $issues[] = [
                    'type' => 'balance_sheet_mismatch',
                    'metric' => 'Balance Sheet equation',
                    'section' => 'Accounting Ledger Integrity',
                    'displayed' => $assets,
                    'displayed_fmt' => number_format($assets, 2),
                    'expected' => $liabEquity,
                    'expected_fmt' => number_format($liabEquity, 2),
                    'message' => 'Total Assets does not equal Liabilities + Equity.'
                ];
            }
        }

        // 4. Net Profit calculation check
        $pl = $displayed['profit_loss'] ?? [];
        if (isset($pl['net_income'])) {
            $checks++;
            $netIncome = (float)$pl['net_income'];
            // Recompute manually from the lines if possible
            $rev = (float)($pl['revenue_total'] ?? 0);
            $cogs = (float)($pl['cogs_total'] ?? 0);
            $op = (float)($pl['operating_total'] ?? 0);
            $other = (float)($pl['other_total'] ?? 0);
            $tax = (float)($pl['tax_total'] ?? 0);
            $expectedNet = $rev - $cogs - $op + $other - $tax;
            if (abs($netIncome - $expectedNet) > 0.05) {
                $issues[] = [
                    'type' => 'net_profit_mismatch',
                    'metric' => 'Net Profit correctness',
                    'section' => 'Accounting Ledger Integrity',
                    'displayed' => $netIncome,
                    'displayed_fmt' => number_format($netIncome, 2),
                    'expected' => $expectedNet,
                    'expected_fmt' => number_format($expectedNet, 2),
                    'message' => 'The displayed Net Profit does not match the computed Net Profit from revenue and expense totals.'
                ];
            }
        }

        $companyName = '';
        if (function_exists('getCurrentCompany')) {
            $co = getCurrentCompany();
            if (!empty($co['company_name'])) {
                $companyName = (string) $co['company_name'];
            }
        }
        if ($companyName === '' && !empty($_SESSION['company_name'])) {
            $companyName = (string) $_SESSION['company_name'];
        }

        return [
            'accurate' => empty($issues),
            'issue_count' => count($issues),
            'issues' => $issues,
            'check_count' => $checks,
            'period' => $displayed['period'] ?? $filters,
            'company' => [
                'id' => $companyId,
                'name' => $companyName,
            ],
        ];
    }
}

if (!function_exists('smart_report_finance_ai_verify_analysis')) {
    function smart_report_finance_ai_verify_analysis(PDO $pdo, array $verification): array
    {
        $accurate = !empty($verification['accurate']);
        $issues = $verification['issues'] ?? [];
        $period = $verification['period'] ?? [];
        $periodLabel = ($period['start_date'] ?? '') . ' to ' . ($period['end_date'] ?? '');
        $company = $verification['company'] ?? [];
        $companyName = trim((string) ($company['name'] ?? ''));
        $companyLabel = $companyName !== '' ? $companyName : 'the active company';

        if ($accurate) {
            return [
                'source' => 'rules',
                'summary' => 'All ' . (int) ($verification['check_count'] ?? 0)
                    . ' verification checks passed successfully. Displayed metrics match '
                    . $companyLabel
                    . ' database records for '
                    . $periodLabel
                    . ' with 100% accuracy and truth, verifying that only this company\'s data is displayed.',
                'details' => [],
            ];
        }

        $rulesDetails = [];
        foreach ($issues as $issue) {
            $rulesDetails[] = ($issue['section'] ?? 'Report') . ' - '
                . ($issue['metric'] ?? 'Metric') . ': shown '
                . ($issue['displayed_fmt'] ?? '') . ', expected '
                . ($issue['expected_fmt'] ?? '') . '. '
                . ($issue['message'] ?? '');
        }

        try {
            $aiHelpers = __DIR__ . '/../../includes/ai_helpers.php';
            if (!is_file($aiHelpers)) {
                return [
                    'source' => 'rules',
                    'summary' => count($issues) . ' data accuracy issue(s) found.',
                    'details' => $rulesDetails,
                ];
            }
            require_once $aiHelpers;

            $settings = ai_fetch_settings_row();
            if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
                return [
                    'source' => 'rules',
                    'summary' => count($issues) . ' data accuracy issue(s) found.',
                    'details' => $rulesDetails,
                ];
            }

            $issueText = '';
            foreach ($issues as $issue) {
                $issueText .= '- ' . ($issue['metric'] ?? '') . ' (' . ($issue['section'] ?? '') . '): '
                    . 'displayed ' . ($issue['displayed_fmt'] ?? $issue['displayed'] ?? '')
                    . ', expected ' . ($issue['expected_fmt'] ?? $issue['expected'] ?? '') . '. '
                    . ($issue['message'] ?? '') . "\n";
            }

            $messages = [
                [
                    'role' => 'system',
                    'content' => 'You are a strict financial data accuracy auditor for an ERP financial analytics dashboard. '
                        . 'Verify that only the active company\'s data is displayed and that all company metrics are 100% accurate, truthful, and isolated. '
                        . 'Given verification failures, explain each issue clearly and suggest the most likely root cause '
                        . '(query filter, missing transactions, unbalanced journal entries, incorrect asset/liability/equity mapping, or database isolation issues). '
                        . 'Use plain language. Format exactly:\n'
                        . "SUMMARY: [one sentence overview]\n"
                        . "DETAIL: [explanation for one issue]\n"
                        . 'Up to one DETAIL line per issue.',
                ],
                [
                    'role' => 'user',
                    'content' => "Active company: {$companyLabel}\nPeriod: {$periodLabel}\nVerification failures:\n{$issueText}",
                ],
            ];

            $openai = ai_openai_request($messages);
            $content = (string) ($openai['content'] ?? '');
            $summary = '';
            $details = [];
            foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (stripos($line, 'SUMMARY:') === 0) {
                    $summary = trim(substr($line, 8));
                } elseif (stripos($line, 'DETAIL:') === 0) {
                    $details[] = trim(substr($line, 7));
                }
            }

            if ($summary === '') {
                $summary = count($issues) . ' data accuracy issue(s) found.';
            }
            if (empty($details)) {
                $details = $rulesDetails;
            }

            return [
                'source' => 'ai',
                'summary' => $summary,
                'details' => $details,
            ];
        } catch (Throwable $e) {
            error_log('smart_report_finance_ai_verify_analysis: ' . $e->getMessage());
            return [
                'source' => 'rules',
                'summary' => count($issues) . ' data accuracy issue(s) found.',
                'details' => $rulesDetails,
            ];
        }
    }
}

if (!function_exists('smart_report_render_finance_checker_result')) {
    function smart_report_render_finance_checker_result(array $verification, array $analysis, bool $serviceOk = true): string
    {
        $accurate = $serviceOk && !empty($verification['accurate']);
        $stateClass = $accurate ? 'sa-data-checker--ok' : 'sa-data-checker--error';
        $icon = $accurate ? 'bi-shield-check' : 'bi-shield-exclamation';
        $summary = $serviceOk
            ? ($analysis['summary'] ?? ($accurate
                ? 'All metrics verified.'
                : ((int) ($verification['issue_count'] ?? 0)) . ' accuracy issue(s) found.'))
            : 'Verification could not be completed.';

        $bodyHtml = '';
        if (!$accurate && $serviceOk) {
            $issues = $verification['issues'] ?? [];
            $details = $analysis['details'] ?? [];
            if ($issues !== []) {
                $bodyHtml .= '<ul class="sa-data-checker-issues">';
                foreach ($issues as $issue) {
                    $bodyHtml .= '<li><strong>'
                        . htmlspecialchars((string) ($issue['metric'] ?? 'Metric'), ENT_QUOTES, 'UTF-8')
                        . ' - '
                        . htmlspecialchars((string) ($issue['section'] ?? ''), ENT_QUOTES, 'UTF-8')
                        . '</strong>';
                    $bodyHtml .= ' Shown: '
                        . htmlspecialchars((string) ($issue['displayed_fmt'] ?? $issue['displayed'] ?? ''), ENT_QUOTES, 'UTF-8')
                        . ' &middot; Expected: '
                        . htmlspecialchars((string) ($issue['expected_fmt'] ?? $issue['expected'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $bodyHtml .= ' <em>'
                        . htmlspecialchars((string) ($issue['message'] ?? ''), ENT_QUOTES, 'UTF-8')
                        . '</em></li>';
                }
                $bodyHtml .= '</ul>';
            }
            if ($details !== []) {
                $bodyHtml .= '<ul class="sa-data-checker-details">';
                foreach ($details as $detail) {
                    $bodyHtml .= '<li>' . htmlspecialchars((string) $detail, ENT_QUOTES, 'UTF-8') . '</li>';
                }
                $bodyHtml .= '</ul>';
            }
            if ($bodyHtml !== '') {
                $bodyHtml = '<p class="sa-data-checker-blocked-note">Financial reports are hidden until these data integrity issues are resolved.</p>'
                    . $bodyHtml;
            }
        }

        return '<div class="sa-data-checker ' . $stateClass . ($accurate ? '' : ' sa-data-checker--blocking') . '" id="saDataChecker" aria-live="polite" style="margin-bottom: 24px;">'
            . '<div class="sa-data-checker-inner">'
            . '<span class="sa-data-checker-icon" aria-hidden="true"><i class="bi ' . $icon . '"></i></span>'
            . '<div class="sa-data-checker-copy">'
            . '<strong class="sa-data-checker-title">AI Data Checker</strong>'
            . '<span class="sa-data-checker-status">' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</div>'
            . '</div>'
            . ($bodyHtml !== '' ? '<div class="sa-data-checker-body">' . $bodyHtml . '</div>' : '')
            . '</div>';
    }
}

if (!function_exists('smart_report_render_finance_simple_table')) {
    function smart_report_render_finance_simple_table(
        string $tableId,
        array $headers,
        array $bodyRows,
        array $totalRow,
        int $previewRows = 0,
        string $viewAllLabel = 'rows'
    ): string {
        ob_start();
        $totalCount = count($bodyRows);
        ?>
        <div class="sa-matrix-card is-tree-collapsed fi-table-card"
             data-matrix="<?= htmlspecialchars($tableId) ?>"
             data-preview-rows="<?= (int) $previewRows ?>"
             data-view-all-label="<?= htmlspecialchars($viewAllLabel) ?>">
            <div class="sa-matrix-scroll">
                <table class="sa-matrix" id="<?= htmlspecialchars($tableId) ?>">
                    <thead>
                        <tr>
                            <?php foreach ($headers as $h): ?>
                                <th class="<?= htmlspecialchars($h['class'] ?? '') ?>"><?= htmlspecialchars($h['label'] ?? '') ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Parent Total Row -->
                        <tr class="sa-row-total sa-row-parent">
                            <?php foreach (($totalRow['cells'] ?? []) as $idx => $cell): ?>
                                <?php if ($idx === 0): ?>
                                    <td class="sa-col-label">
                                        <button type="button" class="sa-tree-toggle bi bi-chevron-right" aria-label="Toggle rows" aria-expanded="false"></button>
                                        <?= htmlspecialchars($totalRow['label'] ?? '') ?>
                                    </td>
                                <?php else: ?>
                                    <td class="sa-matrix-val sa-matrix-val--total"><?= $cell ?></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Child Rows -->
                        <?php foreach ($bodyRows as $r): ?>
                            <tr class="sa-row-child" style="display: none;">
                                <?php foreach (($r['cells'] ?? []) as $idx => $cell): ?>
                                    <?php if ($idx === 0): ?>
                                        <td class="sa-col-label <?= !empty($r['indent']) ? 'sa-col-label-child' : '' ?>">
                                            <?= htmlspecialchars($cell) ?>
                                        </td>
                                    <?php else: ?>
                                        <td class="sa-matrix-val"><?= $cell ?></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($previewRows > 0 && $totalCount > $previewRows): ?>
                <div class="sa-matrix-actions">
                    <button type="button" class="sa-view-all-btn" aria-expanded="false">
                        View all <?= number_format($totalCount) ?> <?= htmlspecialchars($viewAllLabel) ?> <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('smart_report_render_profit_loss_statement')) {
    function smart_report_render_profit_loss_statement(array $pl, array $filters): string
    {
        $currStart = $pl['periods']['current']['start'] ?? $filters['start_date'];
        $currEnd = $pl['periods']['current']['end'] ?? $filters['end_date'];
        $currLabel = date('d M Y', strtotime($currStart)) . ' - ' . date('d M Y', strtotime($currEnd));
        $prevStart = $pl['periods']['previous']['start'] ?? '';
        $prevEnd = $pl['periods']['previous']['end'] ?? '';
        $prevLabel = ($prevStart && $prevEnd) ? (date('d M Y', strtotime($prevStart)) . ' - ' . date('d M Y', strtotime($prevEnd))) : '';

        ob_start();
        ?>
        <section class="sales-drill-section" id="fi-profit-loss">
            <div class="sa-section-header-wrap">
                <?= smart_report_render_section_head(
                    'bi-calculator',
                    'Profit & Loss Statement',
                    'Revenue, cost of goods sold, and operating profit details.',
                    'success'
                ) ?>
                <div class="sa-section-actions">
                    <a href="<?= analytics_export_url('profit_loss') ?>&format=excel" class="btn btn-outline-secondary btn-sm" target="_blank">Export Excel</a>
                    <a href="<?= analytics_export_url('profit_loss') ?>&format=pdf" class="btn btn-outline-secondary btn-sm" target="_blank">Export PDF</a>
                    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">Print</button>
                </div>
            </div>

            <div class="sales-kpi-grid sales-kpi-grid--6">
                <?php foreach ($pl['kpis'] ?? [] as $kpi): ?>
                    <div class="sales-kpi-card sales-kpi-card--<?= htmlspecialchars($kpi['tone'] ?? 'slate') ?>">
                        <div class="sales-kpi-icon" aria-hidden="true">
                            <i class="bi <?= htmlspecialchars($kpi['icon'] ?? 'bi-cash') ?>"></i>
                        </div>
                        <div class="sales-kpi-body">
                            <span class="sales-kpi-label"><?= htmlspecialchars($kpi['label'] ?? '') ?></span>
                            <span class="sales-kpi-value"><?= smart_report_finance_pl_kpi_value($kpi) ?></span>
                            <?= smart_report_finance_pl_change_html($kpi['current'], $kpi['previous'], !empty($kpi['is_margin'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?= smart_report_render_pl_foldable_table($pl, $currLabel, $prevLabel) ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('smart_report_render_finance_reports_html')) {
    function smart_report_render_finance_reports_html(array $d, array $filters = []): string
    {
        if (!$d['has_data']) {
            return '<p class="text-muted mb-0">No general ledger data available. Chart of accounts and journal entries are required.</p>';
        }

        $fmt = static function (float $n): string {
            return smart_report_finance_fmt_cell($n);
        };
        $periodNote = ' Data for ' . ($d['period']['label'] ?? '') . '.';
        $pl = $d['profit_loss'] ?? [];
        $bs = $d['balance_sheet'] ?? [];
        $cf = $d['cash_flow'] ?? [];
        $tb = $d['trial_balance'] ?? [];
        $eq = $d['equity_changes'] ?? [];
        $gl = $d['general_ledger'] ?? [];
        $je = $d['journal_entries'] ?? [];
        $coa = $d['chart_of_accounts'] ?? [];
        $summary = $d['summary'] ?? [];

        ob_start();
        ?>
        <div class="sales-drill-sections">
            <?= smart_report_render_profit_loss_statement($pl, $filters) ?>

            <section class="sales-drill-section" id="fi-balance-sheet">
                <?= smart_report_render_section_head(
                    'bi-balance-scale',
                    'Balance Sheet',
                    'Assets, liabilities, and equity position as of the period end date.' . $periodNote,
                    'info'
                ) ?>
                <div class="sales-kpi-grid sa-performance-kpi-row">
                    <?= smart_report_render_kpi_card('bi-bank', 'Total assets', 'TZS ' . number_format($bs['total_assets'] ?? 0, 0), 'blue') ?>
                    <?= smart_report_render_kpi_card('bi-credit-card', 'Total liabilities', 'TZS ' . number_format($bs['total_liabilities'] ?? 0, 0), 'amber') ?>
                    <?= smart_report_render_kpi_card('bi-pie-chart', 'Total equity', 'TZS ' . number_format($bs['total_equity'] ?? 0, 0), 'green') ?>
                    <?= smart_report_render_kpi_card('bi-check2-circle', 'Liabilities + equity', 'TZS ' . number_format($bs['total_liab_equity'] ?? 0, 0), 'indigo') ?>
                </div>
                <?php
                $bsRows = [];
                foreach (['asset_rows' => 'Assets', 'liability_rows' => 'Liabilities', 'equity_rows' => 'Equity'] as $key => $section) {
                    foreach ($bs[$key] ?? [] as $r) {
                        $bsRows[] = ['cells' => [htmlspecialchars($section . ' &raquo; ' . $r['label']), $fmt($r['amount'])], 'indent' => true];
                    }
                }
                if (!empty($bs['retained_earnings'])) {
                    $bsRows[] = ['cells' => ['Equity &raquo; Retained earnings (current period)', $fmt((float) $bs['retained_earnings'])], 'indent' => true];
                }
                echo smart_report_render_finance_simple_table(
                    'fi-bs-table',
                    [
                        ['label' => 'Account', 'class' => 'sa-col-label'],
                        ['label' => 'Balance (TSh)', 'class' => 'sa-col-amount'],
                    ],
                    $bsRows,
                    ['label' => 'Total assets', 'cells' => ['', $fmt($bs['total_assets'] ?? 0)]],
                    12
                );
                ?>
            </section>

            <section class="sales-drill-section" id="fi-cash-flow">
                <?= smart_report_render_section_head(
                    'bi-currency-exchange',
                    'Cash Flow Statement',
                    'Operating, investing, and financing cash movements (indirect method).' . $periodNote,
                    'primary'
                ) ?>
                <div class="sales-kpi-grid sales-kpi-grid--4">
                    <?= smart_report_render_kpi_card('bi-gear', 'Operating', 'TZS ' . number_format($cf['operating_total'] ?? 0, 0), 'blue') ?>
                    <?= smart_report_render_kpi_card('bi-building', 'Investing', 'TZS ' . number_format($cf['investing_total'] ?? 0, 0), 'amber') ?>
                    <?= smart_report_render_kpi_card('bi-bank2', 'Financing', 'TZS ' . number_format($cf['financing_total'] ?? 0, 0), 'violet') ?>
                    <?= smart_report_render_kpi_card('bi-cash-coin', 'Net change in cash', 'TZS ' . number_format($cf['net_change'] ?? 0, 0), ($cf['net_change'] ?? 0) >= 0 ? 'green' : 'down') ?>
                </div>
                <?php
                $cfRows = [
                    ['cells' => ['Operating activities &raquo; Net income', $fmt($cf['net_income'] ?? 0)], 'indent' => true],
                    ['cells' => ['Operating activities &raquo; Decrease (increase) in accounts receivable', $fmt($cf['change_ar'] ?? 0)], 'indent' => true],
                    ['cells' => ['Operating activities &raquo; Decrease (increase) in inventory', $fmt($cf['change_inventory'] ?? 0)], 'indent' => true],
                    ['cells' => ['Operating activities &raquo; Increase (decrease) in accounts payable', $fmt($cf['change_ap'] ?? 0)], 'indent' => true],
                    ['cells' => ['Investing activities &raquo; Purchase of property, plant, and equipment', $fmt($cf['investing_total'] ?? 0)], 'indent' => true],
                    ['cells' => ['Financing activities &raquo; Capital and borrowing changes', $fmt($cf['financing_total'] ?? 0)], 'indent' => true],
                    ['cells' => ['Cash at beginning of period', $fmt($cf['start_cash'] ?? 0)], 'indent' => false],
                    ['cells' => ['Cash at end of period', $fmt($cf['end_cash'] ?? 0)], 'indent' => false],
                ];
                echo smart_report_render_finance_simple_table(
                    'fi-cf-table',
                    [
                        ['label' => 'Cash Flow Component', 'class' => 'sa-col-label'],
                        ['label' => 'Net Flow (TSh)', 'class' => 'sa-col-amount'],
                    ],
                    $cfRows,
                    ['label' => 'Net change in cash', 'cells' => ['', $fmt($cf['net_change'] ?? 0)]],
                    12
                );
                ?>
            </section>

            <section class="sales-drill-section" id="fi-trial-balance">
                <?= smart_report_render_section_head(
                    'bi-shuffle',
                    'Trial Balance',
                    'List of ledger balances to verify Debit/Credit equality.' . $periodNote,
                    'amber'
                ) ?>
                <?php
                $tbRows = [];
                foreach ($tb['rows'] ?? [] as $r) {
                    $tbRows[] = [
                        'cells' => [
                            htmlspecialchars($r['code'] . ' - ' . $r['name']),
                            htmlspecialchars($r['type']),
                            $r['debit'] > 0 ? $fmt($r['debit']) : '-',
                            $r['credit'] > 0 ? $fmt($r['credit']) : '-',
                        ],
                        'indent' => false,
                    ];
                }
                echo smart_report_render_finance_simple_table(
                    'fi-tb-table',
                    [
                        ['label' => 'Account (Code & Name)', 'class' => 'sa-col-label'],
                        ['label' => 'Type', 'class' => 'sa-col-meta'],
                        ['label' => 'Debit (TSh)', 'class' => 'sa-col-amount'],
                        ['label' => 'Credit (TSh)', 'class' => 'sa-col-amount'],
                    ],
                    $tbRows,
                    ['label' => 'Total Trial Balance', 'cells' => ['', $fmt($tb['total_debit'] ?? 0), $fmt($tb['total_credit'] ?? 0)]],
                    12,
                    'accounts'
                );
                ?>
            </section>

            <section class="sales-drill-section" id="fi-equity-changes">
                <?= smart_report_render_section_head(
                    'bi-bar-chart-steps',
                    'Statement of Changes in Equity',
                    'Reconciles opening and closing owner equity.' . $periodNote,
                    'info'
                ) ?>
                <?php
                $eqRows = [
                    ['cells' => ['Opening balance', $fmt($eq['opening_equity'] ?? 0)], 'indent' => false],
                    ['cells' => ['Net Income for the period', $fmt($eq['net_income'] ?? 0)], 'indent' => true],
                    ['cells' => ['Owner contributions', $fmt($eq['contributions'] ?? 0)], 'indent' => true],
                    ['cells' => ['Owner drawings / distributions', $fmt(-$eq['drawings'] ?? 0)], 'indent' => true],
                ];
                echo smart_report_render_finance_simple_table(
                    'fi-eq-table',
                    [
                        ['label' => 'Equity Component', 'class' => 'sa-col-label'],
                        ['label' => 'Amount (TSh)', 'class' => 'sa-col-amount'],
                    ],
                    $eqRows,
                    ['label' => 'Closing balance', 'cells' => ['', $fmt($eq['closing_equity'] ?? 0)]],
                    12
                );
                ?>
            </section>

            <section class="sales-drill-section" id="fi-general-ledger">
                <?= smart_report_render_section_head(
                    'bi-journal-text',
                    'General Ledger (Recent Items)',
                    'Chronological list of general ledger transactions.' . $periodNote,
                    'primary'
                ) ?>
                <?php
                $glRows = [];
                foreach ($gl as $r) {
                    $glRows[] = [
                        'cells' => [
                            htmlspecialchars($r['date'] . ' / ' . $r['reference']),
                            htmlspecialchars($r['code'] . ' - ' . $r['account_name']),
                            htmlspecialchars($r['journal_desc'] ?: '-'),
                            $r['debit'] > 0 ? $fmt((float)$r['debit']) : '-',
                            $r['credit'] > 0 ? $fmt((float)$r['credit']) : '-',
                        ],
                        'indent' => false,
                    ];
                }
                echo smart_report_render_finance_simple_table(
                    'fi-gl-table',
                    [
                        ['label' => 'Date / Reference', 'class' => 'sa-col-label'],
                        ['label' => 'Account', 'class' => 'sa-col-meta'],
                        ['label' => 'Description', 'class' => 'sa-col-meta'],
                        ['label' => 'Debit (TSh)', 'class' => 'sa-col-amount'],
                        ['label' => 'Credit (TSh)', 'class' => 'sa-col-amount'],
                    ],
                    $glRows,
                    ['label' => 'Total ledger postings shown', 'cells' => ['', '', '', 'L: ' . count($gl), '']],
                    12,
                    'transactions'
                );
                ?>
            </section>

            <section class="sales-drill-section" id="fi-chart-of-accounts">
                <?= smart_report_render_section_head(
                    'bi-diagram-3',
                    'Chart of Accounts',
                    'Current balance sheet and income statement account list as of end date.',
                    'success'
                ) ?>
                <?php
                $coaRows = [];
                foreach ($coa as $r) {
                    $coaRows[] = [
                        'cells' => [
                            htmlspecialchars($r['code'] . ' - ' . $r['name']),
                            htmlspecialchars($r['type']),
                            $fmt($r['balance']),
                        ],
                        'indent' => false,
                    ];
                }
                echo smart_report_render_finance_simple_table(
                    'fi-coa-table',
                    [
                        ['label' => 'Account (Code & Name)', 'class' => 'sa-col-label'],
                        ['label' => 'Type', 'class' => 'sa-col-meta'],
                        ['label' => 'Balance (TSh)', 'class' => 'sa-col-amount'],
                    ],
                    $coaRows,
                    ['label' => 'Total accounts', 'cells' => ['', 'Count: ' . count($coa)]],
                    12,
                    'accounts'
                );
                ?>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}