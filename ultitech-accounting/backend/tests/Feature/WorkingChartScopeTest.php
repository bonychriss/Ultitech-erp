<?php

namespace Tests\Feature;

use App\Application\Services\CompanyBootstrapService;
use App\Models\Account;
use App\Models\AccountingBook;
use App\Models\AccountType;
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

class WorkingChartScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_working_scope_hides_erp_clutter_keeps_template_and_user(): void
    {
        $this->seed(CatalogSeeder::class);

        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'CoA Scope',
            'email' => 'coa.scope@ultitech.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'SCOPE1',
            'legal_name' => 'Scope Co',
            'country_code' => 'TZ',
            'base_currency_code' => 'TZS',
            'timezone' => 'Africa/Dar_es_Salaam',
            'status' => 'active',
        ]);
        UserCompanyAccess::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $adminRole->id,
            'is_default' => true,
            'status' => 'active',
        ]);
        $book = AccountingBook::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'IFRS-TZ',
            'name' => 'Primary',
            'framework_id' => Framework::query()->where('code', 'IFRS')->value('id'),
            'jurisdiction_id' => Jurisdiction::query()->where('code', 'TZ')->value('id'),
            'functional_currency_code' => 'TZS',
            'is_primary' => true,
            'status' => 'active',
        ]);

        app(CompanyBootstrapService::class)->ensureStandardChart($company, $book);

        $assetTypeId = (int) AccountType::query()->where('code', 'asset')->value('id');
        Account::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'book_id' => $book->id,
            'account_type_id' => $assetTypeId,
            'code' => '1000-FA19',
            'name' => '1000 - Assets',
            'normal_balance' => 'debit',
            'is_postable' => false,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 2,
            'is_system' => false,
            'path' => 'erp',
        ]);
        Account::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'book_id' => $book->id,
            'account_type_id' => $assetTypeId,
            'code' => '101001',
            'name' => 'salt',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
            'is_system' => false,
            'path' => 'erp',
        ]);
        Account::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'book_id' => $book->id,
            'account_type_id' => $assetTypeId,
            'code' => '1199',
            'name' => 'Custom Wallet',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
            'is_system' => false,
            'path' => 'user',
        ]);

        app(TenantContext::class)->setUser($user);
        app(TenantContext::class)->setCompany($company);
        Sanctum::actingAs($user);

        $headers = [
            'X-Company-Id' => (string) $company->id,
            'X-Book-Id' => (string) $book->id,
        ];

        $working = $this->getJson('/api/v1/accounts?scope=working', $headers)->assertOk()->json('data');
        $codes = collect($working)->pluck('code')->all();

        $this->assertContains('1001', $codes);
        $this->assertContains('1199', $codes);
        $this->assertNotContains('1000-FA19', $codes);
        $this->assertNotContains('101001', $codes);
        $this->assertCount(count(CompanyBootstrapService::STANDARD_CODES) + 1, $working);

        $all = $this->getJson('/api/v1/accounts?scope=all', $headers)->assertOk()->json('data');
        $this->assertGreaterThan(count($working), count($all));
    }

    public function test_store_skips_hidden_code_collision(): void
    {
        $this->seed(CatalogSeeder::class);

        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'CoA Alloc',
            'email' => 'coa.alloc@ultitech.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'ALLOC1',
            'legal_name' => 'Alloc Co',
            'country_code' => 'TZ',
            'base_currency_code' => 'TZS',
            'timezone' => 'Africa/Dar_es_Salaam',
            'status' => 'active',
        ]);
        UserCompanyAccess::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $adminRole->id,
            'is_default' => true,
            'status' => 'active',
        ]);
        $book = AccountingBook::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'IFRS-TZ',
            'name' => 'Primary',
            'framework_id' => Framework::query()->where('code', 'IFRS')->value('id'),
            'jurisdiction_id' => Jurisdiction::query()->where('code', 'TZ')->value('id'),
            'functional_currency_code' => 'TZS',
            'is_primary' => true,
            'status' => 'active',
        ]);

        app(CompanyBootstrapService::class)->ensureStandardChart($company, $book);

        $expenseTypeId = (int) AccountType::query()->where('code', 'expense')->value('id');
        Account::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'book_id' => $book->id,
            'account_type_id' => $expenseTypeId,
            'code' => '5005',
            'name' => 'Hidden expense',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
            'is_system' => false,
            'path' => 'erp',
        ]);

        app(TenantContext::class)->setUser($user);
        app(TenantContext::class)->setCompany($company);
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/accounts', [
            'code' => '5005',
            'name' => 'assety',
            'account_type_id' => $expenseTypeId,
            'normal_balance' => 'debit',
            'is_postable' => true,
            'allow_manual_entry' => true,
        ], [
            'X-Company-Id' => (string) $company->id,
            'X-Book-Id' => (string) $book->id,
        ])->assertCreated()->json('data');

        $this->assertSame('5006', $created['code']);
        $this->assertSame('user', $created['path']);
    }
}
