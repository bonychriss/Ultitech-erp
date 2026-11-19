<?php

namespace Tests\Feature;

use App\Application\Services\AccountingPolicyService;
use App\Application\Services\PayableService;
use App\Application\Services\ReceivableService;
use App\Application\Services\TaxEngineService;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Framework;
use App\Models\Jurisdiction;
use App\Models\PostingSequence;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Models\TaxType;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Support\Money;
use App\Support\TenantContext;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Milestone05TaxAndStandardsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private AccountingBook $book;
    private Customer $customer;
    private Supplier $supplier;
    private Account $ar;
    private Account $ap;
    private Account $revenue;
    private Account $expense;
    private Account $vatOutput;
    private Account $vatInput;
    private TaxCode $vatCode;
    private TaxType $vatType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->bootDemo();
    }

    public function test_tax_engine_uses_effective_dated_rate_not_hardcoded(): void
    {
        $calc = app(TaxEngineService::class)->calculate($this->vatCode, '100000.0000', '2026-02-15');

        $this->assertTrue(Money::equals($calc['tax_amount'], '18000.0000'));
        $this->assertTrue(Money::equals($calc['rate_percent'], '18.0000'));
        $this->assertDatabaseHas('acc_tax_rates', [
            'id' => $calc['tax_rate']->id,
            'rate_percent' => 18,
        ]);
    }

    public function test_invoice_and_bill_record_tax_transactions_and_return_reconciles(): void
    {
        $invoice = app(ReceivableService::class)->createAndPostInvoice($this->book, $this->user, [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-02-10',
            'currency_code' => 'TZS',
            'tax_code_id' => $this->vatCode->id,
            'lines' => [[
                'description' => 'Taxable sale',
                'quantity' => 1,
                'unit_price' => '100000',
                'revenue_account_id' => $this->revenue->id,
            ]],
        ]);

        $this->assertTrue(Money::equals(Money::normalize($invoice->tax_total), '18000.0000'));
        $this->assertDatabaseHas('acc_tax_transactions', [
            'source_type' => 'customer_invoice',
            'source_id' => $invoice->id,
            'direction' => 'output',
            'tax_amount' => 18000,
        ]);

        $bill = app(PayableService::class)->createAndPostBill($this->book, $this->user, [
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2026-02-12',
            'currency_code' => 'TZS',
            'tax_code_id' => $this->vatCode->id,
            'lines' => [[
                'description' => 'Taxable purchase',
                'quantity' => 1,
                'unit_price' => '50000',
                'expense_account_id' => $this->expense->id,
            ]],
        ]);

        $this->assertTrue(Money::equals(Money::normalize($bill->tax_total), '9000.0000'));
        $this->assertDatabaseHas('acc_tax_transactions', [
            'source_type' => 'supplier_bill',
            'source_id' => $bill->id,
            'direction' => 'input',
            'tax_amount' => 9000,
        ]);

        $return = app(TaxEngineService::class)->prepareReturn(
            (int) $this->company->id,
            (int) $this->book->jurisdiction_id,
            (int) $this->vatType->id,
            '2026-02-01',
            '2026-02-28',
            $this->user
        );

        $this->assertTrue(Money::equals(Money::normalize($return->output_tax), '18000.0000'));
        $this->assertTrue(Money::equals(Money::normalize($return->input_tax), '9000.0000'));
        $this->assertTrue(Money::equals(Money::normalize($return->net_tax), '9000.0000'));

        $recon = app(TaxEngineService::class)->reconcileReturn($return, $this->book);

        $this->assertSame('balanced', $recon->status);
        $this->assertTrue(Money::isZero(Money::normalize($recon->difference)));
    }

    public function test_policy_versioning_and_framework_checklist(): void
    {
        $service = app(AccountingPolicyService::class);
        $policy = $service->createPolicy($this->book, [
            'code' => 'REVENUE',
            'name' => 'Revenue recognition',
        ]);
        $draft = $service->addVersion($policy, [
            'effective_from' => '2026-01-01',
            'policy_json' => ['basis' => 'IFRS 15'],
        ], $this->user, false);

        $this->assertSame('draft', $draft->status);
        $approved = $service->approveVersion($draft, $this->user);
        $this->assertSame('approved', $approved->status);

        $active = $service->activePolicy($this->book, 'REVENUE', '2026-06-01');
        $this->assertNotNull($active);
        $this->assertSame(1, $active->version_no);

        $checklist = $service->complianceChecklist($this->book);
        $this->assertSame('IFRS', $checklist['framework']['code']);
        $this->assertGreaterThan(0, $checklist['standards_count']);
    }

    public function test_tax_and_standards_api_endpoints(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Company-Id' => (string) $this->company->id,
            'X-Book-Id' => (string) $this->book->id,
            'Accept' => 'application/json',
        ];

        $this->withHeaders($headers)->getJson('/api/v1/tax-rates')->assertOk()
            ->assertJsonFragment(['code' => 'VAT-STD']);

        $this->withHeaders($headers)->getJson('/api/v1/tax-codes')->assertOk()
            ->assertJsonFragment(['code' => 'VAT18']);

        $this->withHeaders($headers)->postJson('/api/v1/tax/calculate', [
            'tax_code_id' => $this->vatCode->id,
            'taxable_amount' => 100000,
            'as_of_date' => '2026-02-01',
        ])->assertOk()->assertJsonPath('data.tax_amount', '18000.0000');

        $this->withHeaders($headers)->getJson('/api/v1/standards/frameworks')->assertOk()
            ->assertJsonFragment(['code' => 'IFRS']);

        $this->withHeaders($headers)->postJson('/api/v1/standards/policies', [
            'code' => 'PPE',
            'name' => 'PPE policy',
            'effective_from' => '2026-01-01',
            'policy_json' => ['basis' => 'IAS 16'],
            'approve' => true,
        ])->assertCreated();

        $this->withHeaders($headers)->getJson('/api/v1/standards/checklist')->assertOk()
            ->assertJsonPath('data.framework.code', 'IFRS');
    }

    private function bootDemo(): void
    {
        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $this->user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'M05 Tester',
            'email' => 'm05@ultitech.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'M05',
            'legal_name' => 'M05 Co',
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
            'period_number' => 2,
            'name' => 'Feb 2026',
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
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

        $this->ar = $make('1200', 'AR', 'asset', 'debit');
        $this->ap = $make('2000', 'AP', 'liability', 'credit');
        $this->vatOutput = $make('2100', 'VAT Output', 'liability', 'credit');
        $this->vatInput = $make('2200', 'VAT Input', 'asset', 'debit');
        $this->revenue = $make('4000', 'Sales', 'revenue', 'credit');
        $this->expense = $make('5200', 'Purchases', 'expense', 'debit');

        foreach ([
            'accounts_receivable' => $this->ar->id,
            'accounts_payable' => $this->ap->id,
            'vat_output' => $this->vatOutput->id,
            'vat_input' => $this->vatInput->id,
            'sales_revenue' => $this->revenue->id,
            'purchases' => $this->expense->id,
        ] as $key => $id) {
            AccountMapping::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'book_id' => $this->book->id,
                'mapping_key' => $key,
                'account_id' => $id,
            ]);
        }

        $this->customer = Customer::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'C1',
            'name' => 'Customer',
            'currency_code' => 'TZS',
            'status' => 'active',
        ]);
        $this->supplier = Supplier::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'S1',
            'name' => 'Supplier',
            'currency_code' => 'TZS',
            'status' => 'active',
        ]);

        $this->vatType = TaxType::query()->where('code', 'VAT')->firstOrFail();
        $rate = TaxRate::query()
            ->where('jurisdiction_id', $this->book->jurisdiction_id)
            ->where('code', 'VAT-STD')
            ->firstOrFail();

        $this->vatCode = TaxCode::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'jurisdiction_id' => $this->book->jurisdiction_id,
            'tax_rate_id' => $rate->id,
            'code' => 'VAT18',
            'name' => 'Standard VAT 18%',
            'applies_to' => 'both',
            'is_recoverable' => true,
            'is_active' => true,
        ]);

        app(TenantContext::class)->setUser($this->user);
        app(TenantContext::class)->setCompany($this->company);
        app(TenantContext::class)->setBook($this->book);
    }
}
