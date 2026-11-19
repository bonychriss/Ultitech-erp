<?php

namespace App\Application\Services;

use App\Models\Account;
use App\Models\AccountingBook;
use App\Models\ConsolidationGroup;
use App\Models\ConsolidationMember;
use App\Models\ConsolidationRun;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class ConsolidationService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly AuditTrailService $audit,
    ) {}

    public function createGroup(int $ownerCompanyId, array $payload): ConsolidationGroup
    {
        return DB::transaction(function () use ($ownerCompanyId, $payload) {
            $group = ConsolidationGroup::withoutGlobalScopes()->create([
                'company_id' => $ownerCompanyId,
                'code' => $payload['code'],
                'name' => $payload['name'],
                'parent_book_id' => $payload['parent_book_id'],
                'reporting_currency' => $payload['reporting_currency'],
            ]);

            foreach ($payload['members'] as $member) {
                ConsolidationMember::query()->create([
                    'group_id' => $group->id,
                    'company_id' => $ownerCompanyId,
                    'member_company_id' => $member['member_company_id'],
                    'member_book_id' => $member['member_book_id'],
                    'ownership_percent' => Money::normalize($member['ownership_percent'] ?? 100, 4),
                    'is_parent' => (bool) ($member['is_parent'] ?? false),
                ]);
            }

            return $group->load('members');
        });
    }

    public function run(ConsolidationGroup $group, User $user, string $asOfDate): ConsolidationRun
    {
        $members = $group->members;
        $combined = [];

        foreach ($members as $member) {
            $book = AccountingBook::withoutGlobalScopes()->findOrFail($member->member_book_id);
            $tb = $this->balances->trialBalance((int) $book->company_id, (int) $book->id, $asOfDate);
            $ownership = bcdiv(Money::normalize($member->ownership_percent, 4), '100', 8);

            foreach ($tb as $row) {
                $account = Account::withoutGlobalScopes()->find($row['account_id']);
                $key = $account?->code ?? ('ID:'.$row['account_id']);
                $debit = Money::multiply($row['debit'], $ownership);
                $credit = Money::multiply($row['credit'], $ownership);

                if (! isset($combined[$key])) {
                    $combined[$key] = [
                        'account_code' => $account?->code,
                        'account_name' => $account?->name,
                        'debit' => '0.0000',
                        'credit' => '0.0000',
                    ];
                }
                $combined[$key]['debit'] = Money::add($combined[$key]['debit'], $debit);
                $combined[$key]['credit'] = Money::add($combined[$key]['credit'], $credit);
            }
        }

        $lines = array_values($combined);
        $totalDebit = array_reduce($lines, fn ($c, $r) => Money::add($c, $r['debit']), '0.0000');
        $totalCredit = array_reduce($lines, fn ($c, $r) => Money::add($c, $r['credit']), '0.0000');

        $run = ConsolidationRun::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'company_id' => $group->company_id,
            'as_of_date' => $asOfDate,
            'result_json' => [
                'reporting_currency' => $group->reporting_currency,
                'member_count' => $members->count(),
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balanced' => Money::equals($totalDebit, $totalCredit),
                'lines' => $lines,
                'note' => 'Additive consolidation by ownership %. Intercompany eliminations deferred.',
            ],
            'created_by' => $user->id,
        ]);

        $this->audit->record('consolidation.run', 'consolidation_run', $run->id, null, [
            'group_id' => $group->id,
            'as_of_date' => $asOfDate,
        ]);

        return $run;
    }
}
