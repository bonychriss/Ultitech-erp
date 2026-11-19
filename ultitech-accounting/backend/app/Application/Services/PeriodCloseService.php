<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\CloseChecklist;
use App\Models\CloseChecklistItem;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\PeriodClose;
use App\Models\PeriodLock;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PeriodCloseService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly AuditTrailService $audit,
    ) {}

    public function buildChecklist(AccountingBook $book, User $user, int $periodId): CloseChecklist
    {
        $period = FiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->whereKey($periodId)
            ->firstOrFail();

        $checks = $this->evaluateMonthEnd($book, $period);

        return DB::transaction(function () use ($book, $user, $period, $checks) {
            $checklist = CloseChecklist::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'close_type' => 'month_end',
                'period_id' => $period->id,
                'status' => collect($checks)->contains(fn ($c) => $c['is_required'] && ! $c['is_passed']) ? 'blocked' : 'ready',
                'created_by' => $user->id,
            ]);

            foreach ($checks as $check) {
                CloseChecklistItem::query()->create([
                    'checklist_id' => $checklist->id,
                    'company_id' => $book->company_id,
                    'code' => $check['code'],
                    'label' => $check['label'],
                    'is_required' => $check['is_required'],
                    'is_passed' => $check['is_passed'],
                    'details' => $check['details'],
                ]);
            }

            return $checklist->load('items');
        });
    }

    public function close(AccountingBook $book, User $user, int $periodId, ?string $notes = null): PeriodClose
    {
        $period = FiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->whereKey($periodId)
            ->firstOrFail();

        if ($period->status === 'closed') {
            throw ValidationException::withMessages(['period_id' => 'Period is already closed.']);
        }

        $checklist = $this->buildChecklist($book, $user, $periodId);
        $failed = $checklist->items->where('is_required', true)->where('is_passed', false);
        if ($failed->isNotEmpty()) {
            throw ValidationException::withMessages([
                'checklist' => 'Month-end checklist has required failures: '.$failed->pluck('code')->implode(', '),
            ]);
        }

        return DB::transaction(function () use ($book, $user, $period, $checklist, $notes) {
            $period->update(['status' => 'closed']);

            PeriodLock::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $book->company_id,
                    'book_id' => $book->id,
                    'period_id' => $period->id,
                ],
                [
                    'locked_at' => now(),
                    'locked_by' => $user->id,
                    'reason' => 'Month-end close',
                ]
            );

            $checklist->update(['status' => 'completed']);

            $close = PeriodClose::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'period_id' => $period->id,
                'checklist_id' => $checklist->id,
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $user->id,
                'notes' => $notes,
            ]);

            $this->audit->record('period.closed', 'period_close', $close->id, null, [
                'period_id' => $period->id,
            ]);

            return $close->load('checklist.items');
        });
    }

    private function evaluateMonthEnd(AccountingBook $book, FiscalPeriod $period): array
    {
        $from = Carbon::parse($period->start_date)->toDateString();
        $to = Carbon::parse($period->end_date)->toDateString();

        $drafts = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('status', 'draft')
            ->whereDate('journal_date', '>=', $from)
            ->whereDate('journal_date', '<=', $to)
            ->count();

        $pendingApprovals = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('approval_status', 'pending')
            ->whereDate('journal_date', '>=', $from)
            ->whereDate('journal_date', '<=', $to)
            ->count();

        $tb = $this->balances->trialBalance((int) $book->company_id, (int) $book->id, $to);
        $debit = $tb->reduce(fn ($c, $r) => Money::add($c, $r['debit']), '0.0000');
        $credit = $tb->reduce(fn ($c, $r) => Money::add($c, $r['credit']), '0.0000');
        $balanced = Money::equals($debit, $credit);

        return [
            [
                'code' => 'NO_DRAFT_JOURNALS',
                'label' => 'No draft journals in period',
                'is_required' => true,
                'is_passed' => $drafts === 0,
                'details' => $drafts === 0 ? 'Clear' : "{$drafts} draft journal(s) remain",
            ],
            [
                'code' => 'NO_PENDING_APPROVALS',
                'label' => 'No pending journal approvals',
                'is_required' => true,
                'is_passed' => $pendingApprovals === 0,
                'details' => $pendingApprovals === 0 ? 'Clear' : "{$pendingApprovals} pending approval(s)",
            ],
            [
                'code' => 'TRIAL_BALANCE_BALANCED',
                'label' => 'Trial balance is balanced as of period end',
                'is_required' => true,
                'is_passed' => $balanced,
                'details' => $balanced ? "Debit {$debit} = Credit {$credit}" : "Debit {$debit} != Credit {$credit}",
            ],
        ];
    }
}
