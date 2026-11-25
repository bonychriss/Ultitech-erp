<?php

namespace App\Application\Services;

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Ensures an ERP-linked company has a usable CoA + bank/cash desks.
 *
 * Preferred method: auto-generate a standard hierarchical chart of accounts.
 */
final class CompanyBootstrapService
{
    /** @var list<string> */
    public const STANDARD_CODES = [
        '1000', '1001', '1002', '1003', '1200', '1300',
        '2000', '2001', '2100', '2200',
        '3000', '3001', '3002',
        '4000', '4001', '4002', '4003',
        '5000', '5001', '5002', '5003', '5004',
    ];

    public function hydrateForErpCompany(Company $company, User $user): void
    {
        $book = AccountingBook::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_primary', true)
            ->first();

        if (! $book) {
            return;
        }

        // Always ensure the standard template exists (headers + posting accounts).
        $this->ensureStandardChart($company, $book);

        // Template CoA is the base. Pull ERP master data + wallets + GL journals.
        // Journals are required so Trial Balance / Accounting GL stay complete;
        // syncJournals is idempotent (source_hash) and cursor-scans past imports.
        if (config('erp.enabled')) {
            try {
                $journalLimit = (int) config('erp.journal_limit', 500);
                app(ErpSyncService::class)->pull(
                    $book,
                    $user,
                    ['customers', 'suppliers', 'invoices', 'wallets', 'journals'],
                    max(50, $journalLimit)
                );
            } catch (\Throwable $e) {
                Log::warning('ERP auto-pull on bridge failed: '.$e->getMessage());
            }
        }

        $hasBank = BankAccount::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('book_id', $book->id)
            ->where('status', 'active')
            ->exists();
        $hasCash = CashAccount::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('book_id', $book->id)
            ->where('status', 'active')
            ->exists();

        if (! $hasBank || ! $hasCash) {
            $this->ensureBankingDefaults($company, $book);
        }
    }

    /**
     * Backward-compatible alias.
     */
    public function ensureMinimalChart(Company $company, AccountingBook $book): void
    {
        $this->ensureStandardChart($company, $book);
    }

    /**
     * Auto-generate standard CoA:
     * 1000 Assets → Cash/Bank/Mobile/AR/Inventory
     * 2000 Liabilities → AP/Loans/Tax
     * 3000 Equity → Capital/RE
     * 4000 Revenue → Sales/Service/Other
     * 5000 Expenses → Rent/Salaries/Utilities/Transport
     */
    public function ensureStandardChart(Company $company, AccountingBook $book): void
    {
        $types = AccountType::query()->pluck('id', 'code');
        if ($types->isEmpty()) {
            return;
        }

        // code, name, type, normal_balance, is_postable, parent_code|null
        $rows = [
            ['1000', 'Assets', 'asset', 'debit', false, null],
            ['1001', 'Cash', 'asset', 'debit', true, '1000'],
            ['1002', 'Bank', 'asset', 'debit', true, '1000'],
            ['1003', 'Mobile Money', 'asset', 'debit', true, '1000'],
            ['1200', 'Accounts Receivable', 'asset', 'debit', true, '1000'],
            ['1300', 'Inventory', 'asset', 'debit', true, '1000'],

            ['2000', 'Liabilities', 'liability', 'credit', false, null],
            ['2001', 'Accounts Payable', 'liability', 'credit', true, '2000'],
            ['2100', 'Loans Payable', 'liability', 'credit', true, '2000'],
            ['2200', 'Tax Payable', 'liability', 'credit', true, '2000'],

            ['3000', 'Equity', 'equity', 'credit', false, null],
            ['3001', 'Capital', 'equity', 'credit', true, '3000'],
            ['3002', 'Retained Earnings', 'equity', 'credit', true, '3000'],

            ['4000', 'Revenue', 'revenue', 'credit', false, null],
            ['4001', 'Sales Revenue', 'revenue', 'credit', true, '4000'],
            ['4002', 'Service Revenue', 'revenue', 'credit', true, '4000'],
            ['4003', 'Other Income', 'revenue', 'credit', true, '4000'],

            ['5000', 'Expenses', 'expense', 'debit', false, null],
            ['5001', 'Rent', 'expense', 'debit', true, '5000'],
            ['5002', 'Salaries', 'expense', 'debit', true, '5000'],
            ['5003', 'Utilities', 'expense', 'debit', true, '5000'],
            ['5004', 'Transport', 'expense', 'debit', true, '5000'],
        ];

        $ids = [];
        // First pass: headers + accounts without depending on parent ids.
        foreach ($rows as [$code, $name, $type, $balance, $postable, $parentCode]) {
            if (! isset($types[$type])) {
                continue;
            }
            $account = Account::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'book_id' => $book->id, 'code' => $code],
                [
                    'account_type_id' => $types[$type],
                    'name' => $name,
                    'normal_balance' => $balance,
                    'is_postable' => $postable,
                    // Entire standard template is system-locked (used by posting maps).
                    'is_system' => true,
                    'allow_manual_entry' => $postable,
                    'status' => 'active',
                    'level' => $parentCode ? 2 : 1,
                    'parent_id' => null,
                    'path' => 'template',
                ]
            );
            $ids[$code] = $account->id;
        }

        // Second pass: wire parent_id.
        foreach ($rows as [$code, $name, $type, $balance, $postable, $parentCode]) {
            if (! $parentCode || ! isset($ids[$code], $ids[$parentCode])) {
                continue;
            }
            Account::withoutGlobalScopes()
                ->where('id', $ids[$code])
                ->update([
                    'parent_id' => $ids[$parentCode],
                    'level' => 2,
                ]);
        }

        foreach ([
            'cash' => '1001',
            'bank' => '1002',
            'mobile_money' => '1003',
            'accounts_receivable' => '1200',
            'inventory' => '1300',
            'accounts_payable' => '2001',
            'loans_payable' => '2100',
            'tax_payable' => '2200',
            'vat_output' => '2200',
            'capital' => '3001',
            'retained_earnings' => '3002',
            'sales_revenue' => '4001',
            'service_revenue' => '4002',
            'other_income' => '4003',
            'rent_expense' => '5001',
            'salaries_expense' => '5002',
            'utilities_expense' => '5003',
            'transport_expense' => '5004',
            // legacy mapping keys used by older services
            'purchases' => '5004',
            'vat_input' => '1200',
        ] as $key => $code) {
            if (! isset($ids[$code])) {
                continue;
            }
            AccountMapping::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'book_id' => $book->id, 'mapping_key' => $key],
                ['account_id' => $ids[$code]]
            );
        }
    }

    public function ensureBankingDefaults(Company $company, AccountingBook $book): void
    {
        $currency = $company->base_currency_code ?: 'TZS';

        $cashGl = $this->resolveGl($company->id, $book->id, ['cash', '1001', 'Cash']);
        $bankGl = $this->resolveGl($company->id, $book->id, ['bank', '1002', 'Bank']);

        if (! $cashGl || ! $bankGl) {
            $this->ensureStandardChart($company, $book);
            $cashGl = $this->resolveGl($company->id, $book->id, ['cash', '1001', 'Cash']);
            $bankGl = $this->resolveGl($company->id, $book->id, ['bank', '1002', 'Bank']);
        }

        if ($bankGl) {
            BankAccount::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'book_id' => $book->id, 'code' => 'MAIN-BANK'],
                [
                    'name' => 'Main Operating Bank',
                    'bank_name' => 'Primary Bank',
                    'account_number' => '',
                    'currency_code' => $currency,
                    'gl_account_id' => $bankGl->id,
                    'account_type' => 'bank',
                    'status' => 'active',
                ]
            );
        }

        if ($cashGl) {
            CashAccount::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'book_id' => $book->id, 'code' => 'PETTY'],
                [
                    'name' => 'Petty Cash',
                    'currency_code' => $currency,
                    'gl_account_id' => $cashGl->id,
                    'status' => 'active',
                ]
            );
        }
    }

    /**
     * @param  list<string>  $hints
     */
    private function resolveGl(int $companyId, int $bookId, array $hints): ?Account
    {
        foreach ($hints as $hint) {
            $mapped = AccountMapping::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('book_id', $bookId)
                ->where('mapping_key', $hint)
                ->first();
            if ($mapped) {
                $account = Account::withoutGlobalScopes()->find($mapped->account_id);
                if ($account) {
                    return $account;
                }
            }
        }

        foreach ($hints as $hint) {
            $account = Account::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('book_id', $bookId)
                ->where(function ($q) use ($hint) {
                    $q->where('code', $hint)->orWhere('name', 'like', '%'.$hint.'%');
                })
                ->orderBy('code')
                ->first();
            if ($account) {
                return $account;
            }
        }

        return Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->whereHas('accountType', fn ($q) => $q->where('code', 'asset'))
            ->where('is_postable', true)
            ->orderBy('code')
            ->first();
    }
}
