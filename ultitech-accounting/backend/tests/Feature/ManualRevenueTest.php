<?php

namespace Tests\Feature;

use App\Application\Services\ReceivableService;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\BankAccount;
use App\Models\BankTransaction;
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

class ManualRevenueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private AccountingBook $book;
    private BankAccount $bank;
    private Account $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->bootDemo();
    }

    public function test_manual_revenue_posts_paid_invoice_and_bank_receipt(): void
    {
        $result = app(ReceivableService::class)->createAndPostManualRevenue($this->book, $this->user, [
            'revenue_date' => '2026-03-15',
            'currency_code' => 'TZS',
            'description' => 'Scrap sale',
            'amount' => '75000',
            'revenue_account_id' => $this->revenue->id,
            'bank_account_id' => $this->bank->id,
        ]);

        $invoice = $result['invoice']->fresh();
        $receipt = $result['receipt'];

        $this->assertSame('paid', $invoice->status);
        $this->assertTrue(Money::isZero(Money::normalize($invoice->balance_due)));
        $this->assertSame('posted', $receipt->status);
        $this->assertDatabaseHas('acc_customers', [
            'company_id' => $this->company->id,
            'code' => 'WALKIN',
        ]);
        $this->assertTrue(
            BankTransaction::withoutGlobalScopes()
                ->where('bank_account_id', $this->bank->id)
                ->where('source_type', 'customer_receipt')
                ->exists()
        );
        $this->assertInstanceOf(Customer::class, $invoice->customer);
    }

    private function bootDemo(): void
    {
        $adminRole = Role::query()->where('code', 'company_admin')->firstOrFail();
        $this->user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Tester',
            'email' => 'manual-rev@example.com',
            'password' => bcrypt('secret'),
            'status' => 'active',
        ]);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'DEMO',
            'legal_name' => 'Demo Co',
            'trading_name' => 'Demo',
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

        $bankGl = $make('1100', 'Bank', 'asset', 'debit');
        $ar = $make('1200', 'AR', 'asset', 'debit');
        $this->revenue = $make('4003', 'Other Income', 'revenue', 'credit');

        foreach ([
            'bank' => $bankGl->id,
            'accounts_receivable' => $ar->id,
            'sales_revenue' => $this->revenue->id,
            'cash' => $bankGl->id,
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
            'gl_account_id' => $bankGl->id,
            'account_type' => 'bank',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setUser($this->user);
        app(TenantContext::class)->setCompany($this->company);
        app(TenantContext::class)->setBook($this->book);
    }
}
