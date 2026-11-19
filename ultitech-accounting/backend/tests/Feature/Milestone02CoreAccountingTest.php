<?php

namespace Tests\Feature;

use App\Application\Services\AccountBalanceService;
use App\Application\Services\PayableService;
use App\Application\Services\ReceivableService;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Framework;
use App\Models\Jurisdiction;
use App\Models\PostingSequence;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Support\Money;
use App\Support\TenantContext;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Milestone02CoreAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private AccountingBook $book;

    private Customer $customer;

    private Supplier $supplier;

    private BankAccount $bank;

    private Account $revenue;

    private Account $expense;

    private Account $ar;

    private Account $ap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->bootDemo();
    }

    public function test_customer_invoice_posts_to_gl_and_creates_receivable(): void
    {
        $invoice = app(ReceivableService::class)->createAndPostInvoice($this->book, $this->user, [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-03-15',
            'currency_code' => 'TZS',
            'description' => 'Services',
            'lines' => [
                [
                    'description' => 'Consulting',
                    'quantity' => 1,
                    'unit_price' => '100000',
                    'revenue_account_id' => $this->revenue->id,
                ],
            ],
        ]);

        $this->assertSame('posted', $invoice->status);
        $this->assertTrue(Money::equals((string) $invoice->total, '100000.0000'));
        $this->assertNotNull($invoice->posted_journal_id);
        $this->assertDatabaseHas('acc_journal_entries', [
            'id' => $invoice->posted_journal_id,
            'status' => 'posted',
            'source_hash' => 'customer_invoice:'.$invoice->id,
        ]);
    }

    public function test_receipt_allocates_and_clears_invoice_balance(): void
    {
        $invoice = app(ReceivableService::class)->createAndPostInvoice($this->book, $this->user, [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-03-15',
            'currency_code' => 'TZS',
            'lines' => [
                [
                    'description' => 'Consulting',
                    'quantity' => 1,
                    'unit_price' => '100000',
                    'revenue_account_id' => $this->revenue->id,
                ],
            ],
        ]);

        $receipt = app(ReceivableService::class)->createAndPostReceipt($this->book, $this->user, [
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-03-16',
            'currency_code' => 'TZS',
            'amount' => '100000',
            'bank_account_id' => $this->bank->id,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => '100000'],
            ],
        ]);

        $invoice->refresh();
        $this->assertSame('posted', $receipt->status);
        $this->assertSame('paid', $invoice->status);
        $this->assertTrue(Money::isZero((string) $invoice->balance_due));
        $this->assertDatabaseHas('acc_bank_transactions', [
            'source_type' => 'customer_receipt',
            'source_id' => $receipt->id,
        ]);
    }

    public function test_supplier_bill_and_payment_post_through_gl(): void
    {
        $bill = app(PayableService::class)->createAndPostBill($this->book, $this->user, [
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2026-03-15',
            'currency_code' => 'TZS',
            'lines' => [
                [
                    'description' => 'Supplies',
                    'quantity' => 1,
                    'unit_price' => '50000',
                    'expense_account_id' => $this->expense->id,
                ],
            ],
        ]);

        $payment = app(PayableService::class)->createAndPostPayment($this->book, $this->user, [
            'supplier_id' => $this->supplier->id,
            'payment_date' => '2026-03-17',
            'currency_code' => 'TZS',
            'amount' => '50000',
            'bank_account_id' => $this->bank->id,
            'allocations' => [
                ['bill_id' => $bill->id, 'amount' => '50000'],
            ],
        ]);

        $bill->refresh();
        $this->assertSame('posted', $payment->status);
        $this->assertSame('paid', $bill->status);

        $tb = app(AccountBalanceService::class)->trialBalance((int) $this->company->id, (int) $this->book->id);
        $totalDebit = $tb->reduce(fn ($c, $r) => Money::add($c, $r['debit']), '0.0000');
        $totalCredit = $tb->reduce(fn ($c, $r) => Money::add($c, $r['credit']), '0.0000');
        $this->assertTrue(Money::equals($totalDebit, $totalCredit));
    }

    public function test_invoice_api_requires_permission_and_posts(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeaders([
                'X-Company-Id' => (string) $this->company->id,
                'X-Book-Id' => (string) $this->book->id,
            ])
            ->postJson('/api/v1/customer-invoices', [
                'customer_id' => $this->customer->id,
                'invoice_date' => '2026-03-15',
                'currency_code' => 'TZS',
                'lines' => [
                    [
                        'description' => 'API sale',
                        'quantity' => 1,
                        'unit_price' => '25000',
                        'revenue_account_id' => $this->revenue->id,
                    ],
                ],
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('acc_customer_invoices', [
            'customer_id' => $this->customer->id,
            'status' => 'posted',
        ]);
    }

    public function test_duplicate_invoice_source_hash_is_unique_per_document(): void
    {
        app(ReceivableService::class)->createAndPostInvoice($this->book, $this->user, [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-03-15',
            'currency_code' => 'TZS',
            'lines' => [
                [
                    'description' => 'A',
                    'quantity' => 1,
                    'unit_price' => '10000',
                    'revenue_account_id' => $this->revenue->id,
                ],
            ],
        ]);

        $second = app(ReceivableService::class)->createAndPostInvoice($this->book, $this->user, [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-03-15',
            'currency_code' => 'TZS',
            'lines' => [
                [
                    'description' => 'B',
                    'quantity' => 1,
                    'unit_price' => '20000',
                    'revenue_account_id' => $this->revenue->id,
                ],
            ],
        ]);

        $this->assertInstanceOf(CustomerInvoice::class, $second);
        $this->assertSame(2, CustomerInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    private function bootDemo(): void
    {
        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $this->user = User::factory()->create(['password' => 'password']);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'M2CO',
            'legal_name' => 'M2 Test Co',
            'country_code' => 'TZ',
            'base_currency_code' => 'TZS',
            'timezone' => 'Africa/Dar_es_Salaam',
            'status' => 'active',
        ]);
        UserCompanyAccess::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role_id' => $adminRole->id,
            'is_default' => true,
            'status' => 'active',
        ]);

        $this->book = AccountingBook::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'IFRS-TZ',
            'name' => 'Primary',
            'framework_id' => Framework::query()->where('code', 'IFRS')->value('id'),
            'jurisdiction_id' => Jurisdiction::query()->where('code', 'TZ')->value('id'),
            'functional_currency_code' => 'TZS',
            'is_primary' => true,
            'status' => 'active',
        ]);

        PostingSequence::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'sequence_key' => 'journal',
            'prefix' => 'JE',
            'next_number' => 1,
            'padding' => 6,
        ]);

        ExchangeRate::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'from_currency' => 'TZS',
            'to_currency' => 'TZS',
            'rate_date' => '2026-01-01',
            'rate' => 1,
            'source' => 'system',
            'created_by' => $this->user->id,
        ]);

        $year = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        FiscalPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 3,
            'name' => 'Mar 2026',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'status' => 'open',
        ]);

        $types = AccountType::query()->pluck('id', 'code');
        $make = function (string $code, string $name, string $type, string $balance) use ($types) {
            return Account::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'book_id' => $this->book->id,
                'account_type_id' => $types[$type],
                'code' => $code,
                'name' => $name,
                'normal_balance' => $balance,
                'is_postable' => true,
                'allow_manual_entry' => true,
                'status' => 'active',
                'level' => 1,
            ]);
        };

        $cash = $make('1000', 'Cash', 'asset', 'debit');
        $bankGl = $make('1100', 'Bank', 'asset', 'debit');
        $this->ar = $make('1200', 'AR', 'asset', 'debit');
        $this->ap = $make('2000', 'AP', 'liability', 'credit');
        $this->revenue = $make('4000', 'Sales', 'revenue', 'credit');
        $this->expense = $make('5200', 'Purchases', 'expense', 'debit');

        foreach ([
            'cash' => $cash->id,
            'bank' => $bankGl->id,
            'accounts_receivable' => $this->ar->id,
            'accounts_payable' => $this->ap->id,
            'sales_revenue' => $this->revenue->id,
            'purchases' => $this->expense->id,
        ] as $key => $accountId) {
            AccountMapping::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'book_id' => $this->book->id,
                'mapping_key' => $key,
                'account_id' => $accountId,
            ]);
        }

        $this->bank = BankAccount::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'code' => 'BANK1',
            'name' => 'Main Bank',
            'currency_code' => 'TZS',
            'gl_account_id' => $bankGl->id,
            'account_type' => 'bank',
            'status' => 'active',
        ]);

        $this->customer = Customer::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'C1',
            'name' => 'Customer One',
            'currency_code' => 'TZS',
            'status' => 'active',
        ]);
        $this->supplier = Supplier::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'S1',
            'name' => 'Supplier One',
            'currency_code' => 'TZS',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setUser($this->user);
        app(TenantContext::class)->setCompany($this->company);
        app(TenantContext::class)->setBook($this->book);
    }
}
