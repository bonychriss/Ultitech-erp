<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\AssetCategory;
use App\Models\AssetDisposal;
use App\Models\DepreciationRun;
use App\Models\DepreciationRunLine;
use App\Models\FiscalPeriod;
use App\Models\FixedAsset;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FixedAssetService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly DocumentNumberService $numbers,
        private readonly AuditTrailService $audit,
    ) {}

    public function acquire(AccountingBook $book, User $user, array $payload): FixedAsset
    {
        $category = AssetCategory::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('id', $payload['category_id'])
            ->firstOrFail();

        $cost = Money::normalize($payload['acquisition_cost']);
        $salvage = Money::normalize($payload['salvage_value'] ?? 0);
        $life = (int) ($payload['useful_life_months'] ?? $category->useful_life_months);
        $creditAccountId = (int) $payload['credit_account_id'];

        return DB::transaction(function () use ($book, $user, $payload, $category, $cost, $salvage, $life, $creditAccountId) {
            $code = $payload['asset_code'] ?? $this->numbers->next((int) $book->company_id, 'fixed_asset', 'FA');

            $asset = FixedAsset::withoutGlobalScopes()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'category_id' => $category->id,
                'asset_code' => $code,
                'name' => $payload['name'],
                'acquisition_date' => $payload['acquisition_date'],
                'acquisition_cost' => $cost,
                'salvage_value' => $salvage,
                'useful_life_months' => $life,
                'accum_depreciation' => '0.0000',
                'net_book_value' => $cost,
                'status' => 'active',
            ]);

            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $payload['acquisition_date'],
                'description' => 'Asset acquisition '.$asset->asset_code,
                'reference' => $asset->asset_code,
                'currency_code' => $book->functional_currency_code,
                'source_type' => 'fixed_asset_acquisition',
                'source_id_ref' => (string) $asset->id,
                'source_hash' => 'fixed_asset_acquisition:'.$asset->id,
                'lines' => [
                    [
                        'account_id' => $category->asset_account_id,
                        'debit' => $cost,
                        'credit' => '0',
                        'description' => $asset->name,
                    ],
                    [
                        'account_id' => $creditAccountId,
                        'debit' => '0',
                        'credit' => $cost,
                        'description' => 'Acquisition funding',
                    ],
                ],
            ], 'system');

            $asset->update(['acquisition_journal_id' => $journal->id]);
            $this->audit->record('asset.acquired', 'fixed_asset', $asset->id, null, [
                'asset_code' => $asset->asset_code,
                'cost' => $cost,
            ]);

            return $asset->fresh();
        });
    }

    public function runDepreciation(AccountingBook $book, User $user, int $periodId): DepreciationRun
    {
        $period = FiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('id', $periodId)
            ->firstOrFail();

        $exists = DepreciationRun::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('period_id', $period->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['period_id' => 'Depreciation already run for this period.']);
        }

        $assets = FixedAsset::withoutGlobalScopes()
            ->with('category')
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('status', 'active')
            ->whereDate('acquisition_date', '<=', $period->end_date)
            ->get();

        return DB::transaction(function () use ($book, $user, $period, $assets) {
            $linesByExpense = [];
            $linesByAccum = [];
            $runLines = [];
            $total = '0.0000';

            foreach ($assets as $asset) {
                $depreciable = Money::normalize(bcsub(
                    Money::normalize($asset->acquisition_cost),
                    Money::normalize($asset->salvage_value),
                    4
                ));
                $monthly = Money::normalize(bcdiv($depreciable, (string) max(1, (int) $asset->useful_life_months), 4));
                $remaining = Money::normalize(bcsub(
                    Money::normalize($asset->net_book_value),
                    Money::normalize($asset->salvage_value),
                    4
                ));

                if (bccomp($remaining, '0', 4) <= 0) {
                    continue;
                }

                $amount = bccomp($monthly, $remaining, 4) === 1 ? $remaining : $monthly;
                $total = Money::add($total, $amount);

                $expenseId = $asset->category->depr_expense_account_id;
                $accumId = $asset->category->accum_depr_account_id;
                $linesByExpense[$expenseId] = Money::add($linesByExpense[$expenseId] ?? '0.0000', $amount);
                $linesByAccum[$accumId] = Money::add($linesByAccum[$accumId] ?? '0.0000', $amount);

                $runLines[] = ['asset' => $asset, 'amount' => $amount];
            }

            if (Money::isZero($total)) {
                throw ValidationException::withMessages(['assets' => 'No depreciable assets for this period.']);
            }

            $journalLines = [];
            foreach ($linesByExpense as $accountId => $amount) {
                $journalLines[] = [
                    'account_id' => $accountId,
                    'debit' => $amount,
                    'credit' => '0',
                    'description' => 'Depreciation expense '.$period->name,
                ];
            }
            foreach ($linesByAccum as $accountId => $amount) {
                $journalLines[] = [
                    'account_id' => $accountId,
                    'debit' => '0',
                    'credit' => $amount,
                    'description' => 'Accumulated depreciation '.$period->name,
                ];
            }

            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $period->end_date,
                'description' => 'Depreciation run '.$period->name,
                'reference' => 'DEPR-'.$period->id,
                'currency_code' => $book->functional_currency_code,
                'source_type' => 'depreciation_run',
                'source_id_ref' => (string) $period->id,
                'source_hash' => 'depreciation_run:'.$book->id.':'.$period->id,
                'lines' => $journalLines,
            ], 'system');

            $run = DepreciationRun::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'period_id' => $period->id,
                'run_date' => $period->end_date,
                'total_amount' => $total,
                'status' => 'posted',
                'posted_journal_id' => $journal->id,
                'created_by' => $user->id,
            ]);

            foreach ($runLines as $line) {
                /** @var FixedAsset $asset */
                $asset = $line['asset'];
                $amount = $line['amount'];
                DepreciationRunLine::query()->create([
                    'run_id' => $run->id,
                    'company_id' => $book->company_id,
                    'asset_id' => $asset->id,
                    'amount' => $amount,
                ]);

                $newAccum = Money::add(Money::normalize($asset->accum_depreciation), $amount);
                $newNbv = Money::normalize(bcsub(Money::normalize($asset->acquisition_cost), $newAccum, 4));
                $asset->update([
                    'accum_depreciation' => $newAccum,
                    'net_book_value' => $newNbv,
                ]);
            }

            $this->audit->record('asset.depreciation_run', 'depreciation_run', $run->id, null, [
                'period_id' => $period->id,
                'total' => $total,
            ]);

            return $run->load('lines');
        });
    }

    public function dispose(AccountingBook $book, User $user, FixedAsset $asset, array $payload): AssetDisposal
    {
        if ($asset->status !== 'active') {
            throw ValidationException::withMessages(['asset' => 'Asset is not active.']);
        }

        $proceeds = Money::normalize($payload['proceeds'] ?? 0);
        $nbv = Money::normalize($asset->net_book_value);
        $gainLoss = Money::normalize(bcsub($proceeds, $nbv, 4));
        $bankAccountId = (int) $payload['bank_gl_account_id'];
        $gainLossAccountId = (int) $payload['gain_loss_account_id'];
        $category = AssetCategory::withoutGlobalScopes()->findOrFail($asset->category_id);

        return DB::transaction(function () use ($book, $user, $asset, $payload, $proceeds, $nbv, $gainLoss, $bankAccountId, $gainLossAccountId, $category) {
            $lines = [
                [
                    'account_id' => $category->accum_depr_account_id,
                    'debit' => Money::normalize($asset->accum_depreciation),
                    'credit' => '0',
                    'description' => 'Clear accum depr',
                ],
                [
                    'account_id' => $category->asset_account_id,
                    'debit' => '0',
                    'credit' => Money::normalize($asset->acquisition_cost),
                    'description' => 'Clear asset cost',
                ],
            ];

            if (! Money::isZero($proceeds)) {
                $lines[] = [
                    'account_id' => $bankAccountId,
                    'debit' => $proceeds,
                    'credit' => '0',
                    'description' => 'Disposal proceeds',
                ];
            }

            if (bccomp($gainLoss, '0', 4) > 0) {
                $lines[] = [
                    'account_id' => $gainLossAccountId,
                    'debit' => '0',
                    'credit' => $gainLoss,
                    'description' => 'Gain on disposal',
                ];
            } elseif (bccomp($gainLoss, '0', 4) < 0) {
                $lines[] = [
                    'account_id' => $gainLossAccountId,
                    'debit' => Money::normalize(bcmul($gainLoss, '-1', 4)),
                    'credit' => '0',
                    'description' => 'Loss on disposal',
                ];
            }

            // Ensure debit=credit by validating through posting service
            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $payload['disposal_date'],
                'description' => 'Asset disposal '.$asset->asset_code,
                'reference' => $asset->asset_code,
                'currency_code' => $book->functional_currency_code,
                'source_type' => 'fixed_asset_disposal',
                'source_id_ref' => (string) $asset->id,
                'source_hash' => 'fixed_asset_disposal:'.$asset->id,
                'lines' => $lines,
            ], 'system');

            $disposal = AssetDisposal::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'asset_id' => $asset->id,
                'disposal_date' => $payload['disposal_date'],
                'proceeds' => $proceeds,
                'net_book_value' => $nbv,
                'gain_loss' => $gainLoss,
                'posted_journal_id' => $journal->id,
                'created_by' => $user->id,
            ]);

            $asset->update([
                'status' => 'disposed',
                'net_book_value' => '0.0000',
            ]);

            return $disposal;
        });
    }
}
