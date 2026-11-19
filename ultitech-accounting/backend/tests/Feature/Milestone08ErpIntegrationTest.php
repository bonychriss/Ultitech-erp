<?php

namespace Tests\Feature;

use App\Integration\Erp\ReadOnlyErpGateway;
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
use RuntimeException;
use Tests\TestCase;

class Milestone08ErpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);

        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $this->user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'M08',
            'email' => 'm08@ultitech.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'M08',
            'legal_name' => 'M08 Co',
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
        AccountingBook::withoutGlobalScopes()->create([
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
    }

    public function test_erp_gateway_blocks_write_sql(): void
    {
        $gateway = new ReadOnlyErpGateway('mysql'); // use accounting mysql; gate is SQL-shape based

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ERP gateway blocked');
        $gateway->select('DELETE FROM erp_accounts WHERE id = 1');
    }

    public function test_erp_gateway_blocks_update_keyword(): void
    {
        $gateway = new ReadOnlyErpGateway('mysql');
        $this->expectException(RuntimeException::class);
        $gateway->select('UPDATE erp_accounts SET name = "x" WHERE id = 1');
    }

    public function test_erp_status_endpoint_requires_auth_and_permission(): void
    {
        $token = $this->user->createToken('t')->plainTextToken;
        $book = AccountingBook::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Company-Id' => (string) $this->company->id,
            'X-Book-Id' => (string) $book->id,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/erp/status')->assertOk()
            ->assertJsonPath('data.safety.read_only', true)
            ->assertJsonPath('data.safety.erp_writes', false);
    }
}
