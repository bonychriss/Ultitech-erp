<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\FixedAsset;
use App\Models\ImpairmentTest;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImpairmentService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly AuditTrailService $audit,
    ) {}

    public function impairFixedAsset(AccountingBook $book, User $user, array $payload): ImpairmentTest
    {
        $asset = FixedAsset::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->whereKey($payload['asset_id'])
            ->with('category')
            ->firstOrFail();

        $carrying = Money::normalize($asset->net_book_value);
        $recoverable = Money::normalize($payload['recoverable_amount']);
        $loss = Money::normalize(bcsub($carrying, $recoverable, 4));

        if (bccomp($loss, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'recoverable_amount' => 'Recoverable amount is not below carrying amount; no impairment.',
            ]);
        }

        $expenseAccountId = (int) ($payload['impairment_expense_account_id'] ?? $asset->category->depr_expense_account_id);

        return DB::transaction(function () use ($book, $user, $asset, $payload, $carrying, $recoverable, $loss, $expenseAccountId) {
            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $payload['test_date'],
                'description' => 'Impairment '.$asset->asset_code,
                'reference' => $asset->asset_code,
                'currency_code' => $book->functional_currency_code,
                'source_type' => 'impairment',
                'source_id_ref' => (string) $asset->id,
                'source_hash' => 'impairment:fixed_asset:'.$asset->id.':'.$payload['test_date'],
                'lines' => [
                    [
                        'account_id' => $expenseAccountId,
                        'debit' => $loss,
                        'credit' => '0',
                        'description' => 'Impairment loss',
                    ],
                    [
                        'account_id' => (int) $asset->category->accum_depr_account_id,
                        'debit' => '0',
                        'credit' => $loss,
                        'description' => 'Accumulated impairment',
                    ],
                ],
            ], 'system');

            $asset->update([
                'accum_depreciation' => Money::add(Money::normalize($asset->accum_depreciation), $loss),
                'net_book_value' => $recoverable,
            ]);

            $test = ImpairmentTest::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'asset_type' => 'fixed_asset',
                'asset_id' => $asset->id,
                'test_date' => $payload['test_date'],
                'carrying_amount' => $carrying,
                'recoverable_amount' => $recoverable,
                'impairment_loss' => $loss,
                'journal_id' => $journal->id,
                'status' => 'posted',
                'created_by' => $user->id,
                'notes' => $payload['notes'] ?? null,
            ]);

            $this->audit->record('impairment.posted', 'impairment_test', $test->id, null, [
                'asset_id' => $asset->id,
                'loss' => $loss,
            ]);

            return $test;
        });
    }
}
