<?php

namespace App\Application\Services;

use App\Models\AccountMapping;
use App\Models\AccountingBook;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\PaymentAllocation;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\SupplierPayment;
use App\Models\TaxCode;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayableService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly DocumentNumberService $numbers,
        private readonly AuditTrailService $audit,
        private readonly TaxEngineService $taxEngine,
    ) {}


    public function createAndPostBill(AccountingBook $book, User $user, array $payload): SupplierBill
    {
        if (empty($payload['lines']) || count($payload['lines']) < 1) {
            throw ValidationException::withMessages(['lines' => 'At least one bill line is required.']);
        }

        $apAccountId = $this->mappedAccountId((int) $book->company_id, (int) $book->id, 'accounts_payable');

        return DB::transaction(function () use ($book, $user, $payload, $apAccountId) {
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
                    'expense_account_id' => (int) $line['expense_account_id'],
                ];
            }

            $taxCode = null;
            $taxTotal = Money::normalize($payload['tax_total'] ?? 0);
            if (! empty($payload['tax_code_id'])) {
                $taxCode = TaxCode::withoutGlobalScopes()
                    ->where('company_id', $book->company_id)
                    ->whereKey($payload['tax_code_id'])
                    ->firstOrFail();
                $calc = $this->taxEngine->calculate($taxCode, $subtotal, $payload['bill_date']);
                $taxTotal = $calc['tax_amount'];
            }

            $total = Money::add($subtotal, $taxTotal);
            $number = $this->numbers->next((int) $book->company_id, 'supplier_bill', 'BILL');

            $bill = SupplierBill::withoutGlobalScopes()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'supplier_id' => $payload['supplier_id'],
                'bill_number' => $number,
                'bill_date' => $payload['bill_date'],
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
                SupplierBillLine::query()->create([
                    ...$line,
                    'bill_id' => $bill->id,
                    'company_id' => $book->company_id,
                ]);
            }

            $journalLines = [];
            foreach ($preparedLines as $line) {
                $journalLines[] = [
                    'account_id' => $line['expense_account_id'],
                    'debit' => $line['line_total'],
                    'credit' => '0',
                    'description' => $line['description'],
                ];
            }

            if (! Money::isZero($taxTotal)) {
                $taxAccountId = $this->mappedAccountId((int) $book->company_id, (int) $book->id, 'vat_input');
                $journalLines[] = [
                    'account_id' => $taxAccountId,
                    'debit' => $taxTotal,
                    'credit' => '0',
                    'description' => 'VAT input',
                ];
            }

            $journalLines[] = [
                'account_id' => $apAccountId,
                'debit' => '0',
                'credit' => $total,
                'description' => 'AP '.$bill->bill_number,
            ];

            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $payload['bill_date'],
                'description' => 'Supplier bill '.$bill->bill_number,
                'reference' => $bill->bill_number,
                'currency_code' => $payload['currency_code'],
                'source_type' => 'supplier_bill',
                'source_id_ref' => (string) $bill->id,
                'source_hash' => 'supplier_bill:'.$bill->id,
                'lines' => $journalLines,
            ], 'purchase_bill');

            if ($taxCode && ! Money::isZero($taxTotal)) {
                $this->taxEngine->recordTransaction(
                    $book,
                    $taxCode,
                    'input',
                    $subtotal,
                    $payload['bill_date'],
                    'supplier_bill',
                    (int) $bill->id,
                    (int) $journal->id
                );
            }

            $bill->update([
                'status' => 'posted',
                'posted_journal_id' => $journal->id,
            ]);

            $this->audit->record('bill.posted', 'supplier_bill', $bill->id, null, [
                'bill_number' => $bill->bill_number,
                'total' => $total,
            ]);

            return $bill->load(['lines', 'supplier']);
        });
    }

    public function createAndPostPayment(AccountingBook $book, User $user, array $payload): SupplierPayment
    {
        $apAccountId = $this->mappedAccountId((int) $book->company_id, (int) $book->id, 'accounts_payable');
        $amount = Money::normalize($payload['amount']);
        $allocations = $payload['allocations'] ?? [];

        $allocated = '0.0000';
        foreach ($allocations as $allocation) {
            $allocated = Money::add($allocated, Money::normalize($allocation['amount'] ?? 0));
        }

        if (! Money::equals($allocated, $amount) && count($allocations) > 0) {
            throw ValidationException::withMessages([
                'allocations' => 'Allocation total must equal payment amount.',
            ]);
        }

        $bank = BankAccount::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('id', $payload['bank_account_id'])
            ->firstOrFail();

        return DB::transaction(function () use ($book, $user, $payload, $apAccountId, $amount, $allocations, $bank) {
            $number = $this->numbers->next((int) $book->company_id, 'supplier_payment', 'PAY');

            $payment = SupplierPayment::withoutGlobalScopes()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'supplier_id' => $payload['supplier_id'],
                'payment_number' => $number,
                'payment_date' => $payload['payment_date'],
                'currency_code' => $payload['currency_code'],
                'amount' => $amount,
                'bank_account_id' => $bank->id,
                'status' => 'draft',
                'reference' => $payload['reference'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($allocations as $allocation) {
                $bill = SupplierBill::withoutGlobalScopes()
                    ->where('company_id', $book->company_id)
                    ->where('id', $allocation['bill_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $allocAmount = Money::normalize($allocation['amount']);
                if (bccomp($allocAmount, Money::normalize($bill->balance_due), 4) === 1) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Allocation exceeds bill balance for '.$bill->bill_number,
                    ]);
                }

                PaymentAllocation::query()->create([
                    'company_id' => $book->company_id,
                    'payment_id' => $payment->id,
                    'bill_id' => $bill->id,
                    'amount' => $allocAmount,
                ]);

                $newPaid = Money::add(Money::normalize($bill->amount_paid), $allocAmount);
                $newBalance = Money::normalize(bcsub(Money::normalize($bill->total), $newPaid, 4));
                $bill->update([
                    'amount_paid' => $newPaid,
                    'balance_due' => $newBalance,
                    'status' => Money::isZero($newBalance) ? 'paid' : 'partial',
                ]);
            }

            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $payload['payment_date'],
                'description' => 'Supplier payment '.$payment->payment_number,
                'reference' => $payment->payment_number,
                'currency_code' => $payload['currency_code'],
                'source_type' => 'supplier_payment',
                'source_id_ref' => (string) $payment->id,
                'source_hash' => 'supplier_payment:'.$payment->id,
                'lines' => [
                    [
                        'account_id' => $apAccountId,
                        'debit' => $amount,
                        'credit' => '0',
                        'description' => 'Settle AP',
                    ],
                    [
                        'account_id' => $bank->gl_account_id,
                        'debit' => '0',
                        'credit' => $amount,
                        'description' => 'Payment '.$payment->payment_number,
                    ],
                ],
            ], 'supplier_payment');

            $payment->update([
                'status' => 'posted',
                'posted_journal_id' => $journal->id,
            ]);

            BankTransaction::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'bank_account_id' => $bank->id,
                'transaction_date' => $payload['payment_date'],
                'description' => 'Payment '.$payment->payment_number,
                'debit' => '0',
                'credit' => $amount,
                'reference' => $payment->payment_number,
                'source_type' => 'supplier_payment',
                'source_id' => $payment->id,
                'journal_id' => $journal->id,
                'is_reconciled' => false,
            ]);

            $this->audit->record('payment.posted', 'supplier_payment', $payment->id, null, [
                'payment_number' => $payment->payment_number,
                'amount' => $amount,
            ]);

            return $payment->load(['allocations', 'supplier']);
        });
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
}
