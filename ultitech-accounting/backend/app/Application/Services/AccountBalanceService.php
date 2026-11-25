<?php

namespace App\Application\Services;

use App\Models\AccountOpening;
use App\Models\JournalLine;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountBalanceService
{
    public function trialBalance(int $companyId, int $bookId, ?string $asOfDate = null): Collection
    {
        $openings = AccountOpening::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->select('account_id', DB::raw('SUM(debit) as opening_debit'), DB::raw('SUM(credit) as opening_credit'))
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        $movementsQuery = JournalLine::withoutGlobalScopes()
            ->join('acc_journal_entries', 'acc_journal_entries.id', '=', 'acc_journal_lines.journal_id')
            ->where('acc_journal_lines.company_id', $companyId)
            ->where('acc_journal_lines.book_id', $bookId)
            ->whereIn('acc_journal_entries.status', ['posted', 'reversed']);

        if ($asOfDate) {
            $movementsQuery->whereDate('acc_journal_entries.journal_date', '<=', $asOfDate);
        }

        $movements = $movementsQuery
            ->select(
                'acc_journal_lines.account_id',
                DB::raw('SUM(acc_journal_lines.functional_debit) as movement_debit'),
                DB::raw('SUM(acc_journal_lines.functional_credit) as movement_credit')
            )
            ->groupBy('acc_journal_lines.account_id')
            ->get()
            ->keyBy('account_id');

        $accountIds = $openings->keys()->merge($movements->keys())->unique();

        return $accountIds->map(function ($accountId) use ($openings, $movements) {
            $openingDebit = Money::normalize($openings->get($accountId)?->opening_debit ?? 0);
            $openingCredit = Money::normalize($openings->get($accountId)?->opening_credit ?? 0);
            $movementDebit = Money::normalize($movements->get($accountId)?->movement_debit ?? 0);
            $movementCredit = Money::normalize($movements->get($accountId)?->movement_credit ?? 0);

            $debit = Money::add($openingDebit, $movementDebit);
            $credit = Money::add($openingCredit, $movementCredit);

            return [
                'account_id' => (int) $accountId,
                'opening_debit' => $openingDebit,
                'opening_credit' => $openingCredit,
                'movement_debit' => $movementDebit,
                'movement_credit' => $movementCredit,
                'debit' => $debit,
                'credit' => $credit,
            ];
        })->values();
    }
}
