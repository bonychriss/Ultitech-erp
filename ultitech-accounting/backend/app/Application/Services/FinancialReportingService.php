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

class FinancialReportingService
{
    public function __construct(private readonly AccountBalanceService $balances) {}

    public function profitAndLoss(int $companyId, int $bookId, string $fromDate, string $toDate, ?string $compareFrom = null, ?string $compareTo = null): array
    {
        $current = $this->periodByClassification($companyId, $bookId, $fromDate, $toDate, ['revenue', 'expense']);
        $comparative = ($compareFrom && $compareTo)
            ? $this->periodByClassification($companyId, $bookId, $compareFrom, $compareTo, ['revenue', 'expense'])
            : null;

        $revenue = $current->get('revenue', collect());
        $expenses = $current->get('expense', collect());
        $totalRevenue = $this->sumNetCredit($revenue);
        $totalExpenses = $this->sumNetDebit($expenses);
        $netProfit = Money::normalize(bcsub($totalRevenue, $totalExpenses, 4));

        $result = [
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'revenue' => $revenue->values(),
            'expenses' => $expenses->values(),
            'totals' => [
                'revenue' => $totalRevenue,
                'expenses' => $totalExpenses,
                'net_profit' => $netProfit,
            ],
        ];

        if ($comparative) {
            $cRevenue = $comparative->get('revenue', collect());
            $cExpenses = $comparative->get('expense', collect());
            $cTotalRevenue = $this->sumNetCredit($cRevenue);
            $cTotalExpenses = $this->sumNetDebit($cExpenses);
            $result['comparative'] = [
                'period' => ['from' => $compareFrom, 'to' => $compareTo],
                'totals' => [
                    'revenue' => $cTotalRevenue,
                    'expenses' => $cTotalExpenses,
                    'net_profit' => Money::normalize(bcsub($cTotalRevenue, $cTotalExpenses, 4)),
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
