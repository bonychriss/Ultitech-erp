<?php

namespace App\Application\Services;

use App\Models\AccountMapping;
use App\Models\AccountingBook;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceLine;
use App\Models\CustomerReceipt;
use App\Models\ReceiptAllocation;
use App\Models\TaxCode;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceivableService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly DocumentNumberService $numbers,
        private readonly AuditTrailService $audit,
        private readonly TaxEngineService $taxEngine,
    ) {}

    public function createAndPostInvoice(AccountingBook $book, User $user, array $payload): CustomerInvoice
    {
        if (empty($payload['lines']) || count($payload['lines']) < 1) {
            throw ValidationException::withMessages(['lines' => 'At least one invoice line is required.']);
        }

        $arAccountId = $this->mappedAccountId((int) $book->company_id, (int) $book->id, 'accounts_receivable');

        return DB::transaction(function () use ($book, $user, $payload, $arAccountId) {
            $subtotal = '0.0000';
            $preparedLines = [];

            foreach ($payload['lines'] as $index => $line) {
                $qty = Money::normalize($line['quantity'] ?? 1);
                $price = Money::normalize($line['unit_price'] ?? 0);
                $lineTotal = Money::multiply($qty, $price);
                $subtotal = Money::add($subtotal, $lineTotal);
                $preparedLines[] = [
                    'line_no' => $index + 1,
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'line_total' => $lineTotal,
                    'revenue_account_id' => (int) $line['revenue_account_id'],
                ];
            }

            $taxCode = null;
            $taxTotal = Money::normalize($payload['tax_total'] ?? 0);
            if (! empty($payload['tax_code_id'])) {
                $taxCode = TaxCode::withoutGlobalScopes()
                    ->where('company_id', $book->company_id)
                    ->whereKey($payload['tax_code_id'])
                    ->firstOrFail();
                $calc = $this->taxEngine->calculate($taxCode, $subtotal, $payload['invoice_date']);
                $taxTotal = $calc['tax_amount'];
            }

            $total = Money::add($subtotal, $taxTotal);
            $number = $this->numbers->next((int) $book->company_id, 'customer_invoice', 'INV');

            $invoice = CustomerInvoice::withoutGlobalScopes()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'customer_id' => $payload['customer_id'],
                'invoice_number' => $number,
                'invoice_date' => $payload['invoice_date'],
                'due_date' => $payload['due_date'] ?? null,
                'currency_code' => $payload['currency_code'],
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'total' => $total,
                'amount_paid' => '0.0000',
                'balance_due' => $total,
                'status' => 'draft',
                'description' => $payload['description'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($preparedLines as $line) {
                CustomerInvoiceLine::query()->create([
                    ...$line,
                    'invoice_id' => $invoice->id,
                    'company_id' => $book->company_id,
                ]);
            }

            $journalLines = [
                [
                    'account_id' => $arAccountId,
                    'debit' => $total,
                    'credit' => '0',
                    'description' => 'AR '.$invoice->invoice_number,
                ],
            ];

            foreach ($preparedLines as $line) {
                $journalLines[] = [
                    'account_id' => $line['revenue_account_id'],
                    'debit' => '0',
                    'credit' => $line['line_total'],
                    'description' => $line['description'],
                ];
            }

            if (! Money::isZero($taxTotal)) {
                $taxAccountId = $this->mappedAccountId((int) $book->company_id, (int) $book->id, 'vat_output');
                $journalLines[] = [
                    'account_id' => $taxAccountId,
                    'debit' => '0',
                    'credit' => $taxTotal,
                    'description' => 'VAT output',
                ];
            }

            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $payload['invoice_date'],
                'description' => 'Sales invoice '.$invoice->invoice_number,
                'reference' => $invoice->invoice_number,
                'currency_code' => $payload['currency_code'],
                'source_type' => 'customer_invoice',
                'source_id_ref' => (string) $invoice->id,
                'source_hash' => 'customer_invoice:'.$invoice->id,
                'lines' => $journalLines,
            ], 'sales_invoice');

            if ($taxCode && ! Money::isZero($taxTotal)) {
                $this->taxEngine->recordTransaction(
                    $book,
                    $taxCode,
                    'output',
                    $subtotal,
                    $payload['invoice_date'],
                    'customer_invoice',
                    (int) $invoice->id,
                    (int) $journal->id
                );
            }

            $invoice->update([
                'status' => 'posted',
                'posted_journal_id' => $journal->id,
            ]);

            $this->audit->record('invoice.posted', 'customer_invoice', $invoice->id, null, [
                'invoice_number' => $invoice->invoice_number,
                'total' => $total,
            ]);

            return $invoice->load(['lines', 'customer']);
        });
    }

    /**
     * Cash / other income: post AR invoice then settle it immediately (Dr bank/cash, Cr revenue).
     *
     * @param  array{
     *   revenue_date:string,
     *   currency_code?:string,
     *   description:string,
     *   amount:float|string,
     *   revenue_account_id:int,
     *   customer_id?:int|null,
     *   bank_account_id?:int|null,
     *   cash_account_id?:int|null,
     *   reference?:string|null
     * }  $payload
     * @return array{invoice:CustomerInvoice,receipt:CustomerReceipt}
     */
    public function createAndPostManualRevenue(AccountingBook $book, User $user, array $payload): array
    {
        $amount = Money::normalize($payload['amount'] ?? 0);
        if (Money::isZero($amount) || bccomp($amount, '0', 4) !== 1) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        $hasBank = ! empty($payload['bank_account_id']);
        $hasCash = ! empty($payload['cash_account_id']);
        if ($hasBank === $hasCash) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'Choose either a bank/mobile account or a cash account.',
            ]);
        }

        $customerId = (int) ($payload['customer_id'] ?? 0);
        if ($customerId <= 0) {
            $customerId = (int) $this->ensureWalkInCustomer($book)->id;
        }

        $date = (string) $payload['revenue_date'];
        $currency = strtoupper((string) ($payload['currency_code'] ?? 'TZS'));
        $description = trim((string) ($payload['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages(['description' => 'Description is required.']);
        }

        return DB::transaction(function () use ($book, $user, $payload, $amount, $customerId, $date, $currency, $description, $hasBank) {
            $invoice = $this->createAndPostInvoice($book, $user, [
                'customer_id' => $customerId,
                'invoice_date' => $date,
                'currency_code' => $currency,
                'description' => $description,
                'lines' => [[
                    'description' => $description,
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'revenue_account_id' => (int) $payload['revenue_account_id'],
                ]],
            ]);

            $receiptPayload = [
                'customer_id' => $customerId,
                'receipt_date' => $date,
                'currency_code' => $currency,
                'amount' => $amount,
                'reference' => $payload['reference'] ?? null,
                'allocations' => [[
                    'invoice_id' => (int) $invoice->id,
                    'amount' => $amount,
                ]],
            ];
            if ($hasBank) {
                $receiptPayload['bank_account_id'] = (int) $payload['bank_account_id'];
            } else {
                $receiptPayload['cash_account_id'] = (int) $payload['cash_account_id'];
            }

            $receipt = $this->createAndPostReceipt($book, $user, $receiptPayload);

            $this->audit->record('manual_revenue.posted', 'customer_invoice', $invoice->id, null, [
                'invoice_number' => $invoice->invoice_number,
                'receipt_number' => $receipt->receipt_number,
                'amount' => $amount,
            ]);

            return [
                'invoice' => $invoice->fresh(['lines', 'customer']) ?? $invoice,
                'receipt' => $receipt,
            ];
        });
    }

    public function createAndPostReceipt(AccountingBook $book, User $user, array $payload): CustomerReceipt
    {
        $arAccountId = $this->mappedAccountId((int) $book->company_id, (int) $book->id, 'accounts_receivable');
        $amount = Money::normalize($payload['amount']);
        $allocations = $payload['allocations'] ?? [];

        $allocated = '0.0000';
        foreach ($allocations as $allocation) {
            $allocated = Money::add($allocated, Money::normalize($allocation['amount'] ?? 0));
        }

        if (! Money::equals($allocated, $amount) && count($allocations) > 0) {
            throw ValidationException::withMessages([
                'allocations' => 'Allocation total must equal receipt amount.',
            ]);
        }

        $cashGlId = $this->resolveCashGl($book, $payload);

        return DB::transaction(function () use ($book, $user, $payload, $arAccountId, $amount, $allocations, $cashGlId) {
            $number = $this->numbers->next((int) $book->company_id, 'customer_receipt', 'RCT');

            $receipt = CustomerReceipt::withoutGlobalScopes()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'customer_id' => $payload['customer_id'],
                'receipt_number' => $number,
                'receipt_date' => $payload['receipt_date'],
                'currency_code' => $payload['currency_code'],
                'amount' => $amount,
                'bank_account_id' => $payload['bank_account_id'] ?? null,
                'cash_account_id' => $payload['cash_account_id'] ?? null,
                'status' => 'draft',
                'reference' => $payload['reference'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($allocations as $allocation) {
                $invoice = CustomerInvoice::withoutGlobalScopes()
                    ->where('company_id', $book->company_id)
                    ->where('id', $allocation['invoice_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $allocAmount = Money::normalize($allocation['amount']);
                if (bccomp($allocAmount, Money::normalize($invoice->balance_due), 4) === 1) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Allocation exceeds invoice balance for '.$invoice->invoice_number,
                    ]);
                }

                ReceiptAllocation::query()->create([
                    'company_id' => $book->company_id,
                    'receipt_id' => $receipt->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $allocAmount,
                ]);

                $newPaid = Money::add(Money::normalize($invoice->amount_paid), $allocAmount);
                $newBalance = Money::normalize(bcsub(Money::normalize($invoice->total), $newPaid, 4));
                $invoice->update([
                    'amount_paid' => $newPaid,
                    'balance_due' => $newBalance,
                    'status' => Money::isZero($newBalance) ? 'paid' : 'partial',
                ]);
            }

            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $payload['receipt_date'],
                'description' => 'Customer receipt '.$receipt->receipt_number,
                'reference' => $receipt->receipt_number,
                'currency_code' => $payload['currency_code'],
                'source_type' => 'customer_receipt',
                'source_id_ref' => (string) $receipt->id,
                'source_hash' => 'customer_receipt:'.$receipt->id,
                'lines' => [
                    [
                        'account_id' => $cashGlId,
                        'debit' => $amount,
                        'credit' => '0',
                        'description' => 'Receipt '.$receipt->receipt_number,
                    ],
                    [
                        'account_id' => $arAccountId,
                        'debit' => '0',
                        'credit' => $amount,
                        'description' => 'Settle AR',
                    ],
                ],
            ], 'customer_receipt');

            $receipt->update([
                'status' => 'posted',
                'posted_journal_id' => $journal->id,
            ]);

            if (! empty($payload['bank_account_id'])) {
                BankTransaction::withoutGlobalScopes()->create([
                    'company_id' => $book->company_id,
                    'bank_account_id' => $payload['bank_account_id'],
                    'transaction_date' => $payload['receipt_date'],
                    'description' => 'Receipt '.$receipt->receipt_number,
                    'debit' => $amount,
                    'credit' => '0',
                    'reference' => $receipt->receipt_number,
                    'source_type' => 'customer_receipt',
                    'source_id' => $receipt->id,
                    'journal_id' => $journal->id,
                    'is_reconciled' => false,
                ]);
            }

            $this->audit->record('receipt.posted', 'customer_receipt', $receipt->id, null, [
                'receipt_number' => $receipt->receipt_number,
                'amount' => $amount,
            ]);

            return $receipt->load(['allocations', 'customer']);
        });
    }

    private function ensureWalkInCustomer(AccountingBook $book): Customer
    {
        $existing = Customer::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('code', 'WALKIN')
            ->first();
        if ($existing) {
            return $existing;
        }

        return Customer::withoutGlobalScopes()->create([
            'company_id' => $book->company_id,
            'code' => 'WALKIN',
            'name' => 'Walk-in / Other income',
            'currency_code' => 'TZS',
            'status' => 'active',
        ]);
    }

    private function mappedAccountId(int $companyId, int $bookId, string $key): int
    {
        $mapping = AccountMapping::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->where('mapping_key', $key)
            ->first();

        if (! $mapping) {
            throw ValidationException::withMessages([
                'mapping' => "Account mapping '{$key}' is not configured for this book.",
            ]);
        }

        return (int) $mapping->account_id;
    }

    private function resolveCashGl(AccountingBook $book, array $payload): int
    {
        if (! empty($payload['bank_account_id'])) {
            $bank = BankAccount::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('id', $payload['bank_account_id'])
                ->firstOrFail();

            return (int) $bank->gl_account_id;
        }

        if (! empty($payload['cash_account_id'])) {
            $cash = CashAccount::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('id', $payload['cash_account_id'])
                ->firstOrFail();

            return (int) $cash->gl_account_id;
        }

        return $this->mappedAccountId((int) $book->company_id, (int) $book->id, 'cash');
    }
}
