<?php

namespace Tests\Feature;

use App\Application\Services\CompanyBootstrapService;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountingBook;
use App\Models\Company;
use App\Models\Framework;
use App\Models\Jurisdiction;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StandardChartBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_chart_creates_headers_and_postable_children(): void
    {
        $this->seed(CatalogSeeder::class);

        $company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'COA1',
            'legal_name' => 'CoA Co',
            'country_code' => 'TZ',
            'base_currency_code' => 'TZS',
            'timezone' => 'Africa/Dar_es_Salaam',
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

        $assets = Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '1000')->first();
        $cash = Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '1001')->first();
        $bank = Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '1002')->first();
        $mobile = Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '1003')->first();

        $this->assertNotNull($assets);
        $this->assertFalse((bool) $assets->is_postable);
        $this->assertNotNull($cash);
        $this->assertTrue((bool) $cash->is_postable);
        $this->assertSame($assets->id, (int) $cash->parent_id);
        $this->assertSame($assets->id, (int) $bank->parent_id);
        $this->assertSame($assets->id, (int) $mobile->parent_id);

        $this->assertSame(
            $cash->id,
            (int) AccountMapping::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('mapping_key', 'cash')
                ->value('account_id')
        );
        $this->assertSame(
            $bank->id,
            (int) AccountMapping::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('mapping_key', 'bank')
                ->value('account_id')
        );
    }
}
