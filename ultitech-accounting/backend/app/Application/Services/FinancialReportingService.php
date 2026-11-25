<?php

namespace App\Application\Services;

use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\AccountType;
use App\Models\BankTransaction;
use App\Models\JournalLine;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialReportingService
{
    public function __construct(private readonly AccountBalanceService $balances) {}

    public function profitAndLoss(
        int $companyId,
        int $bookId,
        string $fromDate,
        string $toDate,
        ?string $compareFrom = null,
        ?string $compareTo = null,
        string $columns = 'total',
    ): array {
        $columns = in_array($columns, ['total', 'quarter', 'month'], true) ? $columns : 'total';

        $current = $this->periodByClassification($companyId, $bookId, $fromDate, $toDate, ['revenue', 'expense']);
        $comparative = ($compareFrom && $compareTo)
            ? $this->periodByClassification($companyId, $bookId, $compareFrom, $compareTo, ['revenue', 'expense'])
            : null;

        $revenue = $current->get('revenue', collect());
        $expenses = $current->get('expense', collect());
        [$cogs, $operating] = $this->splitExpenses($companyId, $bookId, $expenses);

        $totalRevenue = $this->sumNetCredit($revenue);
        $totalCogs = $this->sumNetDebit($cogs);
        $totalOperating = $this->sumNetDebit($operating);
        $totalExpenses = Money::add($totalCogs, $totalOperating);
        $grossProfit = Money::normalize(bcsub($totalRevenue, $totalCogs, 4));
        $netProfit = Money::normalize(bcsub($totalRevenue, $totalExpenses, 4));

        $result = [
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'columns_mode' => $columns,
            'columns' => [['key' => 'total', 'label' => 'Total']],
            // Backward-compatible flat lists
            'revenue' => $revenue->values(),
            'expenses' => $expenses->values(),
            // Structured P&L sections (FreshBooks-style)
            'income' => $revenue->values(),
            'cogs' => $cogs->values(),
            'operating_expenses' => $operating->values(),
            'totals' => [
                'revenue' => $totalRevenue,
                'income' => $totalRevenue,
                'cogs' => $totalCogs,
                'gross_profit' => $grossProfit,
                'gross_profit_margin' => $this->marginPercent($grossProfit, $totalRevenue),
                'expenses' => $totalExpenses,
                'operating_expenses' => $totalOperating,
                'net_profit' => $netProfit,
                'net_profit_margin' => $this->marginPercent($netProfit, $totalRevenue),
            ],
        ];

        if ($columns === 'quarter' || $columns === 'month') {
            $periods = $columns === 'month'
                ? $this->monthRanges($fromDate, $toDate)
                : $this->quarterRanges($fromDate, $toDate);
            $result = $this->attachPeriodColumns($result, $companyId, $bookId, $periods);
        }

        if ($comparative) {
            $cRevenue = $comparative->get('revenue', collect());
            $cExpenses = $comparative->get('expense', collect());
            [$cCogs, $cOperating] = $this->splitExpenses($companyId, $bookId, $cExpenses);
            $cTotalRevenue = $this->sumNetCredit($cRevenue);
            $cTotalCogs = $this->sumNetDebit($cCogs);
            $cTotalOperating = $this->sumNetDebit($cOperating);
            $cTotalExpenses = Money::add($cTotalCogs, $cTotalOperating);
            $cGross = Money::normalize(bcsub($cTotalRevenue, $cTotalCogs, 4));
            $cNet = Money::normalize(bcsub($cTotalRevenue, $cTotalExpenses, 4));
            $result['comparative'] = [
                'period' => ['from' => $compareFrom, 'to' => $compareTo],
                'totals' => [
                    'revenue' => $cTotalRevenue,
                    'income' => $cTotalRevenue,
                    'cogs' => $cTotalCogs,
                    'gross_profit' => $cGross,
                    'gross_profit_margin' => $this->marginPercent($cGross, $cTotalRevenue),
                    'expenses' => $cTotalExpenses,
                    'operating_expenses' => $cTotalOperating,
                    'net_profit' => $cNet,
                    'net_profit_margin' => $this->marginPercent($cNet, $cTotalRevenue),
                ],
            ];
        }

        return $result;
    }

    public function balanceSheet(int $companyId, int $bookId, string $asOfDate, ?string $compareAsOf = null): array
    {
        $current = $this->buildBalanceSheet($companyId, $bookId, $asOfDate);
        $result = [
            'as_of' => $asOfDate,
            ...$current,
        ];

        if ($compareAsOf) {
            $result['comparative'] = [
                'as_of' => $compareAsOf,
                ...$this->buildBalanceSheet($companyId, $bookId, $compareAsOf),
            ];
        }

        return $result;
    }

    public function cashFlow(int $companyId, int $bookId, string $fromDate, string $toDate): array
    {
        $cashAccountIds = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->whereIn('code', ['1000', '1100'])
            ->pluck('id');

        $mappings = DB::table('acc_account_mappings')
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->whereIn('mapping_key', ['cash', 'bank'])
            ->pluck('account_id');

        $cashIds = $cashAccountIds->merge($mappings)->unique()->values();

        $opening = $this->cashBalanceAsOf($companyId, $bookId, $cashIds, date('Y-m-d', strtotime($fromDate.' -1 day')));
        $closing = $this->cashBalanceAsOf($companyId, $bookId, $cashIds, $toDate);

        $bankMovements = BankTransaction::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('transaction_date', '>=', $fromDate)
            ->whereDate('transaction_date', '<=', $toDate)
            ->get();

        $receipts = $bankMovements->reduce(fn ($c, $t) => Money::add($c, Money::normalize($t->debit)), '0.0000');
        $payments = $bankMovements->reduce(fn ($c, $t) => Money::add($c, Money::normalize($t->credit)), '0.0000');

        $pl = $this->profitAndLoss($companyId, $bookId, $fromDate, $toDate);
        $netProfit = $pl['totals']['net_profit'];

        // Practical indirect-lite cash flow for M03
        $operating = Money::normalize(bcsub($receipts, $payments, 4));
        $netChange = Money::normalize(bcsub($closing, $opening, 4));

        return [
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'operating_activities' => [
                'net_profit' => $netProfit,
                'customer_receipts' => $receipts,
                'supplier_and_other_payments' => $payments,
                'net_cash_from_operating' => $operating,
            ],
            'investing_activities' => [
                'net_cash_from_investing' => '0.0000',
            ],
            'financing_activities' => [
                'net_cash_from_financing' => '0.0000',
            ],
            'cash_reconciliation' => [
                'opening_cash' => $opening,
                'net_increase' => $netChange,
                'closing_cash' => $closing,
                'reconciled' => Money::equals(
                    Money::add($opening, $netChange),
                    $closing
                ),
            ],
        ];
    }

    public function changesInEquity(int $companyId, int $bookId, string $fromDate, string $toDate): array
    {
        $dayBefore = date('Y-m-d', strtotime($fromDate.' -1 day'));
        $openingSheet = $this->buildBalanceSheet($companyId, $bookId, $dayBefore);
        $closingSheet = $this->buildBalanceSheet($companyId, $bookId, $toDate);
        $pl = $this->profitAndLoss($companyId, $bookId, $fromDate, $toDate);

        $openingEquity = $openingSheet['totals']['equity'];
        $netProfit = $pl['totals']['net_profit'];
        $closingEquity = $closingSheet['totals']['equity'];

        return [
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'opening_equity' => $openingEquity,
            'net_profit' => $netProfit,
            'other_movements' => Money::normalize(
                bcsub(bcsub($closingEquity, $openingEquity, 4), $netProfit, 4)
            ),
            'closing_equity' => $closingEquity,
            'lines' => $closingSheet['equity'],
        ];
    }

    public function generalLedger(int $companyId, int $bookId, ?int $accountId, string $fromDate, string $toDate): array
    {
        $query = JournalLine::withoutGlobalScopes()
            ->join('acc_journal_entries', 'acc_journal_entries.id', '=', 'acc_journal_lines.journal_id')
            ->join('acc_accounts', 'acc_accounts.id', '=', 'acc_journal_lines.account_id')
            ->where('acc_journal_lines.company_id', $companyId)
            ->where('acc_journal_lines.book_id', $bookId)
            ->whereIn('acc_journal_entries.status', ['posted', 'reversed'])
            ->whereDate('acc_journal_entries.journal_date', '>=', $fromDate)
            ->whereDate('acc_journal_entries.journal_date', '<=', $toDate)
            ->orderBy('acc_journal_entries.journal_date')
            ->orderBy('acc_journal_entries.id')
            ->orderBy('acc_journal_lines.line_no');

        if ($accountId) {
            $query->where('acc_journal_lines.account_id', $accountId);
        }

        $rows = $query->get([
            'acc_journal_lines.*',
            'acc_journal_entries.journal_number',
            'acc_journal_entries.journal_date',
            'acc_journal_entries.description as journal_description',
            'acc_accounts.code as account_code',
            'acc_accounts.name as account_name',
        ]);

        $opening = '0.0000';
        if ($accountId) {
            $asOf = date('Y-m-d', strtotime($fromDate.' -1 day'));
            $tb = $this->balances->trialBalance($companyId, $bookId, $asOf)
                ->firstWhere('account_id', $accountId);
            if ($tb) {
                $opening = Money::normalize(bcsub($tb['debit'], $tb['credit'], 4));
            }
        }

        $running = $opening;
        $data = $rows->map(function ($row) use (&$running) {
            $debit = Money::normalize($row->functional_debit);
            $credit = Money::normalize($row->functional_credit);
            $running = Money::normalize(bcadd(bcsub($running, $credit, 4), $debit, 4));

            return [
                'journal_id' => $row->journal_id,
                'journal_number' => $row->journal_number,
                'journal_date' => $row->journal_date,
                'account_id' => $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'description' => $row->description ?: $row->journal_description,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
            ];
        })->values();

        return [
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'account_id' => $accountId,
            'opening_balance' => $opening,
            'closing_balance' => $running,
            'data' => $data,
        ];
    }

    private function buildBalanceSheet(int $companyId, int $bookId, string $asOfDate): array
    {
        $tb = $this->balances->trialBalance($companyId, $bookId, $asOfDate);
        $accounts = Account::withoutGlobalScopes()
            ->with('accountType')
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->whereIn('id', $tb->pluck('account_id'))
            ->get()
            ->keyBy('id');

        $assets = collect();
        $liabilities = collect();
        $equity = collect();
        $revenueNet = '0.0000';
        $expenseNet = '0.0000';

        foreach ($tb as $row) {
            $account = $accounts->get($row['account_id']);
            if (! $account || ! $account->accountType) {
                continue;
            }

            $type = $account->accountType->code;
            $netDebit = Money::normalize(bcsub($row['debit'], $row['credit'], 4));
            $netCredit = Money::normalize(bcsub($row['credit'], $row['debit'], 4));

            $line = [
                'account_id' => $account->id,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'amount' => in_array($type, ['asset', 'expense'], true) ? $netDebit : $netCredit,
            ];

            if ($type === 'asset') {
                $assets->push($line);
            } elseif ($type === 'liability') {
                $liabilities->push($line);
            } elseif ($type === 'equity') {
                $equity->push($line);
            } elseif ($type === 'revenue') {
                $revenueNet = Money::add($revenueNet, $netCredit);
            } elseif ($type === 'expense') {
                $expenseNet = Money::add($expenseNet, $netDebit);
            }
        }

        $retainedCurrent = Money::normalize(bcsub($revenueNet, $expenseNet, 4));
        if (! Money::isZero($retainedCurrent)) {
            $equity->push([
                'account_id' => 0,
                'account_code' => 'NP',
                'account_name' => 'Current period net profit/(loss)',
                'amount' => $retainedCurrent,
            ]);
        }

        $totalAssets = $assets->reduce(fn ($c, $r) => Money::add($c, $r['amount']), '0.0000');
        $totalLiabilities = $liabilities->reduce(fn ($c, $r) => Money::add($c, $r['amount']), '0.0000');
        $totalEquity = $equity->reduce(fn ($c, $r) => Money::add($c, $r['amount']), '0.0000');
        $liabilitiesAndEquity = Money::add($totalLiabilities, $totalEquity);

        return [
            'assets' => $assets->values(),
            'liabilities' => $liabilities->values(),
            'equity' => $equity->values(),
            'totals' => [
                'assets' => $totalAssets,
                'liabilities' => $totalLiabilities,
                'equity' => $totalEquity,
                'liabilities_and_equity' => $liabilitiesAndEquity,
                'balanced' => Money::equals($totalAssets, $liabilitiesAndEquity),
            ],
        ];
    }

    private function periodByClassification(int $companyId, int $bookId, string $from, string $to, array $types): Collection
    {
        $typeIds = AccountType::query()->whereIn('code', $types)->pluck('id', 'code');

        $rows = JournalLine::withoutGlobalScopes()
            ->join('acc_journal_entries', 'acc_journal_entries.id', '=', 'acc_journal_lines.journal_id')
            ->join('acc_accounts', 'acc_accounts.id', '=', 'acc_journal_lines.account_id')
            ->where('acc_journal_lines.company_id', $companyId)
            ->where('acc_journal_lines.book_id', $bookId)
            ->whereIn('acc_journal_entries.status', ['posted', 'reversed'])
            ->whereDate('acc_journal_entries.journal_date', '>=', $from)
            ->whereDate('acc_journal_entries.journal_date', '<=', $to)
            ->whereIn('acc_accounts.account_type_id', $typeIds->values())
            ->select(
                'acc_accounts.id as account_id',
                'acc_accounts.code as account_code',
                'acc_accounts.name as account_name',
                'acc_accounts.account_type_id',
                DB::raw('SUM(acc_journal_lines.functional_debit) as debit'),
                DB::raw('SUM(acc_journal_lines.functional_credit) as credit')
            )
            ->groupBy('acc_accounts.id', 'acc_accounts.code', 'acc_accounts.name', 'acc_accounts.account_type_id')
            ->get();

        $grouped = collect(['revenue' => collect(), 'expense' => collect()]);
        $idToCode = $typeIds->flip();

        foreach ($rows as $row) {
            $code = $idToCode[$row->account_type_id] ?? null;
            if (! $code) {
                continue;
            }
            $debit = Money::normalize($row->debit);
            $credit = Money::normalize($row->credit);
            $amount = $code === 'revenue'
                ? Money::normalize(bcsub($credit, $debit, 4))
                : Money::normalize(bcsub($debit, $credit, 4));

            $grouped[$code]->push([
                'account_id' => (int) $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'amount' => $amount,
            ]);
        }

        return $grouped;
    }

    private function sumNetCredit(Collection $rows): string
    {
        return $rows->reduce(fn ($c, $r) => Money::add($c, $r['amount']), '0.0000');
    }

    private function sumNetDebit(Collection $rows): string
    {
        return $rows->reduce(fn ($c, $r) => Money::add($c, $r['amount']), '0.0000');
    }

    /**
     * Split expense lines into COGS vs operating expenses.
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function splitExpenses(int $companyId, int $bookId, Collection $expenses): array
    {
        $cogsAccountIds = [];
        if (Schema::hasTable('acc_inventory_items')) {
            $cogsAccountIds = DB::table('acc_inventory_items')
                ->where('company_id', $companyId)
                ->where('book_id', $bookId)
                ->whereNotNull('cogs_account_id')
                ->pluck('cogs_account_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();
        }

        $cogs = collect();
        $operating = collect();

        foreach ($expenses as $row) {
            if ($this->isCogsAccount($row, $cogsAccountIds)) {
                $cogs->push($row);
            } else {
                $operating->push($row);
            }
        }

        return [$cogs, $operating];
    }

    private function isCogsAccount(array $row, array $cogsAccountIds): bool
    {
        $accountId = (int) ($row['account_id'] ?? 0);
        if ($accountId && in_array($accountId, $cogsAccountIds, true)) {
            return true;
        }

        $code = strtoupper(trim((string) ($row['account_code'] ?? '')));
        if ($code !== '' && str_starts_with($code, '53')) {
            return true;
        }

        $name = strtolower((string) ($row['account_name'] ?? ''));
        if ($name !== '' && (str_contains($name, 'cost of goods') || str_contains($name, 'cogs'))) {
            return true;
        }

        return false;
    }

    private function marginPercent(string $numerator, string $denominator): string
    {
        if (Money::isZero($denominator)) {
            return '0.00';
        }

        return number_format((float) bcmul(bcdiv($numerator, $denominator, 8), '100', 4), 2, '.', '');
    }

    /**
     * @param  list<array{key: string, label: string, from: string, to: string}>  $periods
     */
    private function attachPeriodColumns(array $result, int $companyId, int $bookId, array $periods): array
    {
        if ($periods === []) {
            return $result;
        }

        $columnDefs = [];
        foreach ($periods as $period) {
            $columnDefs[] = [
                'key' => $period['key'],
                'label' => $period['label'],
                'from' => $period['from'],
                'to' => $period['to'],
            ];
        }
        $columnDefs[] = ['key' => 'total', 'label' => 'TOTAL'];

        $periodMaps = [];
        foreach ($periods as $period) {
            $periodMaps[$period['key']] = $this->periodByClassification(
                $companyId,
                $bookId,
                $period['from'],
                $period['to'],
                ['revenue', 'expense']
            );
        }

        $enrich = function (Collection $rows, string $classification) use ($periodMaps, $periods): Collection {
            return $rows->map(function (array $row) use ($periodMaps, $periods, $classification) {
                $amounts = [];
                foreach ($periods as $period) {
                    $bucket = $periodMaps[$period['key']]->get($classification, collect());
                    $match = $bucket->firstWhere('account_id', $row['account_id']);
                    $amounts[$period['key']] = $match['amount'] ?? '0.0000';
                }
                $amounts['total'] = $row['amount'];
                $row['amounts'] = $amounts;

                return $row;
            })->values();
        };

        $income = $enrich(collect($result['income']), 'revenue');
        $allExpenses = collect($result['expenses']);
        [$cogsFlat, $operatingFlat] = $this->splitExpenses($companyId, $bookId, $allExpenses);
        $cogs = $enrich($cogsFlat, 'expense');
        $operating = $enrich($operatingFlat, 'expense');
        $expenses = $enrich($allExpenses, 'expense');
        $revenue = $enrich(collect($result['revenue']), 'revenue');

        $sumSection = function (Collection $rows) use ($periods): array {
            $sums = ['total' => '0.0000'];
            foreach ($periods as $period) {
                $sums[$period['key']] = '0.0000';
            }
            foreach ($rows as $row) {
                foreach ($sums as $key => $_) {
                    $sums[$key] = Money::add($sums[$key], $row['amounts'][$key] ?? '0.0000');
                }
            }

            return $sums;
        };

        $incomeTotals = $sumSection($income);
        $cogsTotals = $sumSection($cogs);
        $operatingTotals = $sumSection($operating);
        $expenseTotals = $sumSection($expenses);

        $grossByCol = [];
        $netByCol = [];
        $grossMarginByCol = [];
        $netMarginByCol = [];
        foreach (array_merge(array_column($periods, 'key'), ['total']) as $key) {
            $inc = $incomeTotals[$key] ?? '0.0000';
            $cg = $cogsTotals[$key] ?? '0.0000';
            $exp = $expenseTotals[$key] ?? '0.0000';
            $gross = Money::normalize(bcsub($inc, $cg, 4));
            $net = Money::normalize(bcsub($inc, $exp, 4));
            $grossByCol[$key] = $gross;
            $netByCol[$key] = $net;
            $grossMarginByCol[$key] = $this->marginPercent($gross, $inc);
            $netMarginByCol[$key] = $this->marginPercent($net, $inc);
        }

        $result['columns'] = $columnDefs;
        $result['income'] = $income;
        $result['cogs'] = $cogs;
        $result['operating_expenses'] = $operating;
        $result['revenue'] = $revenue;
        $result['expenses'] = $expenses;
        $result['totals']['income_by_column'] = $incomeTotals;
        $result['totals']['cogs_by_column'] = $cogsTotals;
        $result['totals']['operating_expenses_by_column'] = $operatingTotals;
        $result['totals']['expenses_by_column'] = $expenseTotals;
        $result['totals']['gross_profit_by_column'] = $grossByCol;
        $result['totals']['net_profit_by_column'] = $netByCol;
        $result['totals']['gross_profit_margin_by_column'] = $grossMarginByCol;
        $result['totals']['net_profit_margin_by_column'] = $netMarginByCol;

        return $result;
    }

    /**
     * @return list<array{key: string, label: string, from: string, to: string}>
     */
    private function monthRanges(string $fromDate, string $toDate): array
    {
        $from = new \DateTimeImmutable($fromDate);
        $to = new \DateTimeImmutable($toDate);
        if ($from > $to) {
            return [];
        }

        $cursor = $from->modify('first day of this month');
        $end = $to->modify('first day of this month');
        $months = [];

        while ($cursor <= $end) {
            $monthStart = $cursor;
            $monthEnd = $cursor->modify('last day of this month');
            $clippedFrom = $monthStart < $from ? $from : $monthStart;
            $clippedTo = $monthEnd > $to ? $to : $monthEnd;
            $months[] = [
                'key' => 'm'.$cursor->format('Ym'),
                'label' => strtoupper($cursor->format('M Y')),
                'from' => $clippedFrom->format('Y-m-d'),
                'to' => $clippedTo->format('Y-m-d'),
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    /**
     * @return list<array{key: string, label: string, from: string, to: string}>
     */
    private function quarterRanges(string $fromDate, string $toDate): array
    {
        $from = new \DateTimeImmutable($fromDate);
        $to = new \DateTimeImmutable($toDate);
        if ($from > $to) {
            return [];
        }

        $year = (int) $from->format('Y');
        $endYear = (int) $to->format('Y');
        $quarters = [];

        for ($y = $year; $y <= $endYear; $y++) {
            $defs = [
                ['Q1', sprintf('%04d-01-01', $y), sprintf('%04d-03-31', $y), 'JAN '.$y.'-MAR '.$y],
                ['Q2', sprintf('%04d-04-01', $y), sprintf('%04d-06-30', $y), 'APR '.$y.'-JUN '.$y],
                ['Q3', sprintf('%04d-07-01', $y), sprintf('%04d-09-30', $y), 'JUL '.$y.'-SEP '.$y],
                ['Q4', sprintf('%04d-10-01', $y), sprintf('%04d-12-31', $y), 'OCT '.$y.'-DEC '.$y],
            ];
            foreach ($defs as [$key, $qFrom, $qTo, $label]) {
                $qf = new \DateTimeImmutable($qFrom);
                $qt = new \DateTimeImmutable($qTo);
                if ($qt < $from || $qf > $to) {
                    continue;
                }
                $clippedFrom = $qf < $from ? $from->format('Y-m-d') : $qFrom;
                $clippedTo = $qt > $to ? $to->format('Y-m-d') : $qTo;
                $quarters[] = [
                    'key' => $y === $year && $endYear === $year ? strtolower($key) : strtolower($key).'_'.$y,
                    'label' => $label,
                    'from' => $clippedFrom,
                    'to' => $clippedTo,
                ];
            }
        }

        return $quarters;
    }

    private function cashBalanceAsOf(int $companyId, int $bookId, Collection $cashIds, string $asOf): string
    {
        if ($cashIds->isEmpty()) {
            return '0.0000';
        }

        $tb = $this->balances->trialBalance($companyId, $bookId, $asOf);
        $total = '0.0000';
        foreach ($tb as $row) {
            if ($cashIds->contains($row['account_id'])) {
                $total = Money::add($total, Money::normalize(bcsub($row['debit'], $row['credit'], 4)));
            }
        }

        return $total;
    }
}
