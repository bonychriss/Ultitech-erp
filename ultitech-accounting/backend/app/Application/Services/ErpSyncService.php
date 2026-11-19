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

        $limit = $journalLimit ?? (int) config('erp.journal_limit', 50);
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
            'journals_imported' => 0,
            'journals_skipped' => 0,
            'errors' => [],
        ];

        try {
            DB::connection()->transaction(function () use ($book, $user, $connection, $scopes, $limit, &$stats) {
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
                }
                if (in_array('journals', $scopes, true)) {
                    [$imported, $skipped, $errors] = $this->syncJournals($book, $user, $connection, $limit);
                    $stats['journals_imported'] = $imported;
                    $stats['journals_skipped'] = $skipped;
                    $stats['errors'] = $errors;
                }
            });

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
                $this->map($connection, (int) $book->company_id, 'account', (string) $row->id, (int) $existing->id, [
                    'code' => $code,
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
    private function syncJournals(AccountingBook $book, User $user, ErpConnection $connection, int $limit): array
    {
        $entries = $this->erp->select(
            'SELECT id, entry_number, entry_date, description, source_type, source_key, currency, total_amount
             FROM gl_journal_entries
             ORDER BY id ASC
             LIMIT '.(int) $limit
        );

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($entries as $entry) {
            $sourceHash = 'erp:gl_journal:'.$entry->id;
            $exists = \App\Models\JournalEntry::withoutGlobalScopes()
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
                $localAccountId = $this->localId($connection, 'account', (string) $line->account_id);
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
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Journal {$entry->entry_number}: ".$e->getMessage();
            }
        }

        return [$imported, $skipped, array_slice($errors, 0, 25)];
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
