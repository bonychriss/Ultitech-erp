<?php

namespace App\Application\Services;

use App\Models\Account;
use App\Models\AccountingBook;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalPeriod;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BudgetService
{
    public function __construct(private readonly AuditTrailService $audit) {}

    public function create(AccountingBook $book, User $user, array $payload): Budget
    {
        return DB::transaction(function () use ($book, $user, $payload) {
            $budget = Budget::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'fiscal_year_id' => $payload['fiscal_year_id'],
                'code' => $payload['code'],
                'name' => $payload['name'],
                'status' => 'draft',
                'created_by' => $user->id,
            ]);

            foreach ($payload['lines'] as $line) {
                BudgetLine::query()->create([
                    'budget_id' => $budget->id,
                    'company_id' => $book->company_id,
                    'period_id' => $line['period_id'],
                    'account_id' => $line['account_id'],
                    'cost_center_id' => $line['cost_center_id'] ?? null,
                    'amount' => Money::normalize($line['amount']),
                ]);
            }

            $this->audit->record('budget.created', 'budget', $budget->id, null, [
                'code' => $budget->code,
                'lines' => count($payload['lines']),
            ]);

            return $budget->load(['lines.account', 'lines.period']);
        });
    }

    public function approve(Budget $budget): Budget
    {
        $budget->update(['status' => 'approved']);
        $this->audit->record('budget.approved', 'budget', $budget->id, null, ['status' => 'approved']);

        return $budget->fresh(['lines.account', 'lines.period']);
    }

    public function versusActual(Budget $budget): array
    {
        if ($budget->status !== 'approved' && $budget->status !== 'draft') {
            // allow both for reporting
        }

        $rows = [];
        foreach ($budget->lines()->with(['account', 'period'])->get() as $line) {
            $period = $line->period;
            $actual = $this->actualForAccount(
                (int) $budget->company_id,
                (int) $budget->book_id,
                (int) $line->account_id,
                \Carbon\Carbon::parse($period->start_date)->toDateString(),
                \Carbon\Carbon::parse($period->end_date)->toDateString(),
                $line->cost_center_id ? (int) $line->cost_center_id : null
            );

            $budgetAmt = Money::normalize($line->amount);
            $variance = Money::normalize(bcsub($budgetAmt, $actual, 4));

            $rows[] = [
                'period_id' => $line->period_id,
                'period' => $period->name,
                'account_id' => $line->account_id,
                'account_code' => $line->account->code,
                'account_name' => $line->account->name,
                'cost_center_id' => $line->cost_center_id,
                'budget' => $budgetAmt,
                'actual' => $actual,
                'variance' => $variance,
            ];
        }

        return $rows;
    }

    private function actualForAccount(
        int $companyId,
        int $bookId,
        int $accountId,
        string $from,
        string $to,
        ?int $costCenterId
    ): string {
        $account = Account::withoutGlobalScopes()->findOrFail($accountId);
        $type = $account->accountType?->code ?? Account::withoutGlobalScopes()
            ->join('acc_account_types', 'acc_account_types.id', '=', 'acc_accounts.account_type_id')
            ->where('acc_accounts.id', $accountId)
            ->value('acc_account_types.code');

        $q = JournalLine::withoutGlobalScopes()
            ->join('acc_journal_entries', 'acc_journal_entries.id', '=', 'acc_journal_lines.journal_id')
            ->where('acc_journal_lines.company_id', $companyId)
            ->where('acc_journal_lines.book_id', $bookId)
            ->where('acc_journal_lines.account_id', $accountId)
            ->whereIn('acc_journal_entries.status', ['posted', 'reversed'])
            ->whereDate('acc_journal_entries.journal_date', '>=', $from)
            ->whereDate('acc_journal_entries.journal_date', '<=', $to);

        $lines = $q->get([
            'acc_journal_lines.functional_debit',
            'acc_journal_lines.functional_credit',
            'acc_journal_lines.dimensions_json',
        ]);

        if ($costCenterId) {
            $lines = $lines->filter(function ($line) use ($costCenterId) {
                $dims = is_string($line->dimensions_json)
                    ? json_decode($line->dimensions_json, true)
                    : (array) $line->dimensions_json;

                return (int) ($dims['cost_center_id'] ?? 0) === $costCenterId;
            });
        }

        $debit = $lines->reduce(fn ($c, $l) => Money::add($c, Money::normalize($l->functional_debit)), '0.0000');
        $credit = $lines->reduce(fn ($c, $l) => Money::add($c, Money::normalize($l->functional_credit)), '0.0000');

        // Expense/asset: debit nature; revenue/liability/equity: credit nature for "actual spend/earn"
        if (in_array($type, ['expense', 'asset'], true)) {
            return Money::normalize(bcsub($debit, $credit, 4));
        }

        return Money::normalize(bcsub($credit, $debit, 4));
    }
}
