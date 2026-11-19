<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\Framework;
use App\Models\Jurisdiction;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Support\TenantContext;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankingAssetGlAutoCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private AccountingBook $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);

        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $this->user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Bank Creator',
            'email' => 'bank.creator@ultitech.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'BANKGL',
            'legal_name' => 'Bank GL Co',
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

        app(TenantContext::class)->setUser($this->user);
        app(TenantContext::class)->setCompany($this->company);
        Sanctum::actingAs($this->user);
    }

    public function test_creating_bank_account_auto_creates_postable_asset_gl(): void
    {
        $response = $this->postJson('/api/v1/bank-accounts', [
            'code' => 'BANK-1',
            'name' => 'CRDB Bank',
            'currency_code' => 'TZS',
            'account_type' => 'bank',
        ], [
            'X-Company-Id' => (string) $this->company->id,
            'X-Book-Id' => (string) $this->book->id,
        ])->assertCreated();

        $bankId = (int) $response->json('data.id');
        $glId = (int) $response->json('data.gl_account_id');
        $this->assertGreaterThan(0, $glId);

        $bank = BankAccount::withoutGlobalScopes()->findOrFail($bankId);
        $gl = Account::withoutGlobalScopes()->with('accountType')->findOrFail($glId);

        $this->assertSame($glId, (int) $bank->gl_account_id);
        $this->assertSame('asset', $gl->accountType?->code);
        $this->assertTrue((bool) $gl->is_postable);
        $this->assertSame('debit', $gl->normal_balance);
        $this->assertSame('CRDB Bank', $gl->name);
    }

    public function test_creating_mobile_and_cash_also_create_asset_gl(): void
    {
        $mobile = $this->postJson('/api/v1/bank-accounts', [
            'code' => 'MOB-1',
            'name' => 'M-Pesa (Vodacom)',
            'currency_code' => 'TZS',
            'account_type' => 'mobile_money',
        ], [
            'X-Company-Id' => (string) $this->company->id,
            'X-Book-Id' => (string) $this->book->id,
        ])->assertCreated();

        $cash = $this->postJson('/api/v1/cash-accounts', [
            'code' => 'CASH-1',
            'name' => 'Office Petty Cash',
            'currency_code' => 'TZS',
        ], [
            'X-Company-Id' => (string) $this->company->id,
            'X-Book-Id' => (string) $this->book->id,
        ])->assertCreated();

        $mobileGl = Account::withoutGlobalScopes()->with('accountType')->findOrFail((int) $mobile->json('data.gl_account_id'));
        $cashGl = Account::withoutGlobalScopes()->with('accountType')->findOrFail((int) $cash->json('data.gl_account_id'));
        $cashRow = CashAccount::withoutGlobalScopes()->findOrFail((int) $cash->json('data.id'));

        $this->assertSame('asset', $mobileGl->accountType?->code);
        $this->assertTrue((bool) $mobileGl->is_postable);
        $this->assertSame('asset', $cashGl->accountType?->code);
        $this->assertTrue((bool) $cashGl->is_postable);
        $this->assertSame($cashGl->id, (int) $cashRow->gl_account_id);
    }

    public function test_rejects_non_asset_gl_when_explicitly_provided(): void
    {
        $expenseTypeId = (int) AccountType::query()->where('code', 'expense')->value('id');
        $expense = Account::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'account_type_id' => $expenseTypeId,
            'code' => '5001',
            'name' => 'Rent',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_system' => false,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
        ]);

        $this->postJson('/api/v1/bank-accounts', [
            'code' => 'BANK-X',
            'name' => 'Bad Link Bank',
            'currency_code' => 'TZS',
            'account_type' => 'bank',
            'gl_account_id' => $expense->id,
        ], [
            'X-Company-Id' => (string) $this->company->id,
            'X-Book-Id' => (string) $this->book->id,
        ])->assertStatus(422);
    }
}
