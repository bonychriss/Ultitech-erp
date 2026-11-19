<?php

namespace App\Application\Services;

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountingBook;
use App\Models\CloseChecklist;
use App\Models\CloseChecklistItem;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\PeriodClose;
use App\Models\User;
use App\Models\YearClose;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class YearCloseService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly JournalPostingService $posting,
        private readonly AuditTrailService $audit,
    ) {}

    public function buildChecklist(AccountingBook $book, User $user, int $fiscalYearId): CloseChecklist
    {
        $year = FiscalYear::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->whereKey($fiscalYearId)
            ->firstOrFail();

        $periods = FiscalPeriod::withoutGlobalScopes()
            ->where('fiscal_year_id', $year->id)
            ->orderBy('period_number')
            ->get();

        $openPeriods = $periods->where('status', '!=', 'closed')->count();
        $closedLocks = PeriodClose::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->whereIn('period_id', $periods->pluck('id'))
            ->count();

        $asOf = Carbon::parse($year->end_date)->toDateString();
        $tb = $this->balances->trialBalance((int) $book->company_id, (int) $book->id, $asOf);
        $debit = $tb->reduce(fn ($c, $r) => Money::add($c, $r['debit']), '0.0000');
        $credit = $tb->reduce(fn ($c, $r) => Money::add($c, $r['credit']), '0.0000');

        $checks = [
            [
                'code' => 'ALL_PERIODS_CLOSED',
                'label' => 'All fiscal periods are closed',
                'is_required' => true,
                'is_passed' => $openPeriods === 0,
                'details' => $openPeriods === 0 ? 'All periods closed' : "{$openPeriods} period(s) still open",
            ],
            [
                'code' => 'PERIOD_CLOSES_RECORDED',
                'label' => 'Month-end close records exist for all periods',
                'is_required' => true,
                'is_passed' => $closedLocks >= $periods->count() && $periods->count() > 0,
                'details' => "{$closedLocks}/{$periods->count()} period close records",
            ],
            [
                'code' => 'TRIAL_BALANCE_BALANCED',
                'label' => 'Trial balance balanced at year end',
                'is_required' => true,
                'is_passed' => Money::equals($debit, $credit),
                'details' => Money::equals($debit, $credit) ? 'Balanced' : "Debit {$debit} != Credit {$credit}",
            ],
        ];

        return DB::transaction(function () use ($book, $user, $year, $checks) {
            $checklist = CloseChecklist::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'close_type' => 'year_end',
                'fiscal_year_id' => $year->id,
                'status' => collect($checks)->contains(fn ($c) => $c['is_required'] && ! $c['is_passed']) ? 'blocked' : 'ready',
                'created_by' => $user->id,
            ]);

            foreach ($checks as $check) {
                CloseChecklistItem::query()->create([
                    'checklist_id' => $checklist->id,
                    'company_id' => $book->company_id,
                    ...$check,
                ]);
            }

            return $checklist->load('items');
        });
    }

    public function close(AccountingBook $book, User $user, int $fiscalYearId, ?string $notes = null): YearClose
    {
        $year = FiscalYear::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->whereKey($fiscalYearId)
            ->firstOrFail();

        if ($year->status === 'closed') {
            throw ValidationException::withMessages(['fiscal_year_id' => 'Fiscal year is already closed.']);
        }

        $checklist = $this->buildChecklist($book, $user, $fiscalYearId);
        $failed = $checklist->items->where('is_required', true)->where('is_passed', false);
        if ($failed->isNotEmpty()) {
            throw ValidationException::withMessages([
                'checklist' => 'Year-end checklist failed: '.$failed->pluck('code')->implode(', '),
            ]);
        }

        $retainedId = $this->mapped((int) $book->company_id, (int) $book->id, 'retained_earnings');
        $lastPeriod = FiscalPeriod::withoutGlobalScopes()
            ->where('fiscal_year_id', $year->id)
            ->orderByDesc('period_number')
            ->firstOrFail();
        $asOf = Carbon::parse($lastPeriod->end_date)->toDateString();
        $tb = $this->balances->trialBalance((int) $book->company_id, (int) $book->id, $asOf);

        $revenueNet = '0.0000';
        $expenseNet = '0.0000';
        $lines = [];

        foreach ($tb as $row) {
            $account = Account::withoutGlobalScopes()->with('accountType')->find($row['account_id']);
            $type = $account?->accountType?->code;
            if (! in_array($type, ['revenue', 'expense'], true)) {
                continue;
            }

            $netDebit = Money::normalize(bcsub($row['debit'], $row['credit'], 4));
            if (Money::isZero($netDebit)) {
                continue;
            }

            if ($type === 'revenue') {
                // credit nature: credit - debit = income
                $income = Money::normalize(bcsub($row['credit'], $row['debit'], 4));
                $revenueNet = Money::add($revenueNet, $income);
                if (bccomp($income, '0', 4) > 0) {
                    $lines[] = [
                        'account_id' => (int) $account->id,
                        'debit' => $income,
                        'credit' => '0',
                        'description' => 'Close revenue '.$account->code,
                    ];
                }
            } else {
                $expense = Money::normalize(bcsub($row['debit'], $row['credit'], 4));
                $expenseNet = Money::add($expenseNet, $expense);
                if (bccomp($expense, '0', 4) > 0) {
                    $lines[] = [
                        'account_id' => (int) $account->id,
                        'debit' => '0',
                        'credit' => $expense,
                        'description' => 'Close expense '.$account->code,
                    ];
                }
            }
        }

        $netIncome = Money::normalize(bcsub($revenueNet, $expenseNet, 4));

        if (! empty($lines)) {
            if (bccomp($netIncome, '0', 4) >= 0) {
                $lines[] = [
                    'account_id' => $retainedId,
                    'debit' => '0',
                    'credit' => $netIncome,
                    'description' => 'Transfer net income to retained earnings',
                ];
            } else {
                $loss = Money::normalize(bcmul($netIncome, '-1', 4));
                $lines[] = [
                    'account_id' => $retainedId,
                    'debit' => $loss,
                    'credit' => '0',
                    'description' => 'Transfer net loss to retained earnings',
                ];
            }
        }

        return DB::transaction(function () use ($book, $user, $year, $checklist, $notes, $lines, $netIncome, $asOf) {
            $journalId = null;
            if (! empty($lines)) {
                // Year already has closed periods — posting would be blocked. Temporarily reopen last period lock? 
                // Better: post on year end date by unlocking last period briefly or use system bypass.
                $this->temporarilyAllowClosePosting($book, $year);

                $journal = $this->posting->createAndPost($book, $user, [
                    'journal_date' => $asOf,
                    'description' => 'Year-end closing '.$year->name,
                    'reference' => 'YE-'.$year->id,
                    'currency_code' => $book->functional_currency_code,
                    'source_type' => 'year_close',
                    'source_id_ref' => (string) $year->id,
                    'source_hash' => 'year_close:'.$book->id.':'.$year->id,
                    'lines' => $lines,
                ], 'system');
                $journalId = $journal->id;
                $this->recloseLastPeriod($book, $year, $user);
            }

            $year->update(['status' => 'closed']);
            $checklist->update(['status' => 'completed']);

            $close = YearClose::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'fiscal_year_id' => $year->id,
                'checklist_id' => $checklist->id,
                'closing_journal_id' => $journalId,
                'net_income' => $netIncome,
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $user->id,
                'notes' => $notes,
            ]);

            $this->audit->record('year.closed', 'year_close', $close->id, null, [
                'fiscal_year_id' => $year->id,
                'net_income' => $netIncome,
            ]);

            return $close;
        });
    }

    private function temporarilyAllowClosePosting(AccountingBook $book, FiscalYear $year): void
    {
        $lastPeriod = FiscalPeriod::withoutGlobalScopes()
            ->where('fiscal_year_id', $year->id)
            ->orderByDesc('period_number')
            ->first();

        if (! $lastPeriod) {
            return;
        }

        $lastPeriod->update(['status' => 'open']);

        \App\Models\PeriodLock::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('period_id', $lastPeriod->id)
            ->delete();
    }

    private function recloseLastPeriod(AccountingBook $book, FiscalYear $year, User $user): void
    {
        $lastPeriod = FiscalPeriod::withoutGlobalScopes()
            ->where('fiscal_year_id', $year->id)
            ->orderByDesc('period_number')
            ->first();

        if (! $lastPeriod) {
            return;
        }

        $lastPeriod->update(['status' => 'closed']);

        \App\Models\PeriodLock::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'period_id' => $lastPeriod->id,
            ],
            [
                'locked_at' => now(),
                'locked_by' => $user->id,
                'reason' => 'Re-locked after year-end closing entry',
            ]
        );
    }

    private function mapped(int $companyId, int $bookId, string $key): int
    {
        $mapping = AccountMapping::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->where('mapping_key', $key)
            ->first();

        if (! $mapping) {
            throw ValidationException::withMessages([
                'mapping' => "Account mapping '{$key}' is required for year-end close.",
            ]);
        }

        return (int) $mapping->account_id;
    }
}
