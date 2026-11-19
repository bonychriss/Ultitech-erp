<?php

namespace Tests\Feature;

use App\Application\Services\BudgetService;
use App\Application\Services\ConsolidationService;
use App\Application\Services\CostCenterService;
use App\Application\Services\FixedAssetService;
use App\Application\Services\FxRevaluationService;
use App\Application\Services\ImpairmentService;
use App\Application\Services\JournalPostingService;
use App\Application\Services\LeaseService;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\DepreciationMethod;
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

class Milestone06AdvancedAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private AccountingBook $book;
    private FiscalYear $year;
    private FiscalPeriod $period;
    private Account $cash;
    private Account $bank;
    private Account $expense;
    private Account $revenue;
    private Account $fxGain;
    private Account $fxLoss;
    private Account $rou;
    private Account $leaseLiab;
    private Account $leaseInt;
    private Account $depr;
    private Account $assetGl;
    private Account $accumGl;
    private CostCenter $ops;
    private AssetCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->bootDemo();
    }

    public function test_budget_versus_actual_and_cost_center_pl(): void
    {
        app(JournalPostingService::class)->createAndPost($this->book, $this->user, [
            'journal_date' => '2026-02-10',
            'description' => 'Ops expense',
            'currency_code' => 'TZS',
            'lines' => [
                [
                    'account_id' => $this->expense->id,
                    'debit' => '80000',
                    'credit' => '0',
                    'description' => 'Ops cost',
                    'dimensions' => ['cost_center_id' => $this->ops->id],
                ],
                [
                    'account_id' => $this->cash->id,
                    'debit' => '0',
                    'credit' => '80000',
                    'description' => 'Cash',
                ],
            ],
        ], 'manual');

        $budget = app(BudgetService::class)->create($this->book, $this->user, [
            'fiscal_year_id' => $this->year->id,
            'code' => 'B1',
            'name' => 'Ops budget',
            'lines' => [[
                'period_id' => $this->period->id,
                'account_id' => $this->expense->id,
                'cost_center_id' => $this->ops->id,
                'amount' => '100000',
            ]],
        ]);
        app(BudgetService::class)->approve($budget);
        $vs = app(BudgetService::class)->versusActual($budget->fresh('lines'));

        $this->assertCount(1, $vs);
        $this->assertTrue(Money::equals($vs[0]['budget'], '100000.0000'));
        $this->assertTrue(Money::equals($vs[0]['actual'], '80000.0000'));
        $this->assertTrue(Money::equals($vs[0]['variance'], '20000.0000'));

        $pl = app(CostCenterService::class)->profitAndLoss($this->book, '2026-02-01', '2026-02-28');
        $ops = collect($pl)->firstWhere('code', 'OPS');
        $this->assertNotNull($ops);
        $this->assertTrue(Money::equals($ops['expense'], '80000.0000'));
    }

    public function test_fx_revaluation_posts_unrealized_gain(): void
    {
        // USD receivable position: 100 USD booked at 2500 = 250000 TZS
        app(JournalPostingService::class)->createAndPost($this->book, $this->user, [
            'journal_date' => '2026-02-05',
            'description' => 'USD sale',
            'currency_code' => 'USD',
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => '100', 'credit' => '0', 'description' => 'USD bank'],
                ['account_id' => $this->revenue->id, 'debit' => '0', 'credit' => '100', 'description' => 'Revenue'],
            ],
        ], 'manual');

        // Closing rate USD/TZS = 2600 → revalued 260000, gain 10000
        ExchangeRate::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'from_currency' => 'USD',
            'to_currency' => 'TZS',
            'rate_date' => '2026-02-28',
            'rate' => 2600,
            'source' => 'manual',
            'created_by' => $this->user->id,
        ]);

        $reval = app(FxRevaluationService::class)->run($this->book, $this->user, (int) $this->period->id);

        $this->assertTrue(Money::equals(Money::normalize($reval->total_gain), '10000.0000'));
        $this->assertTrue(Money::isZero(Money::normalize($reval->total_loss)));
        $this->assertDatabaseHas('acc_fx_revaluation_lines', [
            'fx_revaluation_id' => $reval->id,
            'currency_code' => 'USD',
        ]);
    }

    public function test_lease_commencement_and_period_posting(): void
    {
        $lease = app(LeaseService::class)->create($this->book, $this->user, [
            'name' => 'Office',
            'commencement_date' => '2026-01-01',
            'term_months' => 3,
            'payment_amount' => '100000',
            'annual_rate_percent' => '12',
            'rou_account_id' => $this->rou->id,
            'liability_account_id' => $this->leaseLiab->id,
            'interest_account_id' => $this->leaseInt->id,
            'depreciation_account_id' => $this->depr->id,
        ]);

        $this->assertSame(3, $lease->schedules->count());
        $this->assertTrue(bccomp(Money::normalize($lease->initial_liability), '0', 4) > 0);

        $schedule = app(LeaseService::class)->postPeriod($lease, $this->book, $this->user, 1, $this->cash->id);
        $this->assertSame('posted', $schedule->status);
        $this->assertNotNull($schedule->journal_id);
    }

    public function test_impairment_and_consolidation_run(): void
    {
        $asset = app(FixedAssetService::class)->acquire($this->book, $this->user, [
            'category_id' => $this->category->id,
            'name' => 'Machine',
            'acquisition_date' => '2026-02-01',
            'acquisition_cost' => '1200000',
            'salvage_value' => 0,
            'credit_account_id' => $this->cash->id,
        ]);

        $test = app(ImpairmentService::class)->impairFixedAsset($this->book, $this->user, [
            'asset_id' => $asset->id,
            'test_date' => '2026-02-15',
            'recoverable_amount' => '900000',
        ]);

        $this->assertTrue(Money::equals(Money::normalize($test->impairment_loss), '300000.0000'));
        $this->assertTrue(Money::equals(Money::normalize($asset->fresh()->net_book_value), '900000.0000'));

        $group = app(ConsolidationService::class)->createGroup((int) $this->company->id, [
            'code' => 'GRP1',
            'name' => 'Group',
            'parent_book_id' => $this->book->id,
            'reporting_currency' => 'TZS',
            'members' => [[
                'member_company_id' => $this->company->id,
                'member_book_id' => $this->book->id,
                'ownership_percent' => 100,
                'is_parent' => true,
            ]],
        ]);

        $run = app(ConsolidationService::class)->run($group, $this->user, '2026-02-28');
        $this->assertTrue($run->result_json['balanced']);
        $this->assertGreaterThan(0, count($run->result_json['lines']));
    }

    private function bootDemo(): void
    {
        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $this->user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'M06',
            'email' => 'm06@ultitech.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'M06',
            'legal_name' => 'M06 Co',
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
        ExchangeRate::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'from_currency' => 'USD',
            'to_currency' => 'TZS',
            'rate_date' => '2026-02-01',
            'rate' => 2500,
            'source' => 'manual',
            'created_by' => $this->user->id,
        ]);

        $this->year = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        FiscalPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->year->id,
            'period_number' => 1,
            'name' => 'Jan 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'open',
        ]);
        $this->period = FiscalPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->year->id,
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

        $this->cash = $make('1000', 'Cash', 'asset', 'debit');
        $this->bank = $make('1100', 'USD Bank', 'asset', 'debit');
        $this->assetGl = $make('1500', 'Equipment', 'asset', 'debit');
        $this->accumGl = $make('1510', 'Accum Dep', 'asset', 'credit');
        $this->rou = $make('1600', 'ROU', 'asset', 'debit');
        $this->leaseLiab = $make('2300', 'Lease Liab', 'liability', 'credit');
        $this->revenue = $make('4000', 'Sales', 'revenue', 'credit');
        $this->expense = $make('5000', 'OpEx', 'expense', 'debit');
        $this->depr = $make('5400', 'Depreciation', 'expense', 'debit');
        $this->fxGain = $make('5500', 'FX Gain', 'revenue', 'credit');
        $this->fxLoss = $make('5600', 'FX Loss', 'expense', 'debit');
        $this->leaseInt = $make('5700', 'Lease Interest', 'expense', 'debit');

        foreach ([
            'cash' => $this->cash->id,
            'bank' => $this->bank->id,
            'fx_gain' => $this->fxGain->id,
            'fx_loss' => $this->fxLoss->id,
        ] as $key => $id) {
            AccountMapping::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'book_id' => $this->book->id,
                'mapping_key' => $key,
                'account_id' => $id,
            ]);
        }

        $this->ops = CostCenter::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'code' => 'OPS',
            'name' => 'Operations',
            'status' => 'active',
        ]);

        $method = DepreciationMethod::query()->where('code', 'straight_line')->firstOrFail();
        $this->category = AssetCategory::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'code' => 'EQ',
            'name' => 'Equipment',
            'asset_account_id' => $this->assetGl->id,
            'accum_depr_account_id' => $this->accumGl->id,
            'depr_expense_account_id' => $this->depr->id,
            'depreciation_method_id' => $method->id,
            'useful_life_months' => 36,
            'salvage_percent' => 0,
        ]);

        app(TenantContext::class)->setUser($this->user);
        app(TenantContext::class)->setCompany($this->company);
        app(TenantContext::class)->setBook($this->book);
    }
}
