<?php

namespace Tests\Feature;

use App\Application\Services\FinancialReportingService;
use App\Application\Services\JournalPostingService;
use App\Application\Services\ReceivableService;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Framework;
use App\Models\Jurisdiction;
use App\Models\PostingSequence;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Support\Money;
use App\Support\TenantContext;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Milestone03FinancialReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private AccountingBook $book;

    private Account $cash;

    private Account $bankGl;

    private Account $ar;

    private Account $revenue;

    private Account $expense;

    private Account $capital;

    private Customer $customer;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->bootDemo();
        $this->postSampleActivity();
    }

    public function test_profit_and_loss_totals_revenue_and_expenses(): void
    {
        $report = app(FinancialReportingService::class)->profitAndLoss(
            (int) $this->company->id,
            (int) $this->book->id,
            '2026-01-01',
            '2026-12-31'
        );

        $this->assertTrue(Money::equals($report['totals']['revenue'], '100000.0000'));
        $this->assertTrue(Money::equals($report['totals']['expenses'], '40000.0000'));
        $this->assertTrue(Money::equals($report['totals']['net_profit'], '60000.0000'));
    }

    public function test_balance_sheet_balances(): void
    {
        $report = app(FinancialReportingService::class)->balanceSheet(
            (int) $this->company->id,
            (int) $this->book->id,
            '2026-12-31'
        );

        $this->assertTrue($report['totals']['balanced']);
        $this->assertTrue(Money::equals(
            $report['totals']['assets'],
            $report['totals']['liabilities_and_equity']
        ));
    }

    public function test_cash_flow_reconciles_opening_and_closing_cash(): void
    {
        $report = app(FinancialReportingService::class)->cashFlow(
            (int) $this->company->id,
            (int) $this->book->id,
            '2026-01-01',
            '2026-12-31'
        );

        $this->assertTrue($report['cash_reconciliation']['reconciled']);
        $this->assertTrue(Money::equals(
            Money::add($report['cash_reconciliation']['opening_cash'], $report['cash_reconciliation']['net_increase']),
            $report['cash_reconciliation']['closing_cash']
        ));
    }

    public function test_equity_statement_includes_net_profit(): void
    {
        $report = app(FinancialReportingService::class)->changesInEquity(
            (int) $this->company->id,
            (int) $this->book->id,
            '2026-01-01',
            '2026-12-31'
        );

        $this->assertTrue(Money::equals($report['net_profit'], '60000.0000'));
        $expectedClosing = Money::add($report['opening_equity'], Money::add($report['net_profit'], $report['other_movements']));
        $this->assertTrue(Money::equals($expectedClosing, $report['closing_equity']));
    }

    public function test_general_ledger_is_traceable_to_journals(): void
    {
        $report = app(FinancialReportingService::class)->generalLedger(
            (int) $this->company->id,
            (int) $this->book->id,
            $this->revenue->id,
            '2026-01-01',
            '2026-12-31'
        );

        $this->assertNotEmpty($report['data']);
        $this->assertArrayHasKey('journal_number', $report['data'][0]);
        $this->assertTrue(Money::equals($report['closing_balance'], '-100000.0000') || Money::equals($report['data'][0]['credit'], '100000.0000'));
    }

    public function test_financial_statement_apis_are_authorized(): void
    {
        $headers = [
            'X-Company-Id' => (string) $this->company->id,
            'X-Book-Id' => (string) $this->book->id,
        ];

        $this->actingAs($this->user, 'sanctum')
            ->withHeaders($headers)
            ->getJson('/api/v1/reports/profit-and-loss?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonPath('totals.net_profit', '60000.0000');

        $this->actingAs($this->user, 'sanctum')
            ->withHeaders($headers)
            ->getJson('/api/v1/reports/balance-sheet?as_of=2026-12-31')
            ->assertOk()
            ->assertJsonPath('totals.balanced', true);
    }

    private function postSampleActivity(): void
    {
        // Capital injection
        app(JournalPostingService::class)->createAndPost($this->book, $this->user, [
            'journal_date' => '2026-01-05',
            'description' => 'Capital introduced',
            'currency_code' => 'TZS',
            'lines' => [
                ['account_id' => $this->bankGl->id, 'debit' => '200000', 'credit' => '0'],
                ['account_id' => $this->capital->id, 'debit' => '0', 'credit' => '200000'],
            ],
        ]);

        // Expense
        app(JournalPostingService::class)->createAndPost($this->book, $this->user, [
            'journal_date' => '2026-02-10',
            'description' => 'Rent',
            'currency_code' => 'TZS',
            'lines' => [
                ['account_id' => $this->expense->id, 'debit' => '40000', 'credit' => '0'],
                ['account_id' => $this->bankGl->id, 'debit' => '0', 'credit' => '40000'],
            ],
        ]);

        // Invoice + receipt
        $invoice = app(ReceivableService::class)->createAndPostInvoice($this->book, $this->user, [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-03-15',
            'currency_code' => 'TZS',
            'lines' => [
                [
                    'description' => 'Services',
                    'quantity' => 1,
                    'unit_price' => '100000',
                    'revenue_account_id' => $this->revenue->id,
                ],
            ],
        ]);

        app(ReceivableService::class)->createAndPostReceipt($this->book, $this->user, [
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-03-20',
            'currency_code' => 'TZS',
            'amount' => '100000',
            'bank_account_id' => $this->bank->id,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => '100000'],
            ],
        ]);
    }

    private function bootDemo(): void
    {
        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $this->user = User::factory()->create(['password' => 'password']);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'M3CO',
            'legal_name' => 'M3 Reporting Co',
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
            'period_number' => 1,
            'name' => 'FY2026 Open',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
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

        $this->cash = $make('1000', 'Cash', 'asset', 'debit');
        $this->bankGl = $make('1100', 'Bank', 'asset', 'debit');
        $this->ar = $make('1200', 'AR', 'asset', 'debit');
        $this->capital = $make('3000', 'Share Capital', 'equity', 'credit');
        $this->revenue = $make('4000', 'Sales', 'revenue', 'credit');
        $this->expense = $make('5100', 'Rent', 'expense', 'debit');

        foreach ([
            'cash' => $this->cash->id,
            'bank' => $this->bankGl->id,
            'accounts_receivable' => $this->ar->id,
            'sales_revenue' => $this->revenue->id,
        ] as $key => $id) {
            AccountMapping::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'book_id' => $this->book->id,
                'mapping_key' => $key,
                'account_id' => $id,
            ]);
        }

        $this->bank = BankAccount::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'code' => 'BANK1',
            'name' => 'Main',
            'currency_code' => 'TZS',
            'gl_account_id' => $this->bankGl->id,
            'account_type' => 'bank',
            'status' => 'active',
        ]);

        $this->customer = Customer::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'C1',
            'name' => 'Customer',
            'currency_code' => 'TZS',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setUser($this->user);
        app(TenantContext::class)->setCompany($this->company);
        app(TenantContext::class)->setBook($this->book);
    }
}
