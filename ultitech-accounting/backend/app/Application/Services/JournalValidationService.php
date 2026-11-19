<?php

namespace App\Application\Services;

use App\Models\Account;
use App\Models\AccountingBook;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class JournalValidationService
{
    public function __construct(
        private readonly AccountingPeriodService $periods,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{period_id:int, exchange_rate:string, total_debit:string, total_credit:string, lines:Collection}
     */
    public function validateForPosting(
        AccountingBook $book,
        string $journalDate,
        string $currencyCode,
        array $lines,
        bool $allowManualEntryOnly = true
    ): array {
        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'lines' => 'A journal requires at least two lines.',
            ]);
        }

        $period = $this->periods->resolveOpenPeriod((int) $book->company_id, (int) $book->id, $journalDate);
        $rate = $this->rates->resolveRate(
            (int) $book->company_id,
            $currencyCode,
            $book->functional_currency_code,
            $journalDate
        );

        $prepared = collect();
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';

        foreach ($lines as $index => $line) {
            $account = Account::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('id', $line['account_id'] ?? 0)
                ->first();

            if (! $account) {
                throw ValidationException::withMessages([
                    "lines.$index.account_id" => 'Account not found in this company book.',
                ]);
            }

            if ($account->status !== 'active') {
                throw ValidationException::withMessages([
                    "lines.$index.account_id" => 'Account is inactive.',
                ]);
            }

            if (! $account->is_postable) {
                throw ValidationException::withMessages([
                    "lines.$index.account_id" => 'Account is not postable.',
                ]);
            }

            if ($allowManualEntryOnly && ! $account->allow_manual_entry) {
                throw ValidationException::withMessages([
                    "lines.$index.account_id" => 'Account does not allow manual entry.',
                ]);
            }

            $debit = Money::normalize($line['debit'] ?? 0);
            $credit = Money::normalize($line['credit'] ?? 0);

            if (Money::isZero($debit) && Money::isZero($credit)) {
                throw ValidationException::withMessages([
                    "lines.$index" => 'Line must have a debit or credit amount.',
                ]);
            }

            if (! Money::isZero($debit) && ! Money::isZero($credit)) {
                throw ValidationException::withMessages([
                    "lines.$index" => 'Line cannot have both debit and credit.',
                ]);
            }

            $functionalDebit = Money::multiply($debit, $rate);
            $functionalCredit = Money::multiply($credit, $rate);
            $totalDebit = Money::add($totalDebit, $functionalDebit);
            $totalCredit = Money::add($totalCredit, $functionalCredit);

            $dimensions = $line['dimensions'] ?? $line['dimensions_json'] ?? null;
            if (is_array($dimensions) && isset($dimensions['cost_center_id'])) {
                $dimensions['cost_center_id'] = (int) $dimensions['cost_center_id'];
            }

            $prepared->push([
                'account_id' => $account->id,
                'description' => $line['description'] ?? null,
                'dimensions_json' => $dimensions,
                'debit' => $debit,
                'credit' => $credit,
                'currency_code' => $currencyCode,
                'exchange_rate' => $rate,
                'functional_debit' => $functionalDebit,
                'functional_credit' => $functionalCredit,
            ]);
        }

        if (! Money::equals($totalDebit, $totalCredit)) {
            throw ValidationException::withMessages([
                'lines' => "Journal is unbalanced. Debit {$totalDebit} != Credit {$totalCredit}.",
            ]);
        }

        return [
            'period_id' => (int) $period->id,
            'exchange_rate' => $rate,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'lines' => $prepared,
        ];
    }
}
