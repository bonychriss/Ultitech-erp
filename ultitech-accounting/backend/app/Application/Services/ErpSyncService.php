<?php

namespace App\Application\Services;

use App\Integration\Erp\ReadOnlyErpGateway;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceLine;
use App\Models\ErpConnection;
use App\Models\ErpIdMap;
use App\Models\ErpSyncRun;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ErpSyncService
{
    public function __construct(
        private readonly ReadOnlyErpGateway $erp,
        private readonly JournalPostingService $posting,
        private readonly DocumentNumberService $numbers,
        private readonly AuditTrailService $audit,
        private readonly AccountingPeriodService $periods,
        private readonly TaxEngineService $taxEngine,
    ) {}

    public function ensureConnection(int $companyId): ErpConnection
    {
        return ErpConnection::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'code' => 'ULTIMATE'],
            [
                'name' => 'UltiTech ERP — Ultimate tenant',
                'connection_name' => config('erp.connection', 'erp_ultimate'),
                'erp_database' => config('erp.default_database'),
                'read_only' => true,
                'is_active' => true,
            ]
        );
    }

    public function test(ErpConnection $connection): array
    {
        $result = $this->erp->testConnection();
        $connection->update([
            'last_tested_at' => now(),
            'last_test_status' => $result['ok'] ? 'ok' : 'failed',
            'last_test_message' => $result['message'],
        ]);

        return $result;
    }

    /**
     * Refresh VAT register: Accounting invoice output tax + ERP purchase input tax.
     *
     * @return array{output:array{created:int,updated:int,removed:int,skipped:int},input_synced:int,erp_error:?string}
     */
    public function syncTaxRegister(AccountingBook $book, User $user): array
    {
        unset($user);
        $output = $this->taxEngine->backfillFromCustomerInvoices($book);
        $inputSynced = 0;
        $erpError = null;

        try {
            if (config('erp.enabled')) {
                $connection = $this->ensureConnection((int) $book->company_id);
                $this->bindErpDatabaseForCompany((int) $book->company_id, $connection);
                $test = $this->test($connection);
                if (! ($test['ok'] ?? false)) {
                    throw new \RuntimeException('ERP connection failed: '.($test['message'] ?? 'unknown'));
                }
                $inputSynced = $this->syncPurchaseTaxTransactions($book, $connection);
            }
        } catch (\Throwable $e) {
            $erpError = $e->getMessage();
        }

        return [
            'output' => $output,
            'input_synced' => $inputSynced,
            'erp_error' => $erpError,
        ];
    }

    /**
     * Pull ERP data into UltiTech Accounting only. Never writes to ERP.
     *
     * @param  array<int, string>  $scopes  accounts|customers|suppliers|invoices|journals|wallets
     */
    public function pull(AccountingBook $book, User $user, array $scopes = ['accounts', 'customers', 'suppliers', 'invoices', 'journals'], ?int $journalLimit = null): ErpSyncRun
    {
        if (! config('erp.enabled')) {
            throw ValidationException::withMessages([
                'erp' => 'ERP sync is disabled. Set ERP_SYNC_ENABLED=true in Accounting .env to enable read-only pull.',
            ]);
        }

        $connection = $this->ensureConnection((int) $book->company_id);
        $this->bindErpDatabaseForCompany((int) $book->company_id, $connection);
        $test = $this->test($connection);
        if (! ($test['ok'] ?? false)) {
            throw ValidationException::withMessages(['erp' => 'ERP connection failed: '.($test['message'] ?? 'unknown')]);
        }

        $limit = $journalLimit ?? (int) config('erp.journal_limit', 500);
        $run = ErpSyncRun::withoutGlobalScopes()->create([
            'company_id' => $book->company_id,
            'book_id' => $book->id,
            'connection_id' => $connection->id,
            'run_number' => $this->numbers->next((int) $book->company_id, 'erp_sync', 'ERP'),
            'mode' => 'pull_readonly',
            'scopes_json' => $scopes,
            'status' => 'running',
            'started_by' => $user->id,
            'started_at' => now(),
        ]);

        $stats = [
            'accounts_upserted' => 0,
            'balances_accounts_upserted' => 0,
            'bank_wallets_upserted' => 0,
            'cash_wallets_upserted' => 0,
            'customers_upserted' => 0,
            'suppliers_upserted' => 0,
            'invoices_upserted' => 0,
            'tax_output_synced' => 0,
            'tax_input_synced' => 0,
            'journals_imported' => 0,
            'journals_skipped' => 0,
            'invoices_linked_to_journals' => 0,
            'errors' => [],
        ];

        try {
            // Master data in one transaction. Journals post via JournalPostingService
            // (own transactions) outside so a large GL backfill cannot hold one lock.
            DB::connection()->transaction(function () use ($book, $user, $connection, $scopes, &$stats) {
                if (in_array('accounts', $scopes, true)) {
                    $stats['accounts_upserted'] = $this->syncAccounts($book, $connection);
                    $stats['balances_accounts_upserted'] = $this->syncFinancialAccounts($book, $connection);
                }
                if (in_array('accounts', $scopes, true) || in_array('wallets', $scopes, true)) {
                    if (in_array('wallets', $scopes, true) && ! in_array('accounts', $scopes, true)) {
                        $stats['balances_accounts_upserted'] = $this->syncFinancialWalletAccounts($book, $connection);
                    }
                    [$banks, $cash] = $this->syncBalancesWallets($book, $connection);
                    $stats['bank_wallets_upserted'] = $banks;
                    $stats['cash_wallets_upserted'] = $cash;
                }
                if (in_array('customers', $scopes, true)) {
                    $stats['customers_upserted'] = $this->syncCustomers($book, $connection);
                }
                if (in_array('suppliers', $scopes, true)) {
                    $stats['suppliers_upserted'] = $this->syncSuppliers($book, $connection);
                }
                if (in_array('invoices', $scopes, true)) {
                    // Customers first so invoice FK resolves even when only invoices was requested.
                    if (! in_array('customers', $scopes, true)) {
                        $stats['customers_upserted'] = $this->syncCustomers($book, $connection);
                    }
                    $stats['invoices_upserted'] = $this->syncInvoices($book, $user, $connection);
                    $taxOut = $this->taxEngine->backfillFromCustomerInvoices($book);
                    $stats['tax_output_synced'] = (int) (($taxOut['created'] ?? 0) + ($taxOut['updated'] ?? 0));
                    $stats['tax_input_synced'] = $this->syncPurchaseTaxTransactions($book, $connection);
                }
            });

            if (in_array('journals', $scopes, true)) {
                [$imported, $skipped, $errors] = $this->syncJournals($book, $user, $connection, $limit);
                $stats['journals_imported'] = $imported;
                $stats['journals_skipped'] = $skipped;
                $stats['errors'] = array_values(array_merge($stats['errors'], $errors));
                $stats['invoices_linked_to_journals'] = $this->linkMirroredInvoicesToImportedJournals($book);
            }

            $run->update([
                'stats_json' => $stats,
                'status' => empty($stats['errors']) ? 'completed' : 'completed_with_errors',
                'message' => 'Read-only pull finished. ERP databases were not modified.',
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'stats_json' => $stats,
                'status' => 'failed',
                'message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            throw $e;
        }

        $this->audit->record('erp.sync_pull', 'erp_sync_run', $run->id, null, $stats);

        return $run->fresh();
    }

    private function syncAccounts(AccountingBook $book, ErpConnection $connection): int
    {
        if (! $this->erp->tableExists('erp_accounts')) {
            return 0;
        }

        $types = AccountType::query()->pluck('id', 'code');
        $rows = $this->erp->select('SELECT id, code, name, type, is_postable, status FROM erp_accounts ORDER BY id');
        $count = 0;

        foreach ($rows as $row) {
            $type = strtolower((string) ($row->type ?? 'asset'));
            if (! isset($types[$type])) {
                $type = 'expense';
            }
            $code = trim((string) ($row->code ?: 'ERP'.$row->id));
            $normal = in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';

            $existing = Account::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('code', $code)
                ->first();
            if ($existing && in_array((string) $existing->path, ['template', 'user', 'banking'], true)) {
                $target = $existing;
                // Never map a postable ERP account onto a non-postable template header
                // (e.g. ERP 2000 AP → template 2000 Liabilities). Prefer a postable child.
                if ((bool) ($row->is_postable ?? true) && ! $existing->is_postable) {
                    $target = $this->findPostableCounterpart($book, $existing, (string) ($row->name ?? '')) ?? $existing;
                }
                $this->map($connection, (int) $book->company_id, 'account', (string) $row->id, (int) $target->id, [
                    'code' => $code,
                    'mapped_via' => $target->id === $existing->id ? 'code' : 'postable_counterpart',
                ]);
                $count++;
                continue;
            }

            $account = Account::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $book->company_id,
                    'book_id' => $book->id,
                    'code' => $code,
                ],
                [
                    'account_type_id' => $types[$type],
                    'name' => $row->name ?: $code,
                    'normal_balance' => $normal,
                    'is_postable' => (bool) ($row->is_postable ?? true),
                    'allow_manual_entry' => true,
                    'status' => (strtolower((string) ($row->status ?? 'active')) === 'inactive') ? 'inactive' : 'active',
                    'level' => 1,
                    'is_system' => false,
                    'path' => 'erp',
                ]
            );

            $this->map($connection, (int) $book->company_id, 'account', (string) $row->id, (int) $account->id, [
                'code' => $code,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Pull only Balances cash/bank/mobile wallets into local asset GL + id maps.
     */
    private function syncFinancialWalletAccounts(AccountingBook $book, ErpConnection $connection): int
    {
        if (! $this->erp->tableExists('financial_accounts')) {
            return 0;
        }

        $types = AccountType::query()->pluck('id', 'code');
        if (! isset($types['asset'])) {
            return 0;
        }

        $rows = $this->erp->select(
            "SELECT id, parent_id, name, type, currency, status, is_system
             FROM financial_accounts
             WHERE status = 'active' AND type IN ('bank', 'cash', 'mobile')
             ORDER BY id"
        );

        $count = 0;
        foreach ($rows as $row) {
            [$parsedCode, $label] = $this->parseBalancesAccountName((string) $row->name, (int) $row->id);
            $faType = strtolower((string) ($row->type ?? 'bank'));
            $deskCode = 'FA-'.$row->id;
            $code = $deskCode;
            $exists = Account::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('code', $code)
                ->exists();
            if ($exists) {
                $code = $parsedCode.'-FA'.$row->id;
            }

            $account = Account::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $book->company_id,
                    'book_id' => $book->id,
                    'code' => substr($code, 0, 40),
                ],
                [
                    'account_type_id' => $types['asset'],
                    'name' => $label !== '' ? $label : (string) $row->name,
                    'normal_balance' => 'debit',
                    'is_postable' => true,
                    'allow_manual_entry' => true,
                    'status' => 'active',
                    'level' => 2,
                    'is_system' => false,
                    'path' => 'erp',
                ]
            );

            $this->map($connection, (int) $book->company_id, 'financial_account', (string) $row->id, (int) $account->id, [
                'code' => $account->code,
                'fa_type' => $faType,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Pull Balances module Chart of Accounts (`financial_accounts`) — same source as
     * modules/balances/accounts.php — into UltiTech CoA.
     */
    private function syncFinancialAccounts(AccountingBook $book, ErpConnection $connection): int
    {
        if (! $this->erp->tableExists('financial_accounts')) {
            return 0;
        }

        $types = AccountType::query()->pluck('id', 'code');
        $rows = $this->erp->select(
            "SELECT id, parent_id, name, type, currency, status, is_system
             FROM financial_accounts
             WHERE status = 'active'
             ORDER BY id"
        );
        $count = 0;
        $usedCodes = Account::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->pluck('code')
            ->flip()
            ->all();

        foreach ($rows as $row) {
            [$parsedCode, $label] = $this->parseBalancesAccountName((string) $row->name, (int) $row->id);
            $faType = strtolower((string) ($row->type ?? 'asset'));
            $glType = in_array($faType, ['cash', 'bank', 'mobile'], true) ? 'asset' : $faType;
            if (! isset($types[$glType])) {
                $glType = 'asset';
            }

            $code = $parsedCode;
            if (isset($usedCodes[$code])) {
                $code = $parsedCode.'-FA'.$row->id;
            }
            $usedCodes[$code] = true;

            $normal = in_array($glType, ['asset', 'expense'], true) ? 'debit' : 'credit';
            $existing = Account::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('code', $code)
                ->first();
            if ($existing && in_array((string) $existing->path, ['template', 'user', 'banking'], true)) {
                $this->map($connection, (int) $book->company_id, 'financial_account', (string) $row->id, (int) $existing->id, [
                    'code' => $code,
                    'fa_type' => $faType,
                ]);
                $count++;
                continue;
            }

            $account = Account::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $book->company_id,
                    'book_id' => $book->id,
                    'code' => $code,
                ],
                [
                    'account_type_id' => $types[$glType],
                    'name' => (string) ($row->name ?: $label),
                    'normal_balance' => $normal,
                    'is_postable' => (int) ($row->parent_id ?? 0) > 0 || in_array($faType, ['cash', 'bank', 'mobile'], true),
                    'allow_manual_entry' => true,
                    'status' => 'active',
                    'level' => (int) ($row->parent_id ?? 0) > 0 ? 2 : 1,
                    'is_system' => false,
                    'path' => 'erp',
                ]
            );

            $this->map($connection, (int) $book->company_id, 'financial_account', (string) $row->id, (int) $account->id, [
                'code' => $code,
                'fa_type' => $faType,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Map Balances cash/bank/mobile wallets into Cash & Bank desks.
     *
     * @return array{0:int,1:int}
     */
    private function syncBalancesWallets(AccountingBook $book, ErpConnection $connection): array
    {
        if (! $this->erp->tableExists('financial_accounts')) {
            return [0, 0];
        }

        $rows = $this->erp->select(
            "SELECT id, name, type, currency, status
             FROM financial_accounts
             WHERE status = 'active' AND type IN ('bank', 'cash', 'mobile')
             ORDER BY id"
        );

        $banks = 0;
        $cash = 0;
        $currencyDefault = 'TZS';

        foreach ($rows as $row) {
            [$parsedCode, $label] = $this->parseBalancesAccountName((string) $row->name, (int) $row->id);
            $faType = strtolower((string) $row->type);
            $currency = strtoupper(substr((string) ($row->currency ?: $currencyDefault), 0, 3));
            $glId = $this->localId($connection, 'financial_account', (string) $row->id);
            if (! $glId) {
                continue;
            }

            $deskCode = 'FA-'.$row->id;
            $deskName = $label !== '' ? $label : (string) $row->name;

            if ($faType === 'cash') {
                $cashRow = CashAccount::withoutGlobalScopes()->updateOrCreate(
                    [
                        'company_id' => $book->company_id,
                        'book_id' => $book->id,
                        'code' => $deskCode,
                    ],
                    [
                        'name' => $deskName,
                        'currency_code' => $currency,
                        'gl_account_id' => $glId,
                        'status' => 'active',
                    ]
                );
                $this->map($connection, (int) $book->company_id, 'financial_cash', (string) $row->id, (int) $cashRow->id, [
                    'fa_code' => $parsedCode,
                ]);
                $cash++;
                continue;
            }

            $existingBank = BankAccount::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('code', $deskCode)
                ->first();
            $bankRow = BankAccount::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $book->company_id,
                    'book_id' => $book->id,
                    'code' => $deskCode,
                ],
                [
                    'name' => $deskName,
                    'bank_name' => $deskName,
                    // Keep operator-edited account numbers across ERP wallet refresh.
                    'account_number' => trim((string) ($existingBank?->account_number ?? '')),
                    'currency_code' => $currency,
                    'gl_account_id' => $glId,
                    'account_type' => $faType === 'mobile' ? 'mobile_money' : 'bank',
                    'status' => 'active',
                ]
            );
            $this->map($connection, (int) $book->company_id, 'financial_bank', (string) $row->id, (int) $bankRow->id, [
                'fa_code' => $parsedCode,
            ]);
            $banks++;
        }

        // Prefer Balances wallets over bootstrap placeholders when real desks exist.
        if ($banks > 0) {
            BankAccount::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('code', 'MAIN-BANK')
                ->update(['status' => 'inactive']);
        }
        if ($cash > 0) {
            CashAccount::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('code', 'PETTY')
                ->update(['status' => 'inactive']);
        }

        return [$banks, $cash];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseBalancesAccountName(string $name, int $id): array
    {
        $name = trim($name);
        if (preg_match('/^(\d+)\s*[-–:]\s*(.+)$/u', $name, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return ['FA'.$id, $name !== '' ? $name : 'Account '.$id];
    }

    private function syncCustomers(AccountingBook $book, ErpConnection $connection): int
    {
        if (! $this->erp->tableExists('customers')) {
            return 0;
        }

        $rows = $this->erp->select(
            'SELECT id, customer_code, company_name, contact_person, email, phone, tax_number, currency, status
             FROM customers ORDER BY id'
        );
        $count = 0;

        foreach ($rows as $row) {
            $code = trim((string) ($row->customer_code ?: 'CUST-'.$row->id));
            $customer = Customer::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $book->company_id, 'code' => $code],
                [
                    'name' => $row->company_name ?: ($row->contact_person ?: $code),
                    'email' => $row->email,
                    'phone' => $row->phone,
                    'tax_number' => $row->tax_number,
                    'currency_code' => strtoupper(substr((string) ($row->currency ?: 'TZS'), 0, 3)),
                    'status' => (strtolower((string) ($row->status ?? 'active')) === 'active') ? 'active' : 'inactive',
                ]
            );
            $this->map($connection, (int) $book->company_id, 'customer', (string) $row->id, (int) $customer->id);
            $count++;
        }

        return $count;
    }

    private function syncSuppliers(AccountingBook $book, ErpConnection $connection): int
    {
        if (! $this->erp->tableExists('suppliers')) {
            return 0;
        }

        $rows = $this->erp->select(
            'SELECT id, name, contact_person, email, phone, status FROM suppliers ORDER BY id'
        );
        $count = 0;

        foreach ($rows as $row) {
            $code = 'SUP-'.$row->id;
            $supplier = Supplier::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $book->company_id, 'code' => $code],
                [
                    'name' => $row->name ?: $code,
                    'email' => $row->email,
                    'phone' => $row->phone,
                    'currency_code' => 'TZS',
                    'status' => (strtolower((string) ($row->status ?? 'active')) === 'active') ? 'active' : 'inactive',
                ]
            );
            $this->map($connection, (int) $book->company_id, 'supplier', (string) $row->id, (int) $supplier->id);
            $count++;
        }

        return $count;
    }

    /**
     * Mirror ERP sales invoices into Accounting Revenue (no double journal post).
     */
    private function syncInvoices(AccountingBook $book, User $user, ErpConnection $connection): int
    {
        if (! $this->erp->tableExists('invoices')) {
            return 0;
        }

        $revenueAccountId = $this->salesRevenueAccountId($book);
        if ($revenueAccountId <= 0) {
            return 0;
        }

        $rows = $this->erp->select(
            'SELECT i.id, i.invoice_number, i.order_id, i.customer_id, i.invoice_date, i.due_date,
                    i.subtotal, i.tax_amount, i.total_amount, i.amount_paid, i.status, i.notes,
                    c.customer_code, c.company_name, c.contact_person, c.email, c.phone, c.tax_number, c.currency, c.status AS customer_status
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id
             ORDER BY i.id ASC'
        );

        $count = 0;
        foreach ($rows as $row) {
            $erpCustomerId = (int) ($row->customer_id ?? 0);
            if ($erpCustomerId <= 0) {
                continue;
            }

            $customerId = $this->localId($connection, 'customer', (string) $erpCustomerId);
            if (! $customerId) {
                $code = trim((string) ($row->customer_code ?: 'CUST-'.$erpCustomerId));
                $customer = Customer::withoutGlobalScopes()->updateOrCreate(
                    ['company_id' => $book->company_id, 'code' => $code],
                    [
                        'name' => $row->company_name ?: ($row->contact_person ?: $code),
                        'email' => $row->email,
                        'phone' => $row->phone,
                        'tax_number' => $row->tax_number,
                        'currency_code' => strtoupper(substr((string) ($row->currency ?: 'TZS'), 0, 3)),
                        'status' => (strtolower((string) ($row->customer_status ?? 'active')) === 'active') ? 'active' : 'inactive',
                    ]
                );
                $customerId = (int) $customer->id;
                $this->map($connection, (int) $book->company_id, 'customer', (string) $erpCustomerId, $customerId);
            }

            $salesNumber = trim((string) ($row->invoice_number ?: ('INV-ERP-'.$row->id)));
            // Prefer classic Accounting revenue voucher (REV-…) when linked via revenue_entries.
            $revenueNumber = $this->classicRevenueVoucherForInvoice((int) $row->id);
            $number = $revenueNumber !== null ? $revenueNumber : $salesNumber;
            $existingLocalId = $this->localId($connection, 'customer_invoice', (string) $row->id);
            $invoice = $existingLocalId
                ? CustomerInvoice::withoutGlobalScopes()
                    ->where('company_id', $book->company_id)
                    ->where('book_id', $book->id)
                    ->whereKey($existingLocalId)
                    ->first()
                : null;

            if (! $invoice) {
                $invoice = CustomerInvoice::withoutGlobalScopes()
                    ->where('company_id', $book->company_id)
                    ->where('book_id', $book->id)
                    ->where('invoice_number', $number)
                    ->first();
            }
            if (! $invoice && $salesNumber !== $number) {
                $invoice = CustomerInvoice::withoutGlobalScopes()
                    ->where('company_id', $book->company_id)
                    ->where('book_id', $book->id)
                    ->where('invoice_number', $salesNumber)
                    ->first();
            }

            $total = Money::normalize($row->total_amount ?? 0);
            $paid = Money::normalize($row->amount_paid ?? 0);
            $tax = Money::normalize($row->tax_amount ?? 0);
            $subtotal = Money::normalize($row->subtotal ?? 0);
            if (Money::isZero($subtotal)) {
                $subtotal = Money::sub($total, $tax);
            }
            $balance = Money::sub($total, $paid);
            if (Money::compare($balance, '0') < 0) {
                $balance = '0.0000';
            }

            $status = $this->mapErpInvoiceStatus((string) ($row->status ?? 'sent'), $paid, $total);
            $payload = [
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'customer_id' => $customerId,
                'invoice_number' => $number,
                'invoice_date' => $row->invoice_date ?: now()->toDateString(),
                'due_date' => $row->due_date ?: null,
                'currency_code' => 'TZS',
                'subtotal' => $subtotal,
                'tax_total' => $tax,
                'total' => $total,
                'amount_paid' => $paid,
                'balance_due' => $balance,
                'status' => $status,
                'description' => trim((string) ($row->notes ?? '')) !== ''
                    ? (string) $row->notes
                    : 'Imported from ERP sales invoice #'.$salesNumber,
                'created_by' => $user->id,
            ];

            if ($invoice) {
                // Never overwrite a classic REV voucher back to INV on later syncs.
                $currentNumber = trim((string) $invoice->invoice_number);
                if (str_starts_with(strtoupper($currentNumber), 'REV-')) {
                    unset($payload['invoice_number']);
                }
                // Keep ERP mirror numbers stable; never overwrite a manually posted Ultitech journal link.
                $invoice->fill(collect($payload)->except(['created_by'])->all());
                $invoice->save();
            } else {
                $invoice = CustomerInvoice::withoutGlobalScopes()->create([
                    ...$payload,
                    'uuid' => (string) Str::uuid(),
                ]);
            }

            $lineRows = $this->erpInvoiceLines((int) ($row->order_id ?? 0), $total, $revenueAccountId);
            CustomerInvoiceLine::query()->where('invoice_id', $invoice->id)->delete();
            foreach ($lineRows as $index => $line) {
                CustomerInvoiceLine::query()->create([
                    'invoice_id' => $invoice->id,
                    'company_id' => $book->company_id,
                    'line_no' => $index + 1,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                    'revenue_account_id' => $revenueAccountId,
                ]);
            }

            $this->map($connection, (int) $book->company_id, 'customer_invoice', (string) $row->id, (int) $invoice->id, [
                'invoice_number' => $salesNumber,
                'sales_invoice_number' => $salesNumber,
                'revenue_voucher_number' => $revenueNumber,
                'erp_status' => (string) ($row->status ?? ''),
                'order_id' => (int) ($row->order_id ?? 0),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Import input VAT from ERP stock purchases into the tax register.
     */
    private function syncPurchaseTaxTransactions(AccountingBook $book, ErpConnection $connection): int
    {
        unset($connection);
        if (! $this->erp->tableExists('purchases')) {
            return 0;
        }

        $code = $this->taxEngine->ensureDefaultVatCode($book);
        if (! $code) {
            return 0;
        }

        $hasPurchaseDate = $this->erp->columnExists('purchases', 'purchase_date');
        $dateExpr = $hasPurchaseDate
            ? 'COALESCE(purchase_date, DATE(created_at), CURDATE())'
            : 'COALESCE(DATE(created_at), CURDATE())';

        $rows = $this->erp->select(
            "SELECT id, subtotal, tax_amount, total_amount, {$dateExpr} AS tax_date, status
             FROM purchases
             WHERE COALESCE(tax_amount, 0) > 0
             ORDER BY id ASC"
        );

        $count = 0;
        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row->status ?? '')));
            if (in_array($status, ['cancelled', 'canceled', 'rejected'], true)) {
                continue;
            }

            $tax = Money::normalize($row->tax_amount ?? 0);
            if (Money::isZero($tax)) {
                continue;
            }

            $taxable = Money::normalize($row->subtotal ?? 0);
            if (Money::isZero($taxable)) {
                $taxable = Money::sub(Money::normalize($row->total_amount ?? 0), $tax);
            }

            $txn = $this->taxEngine->upsertExplicitTransaction(
                $book,
                $code,
                'input',
                $taxable,
                $tax,
                (string) ($row->tax_date ?? now()->toDateString()),
                'erp_purchase',
                (int) $row->id,
                null
            );
            if ($txn) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<int, array{description:string,quantity:string,unit_price:string,line_total:string}>
     */
    private function erpInvoiceLines(int $orderId, string $fallbackTotal, int $revenueAccountId): array
    {
        unset($revenueAccountId);
        if ($orderId > 0 && $this->erp->tableExists('sales_order_items')) {
            $items = $this->erp->select(
                'SELECT description, quantity, unit_price, line_total, total
                 FROM sales_order_items WHERE order_id = ? ORDER BY id ASC',
                [$orderId]
            );
            $lines = [];
            foreach ($items as $item) {
                $qty = Money::normalize($item->quantity ?? 1);
                $price = Money::normalize($item->unit_price ?? 0);
                $lineTotal = Money::normalize($item->line_total ?? ($item->total ?? 0));
                if (Money::isZero($lineTotal)) {
                    $lineTotal = Money::multiply($qty, $price);
                }
                if (Money::isZero($lineTotal)) {
                    continue;
                }
                $desc = trim((string) ($item->description ?? 'Sales line')) ?: 'Sales line';
                if (mb_strlen($desc) > 250) {
                    $desc = mb_substr($desc, 0, 247).'...';
                }
                $lines[] = [
                    'description' => $desc,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'line_total' => $lineTotal,
                ];
            }
            if ($lines !== []) {
                return $lines;
            }
        }

        return [[
            'description' => 'Sales invoice',
            'quantity' => '1.0000',
            'unit_price' => $fallbackTotal,
            'line_total' => $fallbackTotal,
        ]];
    }

    private function mapErpInvoiceStatus(string $erpStatus, string $paid, string $total): string
    {
        $status = strtolower(trim($erpStatus));
        if ($status === 'cancelled' || $status === 'canceled') {
            return 'void';
        }
        if ($status === 'draft') {
            return 'draft';
        }
        if ($status === 'paid' || (! Money::isZero($total) && Money::compare($paid, $total) >= 0)) {
            return 'paid';
        }
        if ($status === 'partial' || (! Money::isZero($paid) && Money::compare($paid, $total) < 0)) {
            return 'partial';
        }

        // sent / viewed / overdue → open receivable in Accounting
        return 'posted';
    }

    private function salesRevenueAccountId(AccountingBook $book): int
    {
        $mapped = AccountMapping::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('mapping_key', 'sales_revenue')
            ->value('account_id');
        if ($mapped) {
            return (int) $mapped;
        }

        $account = Account::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('is_postable', true)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereIn('code', ['4001', '4000', '4002'])
                    ->orWhere('code', 'like', '4%');
            })
            ->orderByRaw("CASE code WHEN '4001' THEN 1 WHEN '4000' THEN 2 WHEN '4002' THEN 3 ELSE 9 END")
            ->orderBy('code')
            ->first();

        return $account ? (int) $account->id : 0;
    }

    /**
     * @return array{0:int,1:int,2:array<int,string>}
     */
    /**
     * Import ERP gl_journal_entries into Accounting via JournalPostingService.
     *
     * Idempotent: source_hash = erp:gl_journal:{erp_id} (unique per company/book).
     * Cursor-scans the full ERP GL so already-imported rows do not block later IDs
     * (previous LIMIT-first-N behaviour permanently stalled after the first batch).
     *
     * @return array{0:int,1:int,2:list<string>}
     */
    private function syncJournals(AccountingBook $book, User $user, ErpConnection $connection, int $limit): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $lastId = 0;
        $scanBatch = 100;
        $limit = max(1, $limit);

        while ($imported < $limit) {
            $entries = $this->erp->select(
                'SELECT id, entry_number, entry_date, description, source_type, source_key, currency, total_amount
                 FROM gl_journal_entries
                 WHERE id > ?
                 ORDER BY id ASC
                 LIMIT '.(int) $scanBatch,
                [$lastId]
            );

            if ($entries === []) {
                break;
            }

            foreach ($entries as $entry) {
                $lastId = (int) $entry->id;

                if ($imported >= $limit) {
                    break 2;
                }

                $sourceHash = 'erp:gl_journal:'.$entry->id;
                $mappedLocalId = $this->localId($connection, 'gl_journal', (string) $entry->id);
                $exists = $mappedLocalId !== null
                    || \App\Models\JournalEntry::withoutGlobalScopes()
                        ->where('company_id', $book->company_id)
                        ->where('book_id', $book->id)
                        ->where('source_hash', $sourceHash)
                        ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $lines = $this->erp->select(
                    'SELECT line_no, account_id, debit, credit, memo, currency
                     FROM gl_journal_lines WHERE entry_id = ? ORDER BY line_no',
                    [$entry->id]
                );

                if (count($lines) < 2) {
                    $skipped++;
                    $errors[] = "Journal {$entry->entry_number}: fewer than 2 lines";
                    continue;
                }

                try {
                    $this->periods->resolveOpenPeriod((int) $book->company_id, (int) $book->id, $entry->entry_date);
                } catch (\Throwable $e) {
                    $skipped++;
                    $errors[] = "Journal {$entry->entry_number}: period not open for {$entry->entry_date}";
                    continue;
                }

                $payloadLines = [];
                $ok = true;
                foreach ($lines as $line) {
                    $localAccountId = $this->resolvePostableMappedAccount(
                        $book,
                        $connection,
                        (string) $line->account_id
                    );
                    if (! $localAccountId) {
                        $ok = false;
                        $errors[] = "Journal {$entry->entry_number}: missing mapped account erp#{$line->account_id}";
                        break;
                    }
                    $payloadLines[] = [
                        'account_id' => $localAccountId,
                        'debit' => Money::normalize($line->debit),
                        'credit' => Money::normalize($line->credit),
                        'description' => $line->memo ?: $entry->description,
                    ];
                }

                if (! $ok) {
                    $skipped++;
                    continue;
                }

                try {
                    $journal = $this->posting->createAndPost($book, $user, [
                        'journal_date' => $entry->entry_date,
                        'description' => '[ERP] '.($entry->description ?: $entry->entry_number),
                        'reference' => $entry->entry_number,
                        'currency_code' => strtoupper((string) ($entry->currency ?: 'TZS')),
                        'source_type' => 'erp_gl_journal',
                        'source_id_ref' => (string) $entry->id,
                        'source_hash' => $sourceHash,
                        'lines' => $payloadLines,
                    ], 'system');

                    $this->map($connection, (int) $book->company_id, 'gl_journal', (string) $entry->id, (int) $journal->id, [
                        'entry_number' => $entry->entry_number,
                        'erp_source_type' => $entry->source_type ?? null,
                        'erp_source_key' => $entry->source_key ?? null,
                    ]);
                    $imported++;
                } catch (\Throwable $e) {
                    $skipped++;
                    $errors[] = "Journal {$entry->entry_number}: ".$e->getMessage();
                }
            }

            if (count($entries) < $scanBatch) {
                break;
            }
        }

        return [$imported, $skipped, array_slice($errors, 0, 25)];
    }

    /**
     * Soft-link ERP-mirrored customer invoices to imported GL journals when the
     * invoice number appears uniquely in a journal description. Never posts a
     * second journal for the invoice (ERP GL is canonical for mirrors).
     */
    private function linkMirroredInvoicesToImportedJournals(AccountingBook $book): int
    {
        $invoices = CustomerInvoice::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->whereNull('posted_journal_id')
            ->orderBy('id')
            ->get(['id', 'invoice_number']);

        if ($invoices->isEmpty()) {
            return 0;
        }

        $linked = 0;
        foreach ($invoices as $invoice) {
            $number = trim((string) $invoice->invoice_number);
            if ($number === '' || strlen($number) < 4) {
                continue;
            }

            // Escape LIKE wildcards in document numbers.
            $like = '%'.addcslashes($number, '%_\\').'%';
            $matches = \App\Models\JournalEntry::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('source_type', 'erp_gl_journal')
                ->where('status', 'posted')
                ->where('description', 'like', $like)
                ->orderBy('journal_date')
                ->orderBy('id')
                ->limit(5)
                ->get(['id', 'description']);

            if ($matches->isEmpty()) {
                continue;
            }

            // Prefer recognition wording; otherwise the earliest mention (sale before receipt).
            $chosen = $matches->first(function ($row) {
                $d = strtolower((string) $row->description);

                return str_contains($d, 'revenue')
                    || str_contains($d, 'recognised')
                    || str_contains($d, 'recognized')
                    || str_contains($d, 'sales invoice');
            }) ?? $matches->first();

            $invoice->posted_journal_id = (int) $chosen->id;
            $invoice->save();
            $linked++;
        }

        return $linked;
    }

    /**
     * Resolve ERP account map to a postable local account. If the map points at a
     * header, remaps to a postable counterpart and persists the corrected map.
     */
    private function resolvePostableMappedAccount(AccountingBook $book, ErpConnection $connection, string $erpAccountId): ?int
    {
        $localAccountId = $this->localId($connection, 'account', $erpAccountId);
        if (! $localAccountId) {
            return null;
        }

        $account = Account::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->whereKey($localAccountId)
            ->first();

        if (! $account) {
            return null;
        }

        if ($account->is_postable) {
            return (int) $account->id;
        }

        $counterpart = $this->findPostableCounterpart($book, $account, (string) $account->name);
        if (! $counterpart) {
            return (int) $account->id;
        }

        $this->map($connection, (int) $book->company_id, 'account', $erpAccountId, (int) $counterpart->id, [
            'code' => $account->code,
            'mapped_via' => 'postable_counterpart_repair',
            'previous_local_id' => (int) $account->id,
        ]);

        return (int) $counterpart->id;
    }

    private function findPostableCounterpart(AccountingBook $book, Account $header, string $preferredName = ''): ?Account
    {
        $postable = function () use ($book) {
            return Account::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('is_postable', true)
                ->where('status', 'active');
        };

        $name = trim($preferredName);
        if ($name !== '' && strcasecmp($name, 'Liabilities') !== 0) {
            $byName = $postable()->where('name', $name)->orderBy('code')->first();
            if ($byName) {
                return $byName;
            }
        }

        // Common template case: header 2000 Liabilities + child 2001 Accounts Payable.
        if (strcasecmp((string) $header->name, 'Liabilities') === 0 || $header->code === '2000') {
            $ap = $postable()->where('name', 'Accounts Payable')->orderBy('code')->first();
            if ($ap) {
                return $ap;
            }
        }

        $prefix = (string) $header->code;
        if ($prefix === '') {
            return null;
        }

        return $postable()
            ->where('code', 'like', $prefix.'%')
            ->where('code', '!=', $prefix)
            ->orderByRaw('LENGTH(code) asc')
            ->orderBy('code')
            ->first();
    }

    private function bindErpDatabaseForCompany(int $companyId, ErpConnection $connection): void
    {
        $settings = CompanySetting::query()->where('company_id', $companyId)->first();
        $json = is_array($settings?->settings_json) ? $settings->settings_json : [];
        $erpDb = trim((string) data_get($json, 'erp_bridge.erp_db', ''));
        if ($erpDb === '') {
            $erpDb = trim((string) ($connection->erp_database ?: config('erp.default_database')));
        }
        if ($erpDb !== '') {
            $this->erp->useDatabase($erpDb);
            if ($connection->erp_database !== $erpDb) {
                $connection->forceFill(['erp_database' => $erpDb])->save();
            }
        }
    }

    private function map(ErpConnection $connection, int $companyId, string $type, string $erpId, int $localId, array $meta = []): void
    {
        ErpIdMap::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'entity_type' => $type,
                'erp_id' => $erpId,
            ],
            [
                'company_id' => $companyId,
                'local_id' => $localId,
                'meta_json' => $meta ?: null,
            ]
        );
    }

    private function localId(ErpConnection $connection, string $type, string $erpId): ?int
    {
        $map = ErpIdMap::query()
            ->where('connection_id', $connection->id)
            ->where('entity_type', $type)
            ->where('erp_id', $erpId)
            ->first();

        return $map ? (int) $map->local_id : null;
    }

    /**
     * Classic Accounting revenue voucher for a sales invoice (revenue_entries.source_invoice_id).
     */
    private function classicRevenueVoucherForInvoice(int $erpInvoiceId): ?string
    {
        if ($erpInvoiceId <= 0 || ! $this->erp->tableExists('revenue_entries')) {
            return null;
        }

        try {
            $rows = $this->erp->select(
                'SELECT voucher_number FROM revenue_entries
                 WHERE source_invoice_id = ?
                   AND voucher_number IS NOT NULL
                   AND voucher_number <> \'\'
                 ORDER BY id ASC
                 LIMIT 5',
                [$erpInvoiceId]
            );
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($rows as $row) {
            $vn = trim((string) ($row->voucher_number ?? ''));
            if ($vn !== '' && str_starts_with(strtoupper($vn), 'REV-')) {
                return $vn;
            }
        }

        return null;
    }
}
