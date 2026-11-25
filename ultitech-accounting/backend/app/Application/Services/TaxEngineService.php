<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\AccountMapping;
use App\Models\BankAccount;
use App\Models\CustomerInvoice;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Models\TaxReconciliation;
use App\Models\TaxReturn;
use App\Models\TaxReturnLine;
use App\Models\TaxTransaction;
use App\Models\TaxType;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaxEngineService
{
    /** @var list<string> */
    public const SUPPLY_CATEGORIES = [
        'standard',
        'zero_rated',
        'exempt',
        'out_of_scope',
        'reverse_charge',
        'adjustment',
    ];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditTrailService $audit,
        private readonly AccountBalanceService $balances,
        private readonly JournalPostingService $posting,
    ) {}

    /**
     * Ensure the company has a usable standard VAT code (VAT18) for the book's jurisdiction.
     */
    public function ensureDefaultVatCode(AccountingBook $book): ?TaxCode
    {
        $existing = TaxCode::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('is_active', true)
            ->where('code', 'VAT18')
            ->first();
        if ($existing) {
            return $existing;
        }

        $vatTypeId = TaxType::query()->where('code', 'VAT')->value('id');
        if ($vatTypeId) {
            $existing = TaxCode::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('is_active', true)
                ->whereHas('taxRate', fn ($q) => $q->where('tax_type_id', $vatTypeId))
                ->orderBy('id')
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        if (! $vatTypeId) {
            return null;
        }

        $rate = TaxRate::query()
            ->where('jurisdiction_id', $book->jurisdiction_id)
            ->where('tax_type_id', $vatTypeId)
            ->where('is_active', true)
            ->where('code', 'VAT-STD')
            ->orderByDesc('effective_from')
            ->first();
        if (! $rate) {
            $rate = TaxRate::query()
                ->where('jurisdiction_id', $book->jurisdiction_id)
                ->where('tax_type_id', $vatTypeId)
                ->where('is_active', true)
                ->orderByDesc('rate_percent')
                ->orderByDesc('effective_from')
                ->first();
        }
        if (! $rate) {
            return null;
        }

        return TaxCode::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $book->company_id, 'code' => 'VAT18'],
            [
                'jurisdiction_id' => $book->jurisdiction_id,
                'tax_rate_id' => $rate->id,
                'name' => 'Standard VAT '.$rate->rate_percent.'%',
                'applies_to' => 'both',
                'supply_category' => 'standard',
                'is_recoverable' => true,
                'is_active' => true,
            ]
        );
    }

    /**
     * Upsert a tax register row using explicit ERP/source amounts (does not recompute tax %).
     * When tax amount is zero, any prior row for the same source is removed.
     */
    public function upsertExplicitTransaction(
        AccountingBook $book,
        TaxCode $taxCode,
        string $direction,
        string $taxableAmount,
        string $taxAmount,
        string $taxDate,
        string $sourceType,
        int $sourceId,
        ?int $journalId = null
    ): ?TaxTransaction {
        $direction = strtolower(trim($direction)) === 'input' ? 'input' : 'output';
        $taxable = Money::normalize($taxableAmount);
        $tax = Money::normalize($taxAmount);

        $existing = TaxTransaction::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('direction', $direction)
            ->first();

        if (Money::isZero($tax)) {
            if ($existing) {
                $existing->delete();
            }

            return null;
        }

        $rate = $this->resolveRate($taxCode, $taxDate);
        $ratePercent = Money::normalize($rate->rate_percent, 4);
        if (! Money::isZero($taxable)) {
            $derived = Money::normalize(bcmul(bcdiv($tax, $taxable, 8), '100', 4), 4);
            if (! Money::isZero($derived)) {
                $ratePercent = $derived;
            }
        }

        $payload = [
            'company_id' => $book->company_id,
            'book_id' => $book->id,
            'tax_code_id' => $taxCode->id,
            'tax_rate_id' => $rate->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'tax_date' => $taxDate,
            'direction' => $direction,
            'supply_category' => $taxCode->supply_category ?: 'standard',
            'taxable_amount' => $taxable,
            'tax_amount' => $tax,
            'rate_percent' => $ratePercent,
            'journal_id' => $journalId,
            'status' => 'posted',
        ];

        if ($existing) {
            $existing->fill($payload);
            $existing->save();

            return $existing->fresh();
        }

        return TaxTransaction::withoutGlobalScopes()->create($payload);
    }

    /**
     * Feed VAT Control from Accounting customer invoices that already carry tax_total
     * (including rows imported from ERP sales).
     *
     * @return array{created:int,updated:int,removed:int,skipped:int}
     */
    public function backfillFromCustomerInvoices(AccountingBook $book): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0];
        $code = $this->ensureDefaultVatCode($book);
        if (! $code) {
            return $stats;
        }

        $invoices = CustomerInvoice::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->whereNotIn('status', ['draft', 'void', 'cancelled', 'canceled'])
            ->orderBy('id')
            ->get(['id', 'invoice_date', 'subtotal', 'tax_total', 'total', 'posted_journal_id', 'status']);

        foreach ($invoices as $invoice) {
            $tax = Money::normalize($invoice->tax_total ?? 0);
            $taxable = Money::normalize($invoice->subtotal ?? 0);
            if (Money::isZero($taxable) && ! Money::isZero(Money::normalize($invoice->total ?? 0))) {
                $taxable = Money::sub(Money::normalize($invoice->total), $tax);
            }

            $before = TaxTransaction::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('source_type', 'customer_invoice')
                ->where('source_id', (int) $invoice->id)
                ->where('direction', 'output')
                ->first();

            $row = $this->upsertExplicitTransaction(
                $book,
                $code,
                'output',
                $taxable,
                $tax,
                (string) ($invoice->invoice_date?->toDateString() ?? $invoice->invoice_date),
                'customer_invoice',
                (int) $invoice->id,
                $invoice->posted_journal_id ? (int) $invoice->posted_journal_id : null
            );

            if (! $row && $before) {
                $stats['removed']++;
            } elseif (! $row) {
                $stats['skipped']++;
            } elseif ($before) {
                $stats['updated']++;
            } else {
                $stats['created']++;
            }
        }

        return $stats;
    }

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
            $locked = TaxReturn::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('tax_type_id', $taxTypeId)
                ->where('jurisdiction_id', $jurisdictionId)
                ->where('period_locked', true)
                ->whereDate('period_start', '<=', $periodEnd)
                ->whereDate('period_end', '>=', $periodStart)
                ->exists();
            if ($locked) {
                throw ValidationException::withMessages([
                    'period' => 'This tax period overlaps a locked (paid) return.',
                ]);
            }

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
        if ($return->isLocked() || in_array(strtolower((string) $return->status), [TaxReturn::STATUS_FILED, TaxReturn::STATUS_PAID], true)) {
            throw ValidationException::withMessages([
                'status' => 'Filed or paid returns cannot be reconciled again.',
            ]);
        }

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

        $return->update(['status' => TaxReturn::STATUS_RECONCILED]);

        $this->audit->record('tax.return_reconciled', 'tax_return', $return->id, null, [
            'difference' => $difference,
        ]);

        return $recon;
    }

    /**
     * Mark a reconciled return as filed with TRA reference.
     */
    public function fileReturn(TaxReturn $return, User $user, string $filingDate, string $filingReference): TaxReturn
    {
        if (strtolower((string) $return->status) !== TaxReturn::STATUS_RECONCILED) {
            throw ValidationException::withMessages([
                'status' => 'Only reconciled returns can be filed.',
            ]);
        }
        if ($return->isLocked()) {
            throw ValidationException::withMessages(['status' => 'This return period is locked.']);
        }

        $ref = trim($filingReference);
        if ($ref === '') {
            throw ValidationException::withMessages([
                'filing_reference' => 'Filing reference is required.',
            ]);
        }

        $return->update([
            'status' => TaxReturn::STATUS_FILED,
            'filing_date' => $filingDate,
            'filing_reference' => $ref,
            'submitted_at' => now(),
        ]);

        $this->audit->record('tax.return_filed', 'tax_return', $return->id, null, [
            'filing_date' => $filingDate,
            'filing_reference' => $ref,
            'by' => $user->id,
        ]);

        return $return->fresh();
    }

    /**
     * Record VAT settlement: pay net payable from bank (or receive refund into bank).
     * Posts GL and locks the period.
     */
    public function recordPayment(
        TaxReturn $return,
        AccountingBook $book,
        User $user,
        string $paymentDate,
        string $amount,
        int $bankAccountId,
        string $currencyCode = 'TZS'
    ): TaxReturn {
        if (strtolower((string) $return->status) !== TaxReturn::STATUS_FILED) {
            throw ValidationException::withMessages([
                'status' => 'Only filed returns can be paid / settled.',
            ]);
        }
        if ($return->isLocked() || $return->payment_journal_id) {
            throw ValidationException::withMessages(['status' => 'This return is already settled.']);
        }

        $payAmount = Money::normalize($amount);
        if (Money::isZero($payAmount) || bccomp($payAmount, '0', 4) < 0) {
            throw ValidationException::withMessages(['amount' => 'Payment amount must be greater than zero.']);
        }

        $bank = BankAccount::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->whereKey($bankAccountId)
            ->firstOrFail();

        $vatAccountId = $this->mappedAccountId((int) $book->company_id, (int) $book->id, 'vat_output');
        $net = Money::normalize($return->net_tax);
        $isRefund = bccomp($net, '0', 4) < 0;

        return DB::transaction(function () use (
            $return,
            $book,
            $user,
            $paymentDate,
            $payAmount,
            $bank,
            $vatAccountId,
            $isRefund,
            $currencyCode
        ) {
            // Payable: Dr VAT output, Cr bank. Refund received: Dr bank, Cr VAT output.
            $lines = $isRefund
                ? [
                    [
                        'account_id' => (int) $bank->gl_account_id,
                        'debit' => $payAmount,
                        'credit' => '0',
                        'description' => 'VAT refund '.$return->return_number,
                    ],
                    [
                        'account_id' => $vatAccountId,
                        'debit' => '0',
                        'credit' => $payAmount,
                        'description' => 'VAT refund '.$return->return_number,
                    ],
                ]
                : [
                    [
                        'account_id' => $vatAccountId,
                        'debit' => $payAmount,
                        'credit' => '0',
                        'description' => 'VAT payment '.$return->return_number,
                    ],
                    [
                        'account_id' => (int) $bank->gl_account_id,
                        'debit' => '0',
                        'credit' => $payAmount,
                        'description' => 'VAT payment '.$return->return_number,
                    ],
                ];

            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $paymentDate,
                'description' => ($isRefund ? 'VAT refund ' : 'VAT payment ').$return->return_number,
                'reference' => $return->filing_reference ?: $return->return_number,
                'currency_code' => $currencyCode,
                'source_type' => 'tax_return_payment',
                'source_id_ref' => (string) $return->id,
                'source_hash' => 'tax_return_payment:'.$return->id,
                'lines' => $lines,
            ], 'tax_settlement');

            $return->update([
                'status' => TaxReturn::STATUS_PAID,
                'payment_amount' => $payAmount,
                'payment_date' => $paymentDate,
                'payment_bank_account_id' => $bank->id,
                'payment_journal_id' => $journal->id,
                'period_locked' => true,
            ]);

            $this->audit->record('tax.return_paid', 'tax_return', $return->id, null, [
                'amount' => $payAmount,
                'payment_date' => $paymentDate,
                'bank_account_id' => $bank->id,
                'journal_id' => $journal->id,
                'refund' => $isRefund,
            ]);

            return $return->fresh();
        });
    }

    /**
     * Simple credit/debit style VAT adjustment (e.g. after a credit note).
     * tax_amount may be negative to reduce output/input.
     */
    public function recordAdjustment(
        AccountingBook $book,
        string $direction,
        string $taxDate,
        string $taxableAmount,
        string $taxAmount,
        string $memo,
        ?string $supplyCategory = 'adjustment'
    ): TaxTransaction {
        $code = $this->ensureDefaultVatCode($book);
        if (! $code) {
            throw ValidationException::withMessages(['tax_code' => 'No VAT tax code configured for this company.']);
        }

        $direction = strtolower(trim($direction)) === 'input' ? 'input' : 'output';
        $category = in_array((string) $supplyCategory, self::SUPPLY_CATEGORIES, true)
            ? (string) $supplyCategory
            : 'adjustment';

        $taxable = Money::normalize($taxableAmount);
        $tax = Money::normalize($taxAmount);
        if (Money::isZero($tax)) {
            throw ValidationException::withMessages(['tax_amount' => 'Adjustment tax amount cannot be zero.']);
        }

        $rate = $this->resolveRate($code, $taxDate);
        $sourceId = (int) (microtime(true) * 1000) % 2000000000;

        $txn = TaxTransaction::withoutGlobalScopes()->create([
            'company_id' => $book->company_id,
            'book_id' => $book->id,
            'tax_code_id' => $code->id,
            'tax_rate_id' => $rate->id,
            'source_type' => 'tax_adjustment',
            'source_id' => $sourceId > 0 ? $sourceId : random_int(1, 999999999),
            'tax_date' => $taxDate,
            'direction' => $direction,
            'supply_category' => $category,
            'taxable_amount' => $taxable,
            'tax_amount' => $tax,
            'rate_percent' => Money::normalize($rate->rate_percent, 4),
            'journal_id' => null,
            'status' => 'posted',
            'memo' => trim($memo) !== '' ? trim($memo) : 'VAT adjustment',
        ]);

        $this->audit->record('tax.adjustment_recorded', 'tax_transaction', $txn->id, null, [
            'direction' => $direction,
            'tax_amount' => $tax,
            'memo' => $txn->memo,
        ]);

        return $txn;
    }

    private function mappedAccountId(int $companyId, int $bookId, string $key): int
    {
        $mapped = AccountMapping::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->where('mapping_key', $key)
            ->value('account_id');
        if ($mapped) {
            return (int) $mapped;
        }

        throw ValidationException::withMessages([
            'account' => "Account mapping '{$key}' is not configured for this book.",
        ]);
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

