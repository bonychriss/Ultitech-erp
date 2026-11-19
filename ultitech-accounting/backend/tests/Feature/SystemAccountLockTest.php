<?php

namespace Tests\Feature;

use App\Application\Services\CompanyBootstrapService;
use App\Models\Account;
use App\Models\AccountingBook;
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

class SystemAccountLockTest extends TestCase
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
            'name' => 'CoA Locker',
            'email' => 'coa.lock@ultitech.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'LOCK1',
            'legal_name' => 'Lock Co',
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

        app(CompanyBootstrapService::class)->ensureStandardChart($this->company, $this->book);
        app(TenantContext::class)->setUser($this->user);
        app(TenantContext::class)->setCompany($this->company);
        Sanctum::actingAs($this->user);
    }

    public function test_system_accounts_are_marked_locked(): void
    {
        $cash = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', '1001')
            ->firstOrFail();

        $this->assertTrue((bool) $cash->is_system);
    }

    public function test_system_account_rejects_status_and_delete(): void
    {
        $cash = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', '1001')
            ->firstOrFail();

        $headers = [
            'X-Company-Id' => (string) $this->company->id,
            'X-Book-Id' => (string) $this->book->id,
        ];

        $this->patchJson('/api/v1/accounts/'.$cash->id, ['status' => 'inactive'], $headers)
            ->assertStatus(422);

        $this->deleteJson('/api/v1/accounts/'.$cash->id, [], $headers)
            ->assertStatus(422);

        $this->assertDatabaseHas('acc_accounts', [
            'id' => $cash->id,
            'status' => 'active',
        ]);
    }

    public function test_system_account_allows_name_only(): void
    {
        $cash = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', '1001')
            ->firstOrFail();

        $this->patchJson('/api/v1/accounts/'.$cash->id, [
            'name' => 'Cash on Hand',
        ], [
            'X-Company-Id' => (string) $this->company->id,
            'X-Book-Id' => (string) $this->book->id,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Cash on Hand');
    }
}
