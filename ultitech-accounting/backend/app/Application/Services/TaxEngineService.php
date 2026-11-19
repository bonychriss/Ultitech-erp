<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Models\TaxReconciliation;
use App\Models\TaxReturn;
use App\Models\TaxReturnLine;
use App\Models\TaxTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaxEngineService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditTrailService $audit,
        private readonly AccountBalanceService $balances,
    ) {}

    public function resolveRate(TaxCode $taxCode, string $asOfDate): TaxRate
    {
        $rate = TaxRate::query()
            ->whereKey($taxCode->tax_rate_id)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $asOfDate)
            ->where(function ($q) use ($asOfDate) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->first();

        if (! $rate) {
            // Fallback: load linked rate and validate effective window manually
            $rate = TaxRate::query()->find($taxCode->tax_rate_id);
            if (! $rate || ! $rate->is_active) {
                throw ValidationException::withMessages(['tax_code_id' => 'Tax rate is inactive.']);
            }
            if ($rate->effective_from > $asOfDate || ($rate->effective_to && $rate->effective_to < $asOfDate)) {
                throw ValidationException::withMessages([
                    'tax_code_id' => 'No effective tax rate for the transaction date.',
                ]);
            }
        }

        return $rate;
    }

    public function calculate(TaxCode $taxCode, string $taxableAmount, string $asOfDate): array
    {
        $rate = $this->resolveRate($taxCode, $asOfDate);
        $base = Money::normalize($taxableAmount);
        $tax = Money::normalize(bcdiv(bcmul($base, Money::normalize($rate->rate_percent, 4), 8), '100', 4));

        return [
            'tax_code' => $taxCode,
            'tax_rate' => $rate,
            'taxable_amount' => $base,
            'tax_amount' => $tax,
            'rate_percent' => Money::normalize($rate->rate_percent, 4),
            'gross_amount' => Money::add($base, $tax),
        ];
    }

    public function recordTransaction(
        AccountingBook $book,
        TaxCode $taxCode,
        string $direction,
        string $taxableAmount,
        string $taxDate,
        string $sourceType,
        int $sourceId,
        ?int $journalId = null
    ): TaxTransaction {
        $calc = $this->calculate($taxCode, $taxableAmount, $taxDate);

        return TaxTransaction::withoutGlobalScopes()->create([
            'company_id' => $book->company_id,
            'book_id' => $book->id,
            'tax_code_id' => $taxCode->id,
            'tax_rate_id' => $calc['tax_rate']->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'tax_date' => $taxDate,
            'direction' => $direction,
            'taxable_amount' => $calc['taxable_amount'],
            'tax_amount' => $calc['tax_amount'],
            'rate_percent' => $calc['rate_percent'],
            'journal_id' => $journalId,
            'status' => 'posted',
        ]);
    }

    public function prepareReturn(
        int $companyId,
        int $jurisdictionId,
        int $taxTypeId,
        string $periodStart,
        string $periodEnd,
        User $user
    ): TaxReturn {
        return DB::transaction(function () use ($companyId, $jurisdictionId, $taxTypeId, $periodStart, $periodEnd, $user) {
            $txns = TaxTransaction::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereDate('tax_date', '>=', $periodStart)
                ->whereDate('tax_date', '<=', $periodEnd)
                ->where('status', 'posted')
                ->whereHas('taxRate', fn ($q) => $q->where('tax_type_id', $taxTypeId)->where('jurisdiction_id', $jurisdictionId))
                ->get();

            $output = $txns->where('direction', 'output')->reduce(
                fn ($c, $t) => Money::add($c, Money::normalize($t->tax_amount)),
                '0.0000'
            );
            $input = $txns->where('direction', 'input')->reduce(
                fn ($c, $t) => Money::add($c, Money::normalize($t->tax_amount)),
                '0.0000'
            );
            $net = Money::normalize(bcsub($output, $input, 4));

            $return = TaxReturn::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'jurisdiction_id' => $jurisdictionId,
                'tax_type_id' => $taxTypeId,
                'return_number' => $this->numbers->next($companyId, 'tax_return', 'VAT'),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'output_tax' => $output,
                'input_tax' => $input,
                'net_tax' => $net,
                'status' => 'draft',
                'prepared_by' => $user->id,
            ]);

            foreach ($txns as $txn) {
                TaxReturnLine::query()->create([
                    'tax_return_id' => $return->id,
                    'company_id' => $companyId,
                    'tax_transaction_id' => $txn->id,
                    'line_type' => $txn->direction,
                    'taxable_amount' => $txn->taxable_amount,
                    'tax_amount' => $txn->tax_amount,
                ]);
            }

            $this->audit->record('tax.return_prepared', 'tax_return', $return->id, null, [
                'output_tax' => $output,
                'input_tax' => $input,
                'net_tax' => $net,
            ]);

            return $return->load('lines');
        });
    }

    public function reconcileReturn(
        TaxReturn $return,
        AccountingBook $book,
        string $outputMappingKey = 'vat_output',
        string $inputMappingKey = 'vat_input'
    ): TaxReconciliation {
        $asOf = $return->period_end->toDateString();
        $tb = $this->balances->trialBalance((int) $book->company_id, (int) $book->id, $asOf);

        $outputGl = $this->controlNetCredit($book, $outputMappingKey, $tb);
        $inputGl = $this->controlNetDebit($book, $inputMappingKey, $tb);
        $glBalance = Money::normalize(bcsub($outputGl, $inputGl, 4));

        $returnBalance = Money::normalize($return->net_tax);
        $difference = Money::normalize(bcsub($glBalance, $returnBalance, 4));

        $recon = TaxReconciliation::withoutGlobalScopes()->create([
            'company_id' => $return->company_id,
            'tax_return_id' => $return->id,
            'gl_balance' => $glBalance,
            'return_balance' => $returnBalance,
            'difference' => $difference,
            'status' => Money::isZero($difference) ? 'balanced' : 'difference',
            'notes' => Money::isZero($difference)
                ? 'Tax return reconciles to GL VAT control accounts (output − input).'
                : 'Difference requires review. Rates are effective-dated configuration, not hardcoded constants.',
        ]);

        $return->update(['status' => 'reconciled']);

        return $recon;
    }

    private function controlNetCredit(AccountingBook $book, string $mappingKey, $trialBalance): string
    {
        $mapping = DB::table('acc_account_mappings')
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('mapping_key', $mappingKey)
            ->first();

        if (! $mapping) {
            return '0.0000';
        }

        $row = $trialBalance->firstWhere('account_id', (int) $mapping->account_id);

        return $row
            ? Money::normalize(bcsub($row['credit'], $row['debit'], 4))
            : '0.0000';
    }

    private function controlNetDebit(AccountingBook $book, string $mappingKey, $trialBalance): string
    {
        $mapping = DB::table('acc_account_mappings')
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('mapping_key', $mappingKey)
            ->first();

        if (! $mapping) {
            return '0.0000';
        }

        $row = $trialBalance->firstWhere('account_id', (int) $mapping->account_id);

        return $row
            ? Money::normalize(bcsub($row['debit'], $row['credit'], 4))
            : '0.0000';
    }
}

