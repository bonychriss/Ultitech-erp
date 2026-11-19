<?php

namespace Tests\Feature;

use App\Application\Services\BankReconciliationService;
use App\Application\Services\FixedAssetService;
use App\Application\Services\InventoryService;
use App\Application\Services\ReceivableService;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\AssetCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DepreciationMethod;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Framework;
use App\Models\InventoryItem;
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

class Milestone04ReconciliationAssetsInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private AccountingBook $book;
    private BankAccount $bank;
    private Account $bankGl;
    private Account $assetGl;
    private Account $accumGl;
    private Account $deprExp;
    private Account $inventoryGl;
    private Account $cogsGl;
    private Account $ap;
    private AssetCategory $category;
    private InventoryItem $item;
    private FiscalPeriod $period;
    private Customer $customer;
    private Account $ar;
    private Account $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->bootDemo();
    }

    public function test_bank_reconciliation_match_and_complete(): void
    {
        $invoice = app(ReceivableService::class)->createAndPostInvoice($this->book, $this->user, [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-03-10',
            'currency_code' => 'TZS',
            'lines' => [[
                'description' => 'Sale',
                'quantity' => 1,
                'unit_price' => '100000',
                'revenue_account_id' => $this->revenue->id,
            ]],
        ]);

        app(ReceivableService::class)->createAndPostReceipt($this->book, $this->user, [
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-03-12',
            'currency_code' => 'TZS',
            'amount' => '100000',
            'bank_account_id' => $this->bank->id,
            'allocations' => [['invoice_id' => $invoice->id, 'amount' => '100000']],
        ]);

        $txn = BankTransaction::withoutGlobalScopes()->where('bank_account_id', $this->bank->id)->firstOrFail();
        $service = app(BankReconciliationService::class);

        $statement = $service->createStatement($this->bank, $this->user, [
            'statement_number' => 'STMT-1',
            'statement_date' => '2026-03-31',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'opening_balance' => 0,
            'closing_balance' => 100000,
            'lines' => [[
                'line_date' => '2026-03-12',
                'description' => 'Receipt',
                'debit' => '100000',
                'credit' => '0',
            ]],
        ]);

        $recon = $service->startReconciliation($this->bank, $statement, $this->user);
        $service->match($recon, $statement->lines->first()->id, $txn->id);
        $completed = $service->complete($recon->fresh(), $this->user);

        $this->assertSame('completed', $completed->status);
        $this->assertTrue($txn->fresh()->is_reconciled);
    }

    public function test_asset_acquisition_and_depreciation_posts_to_gl(): void
    {
        $asset = app(FixedAssetService::class)->acquire($this->book, $this->user, [
            'category_id' => $this->category->id,
            'name' => 'Laptop',
            'acquisition_date' => '2026-01-15',
            'acquisition_cost' => '1200000',
            'salvage_value' => '0',
            'useful_life_months' => 24,
            'credit_account_id' => $this->bankGl->id,
        ]);

        $this->assertSame('active', $asset->status);
        $this->assertTrue(Money::equals((string) $asset->net_book_value, '1200000.0000'));

        $run = app(FixedAssetService::class)->runDepreciation($this->book, $this->user, (int) $this->period->id);
        $this->assertTrue(Money::equals((string) $run->total_amount, '50000.0000'));
        $this->assertTrue(Money::equals((string) $asset->fresh()->accum_depreciation, '50000.0000'));
    }

    public function test_inventory_weighted_average_and_fifo_cogs(): void
    {
        $wa = app(InventoryService::class);
        $wa->receive($this->book, $this->user, $this->item, [
            'movement_date' => '2026-03-01',
            'quantity' => '10',
            'unit_cost' => '1000',
            'credit_account_id' => $this->ap->id,
        ]);
        $wa->receive($this->book, $this->user, $this->item->fresh(), [
            'movement_date' => '2026-03-05',
            'quantity' => '10',
            'unit_cost' => '2000',
            'credit_account_id' => $this->ap->id,
        ]);

        $item = $this->item->fresh();
        $this->assertTrue(Money::equals((string) $item->unit_cost, '1500.0000'));

        $issue = $wa->issue($this->book, $this->user, $item, [
            'movement_date' => '2026-03-10',
            'quantity' => '4',
        ]);
        $this->assertTrue(Money::equals((string) $issue->total_cost, '6000.0000'));

        $fifoItem = InventoryItem::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'sku' => 'FIFO-1',
            'name' => 'FIFO Item',
            'valuation_method' => 'fifo',
            'inventory_account_id' => $this->inventoryGl->id,
            'cogs_account_id' => $this->cogsGl->id,
            'quantity_on_hand' => 0,
            'unit_cost' => 0,
            'total_value' => 0,
            'status' => 'active',
        ]);

        $wa->receive($this->book, $this->user, $fifoItem, [
            'movement_date' => '2026-03-01',
            'quantity' => '5',
            'unit_cost' => '100',
            'credit_account_id' => $this->ap->id,
        ]);
        $wa->receive($this->book, $this->user, $fifoItem->fresh(), [
            'movement_date' => '2026-03-02',
            'quantity' => '5',
            'unit_cost' => '200',
            'credit_account_id' => $this->ap->id,
        ]);
        $fifoIssue = $wa->issue($this->book, $this->user, $fifoItem->fresh(), [
            'movement_date' => '2026-03-03',
            'quantity' => '6',
        ]);
        $this->assertTrue(Money::equals((string) $fifoIssue->total_cost, '700.0000'));
    }

    private function bootDemo(): void
    {
        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $this->user = User::factory()->create(['password' => 'password']);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'M4CO',
            'legal_name' => 'M4 Co',
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
        $this->period = FiscalPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 1,
            'name' => 'Jan 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
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

        $this->bankGl = $make('1100', 'Bank', 'asset', 'debit');
        $this->ar = $make('1200', 'AR', 'asset', 'debit');
        $this->inventoryGl = $make('1300', 'Inventory', 'asset', 'debit');
        $this->assetGl = $make('1500', 'Equipment', 'asset', 'debit');
        $this->accumGl = $make('1510', 'Accum Dep', 'asset', 'credit');
        $this->ap = $make('2000', 'AP', 'liability', 'credit');
        $this->revenue = $make('4000', 'Sales', 'revenue', 'credit');
        $this->cogsGl = $make('5300', 'COGS', 'expense', 'debit');
        $this->deprExp = $make('5400', 'Depreciation', 'expense', 'debit');

        foreach ([
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

        $method = DepreciationMethod::query()->where('code', 'straight_line')->firstOrFail();
        $this->category = AssetCategory::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'code' => 'EQ',
            'name' => 'Equipment',
            'asset_account_id' => $this->assetGl->id,
            'accum_depr_account_id' => $this->accumGl->id,
            'depr_expense_account_id' => $this->deprExp->id,
            'depreciation_method_id' => $method->id,
            'useful_life_months' => 24,
            'salvage_percent' => 0,
        ]);

        $this->item = InventoryItem::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'sku' => 'WA-1',
            'name' => 'WA Item',
            'valuation_method' => 'weighted_average',
            'inventory_account_id' => $this->inventoryGl->id,
            'cogs_account_id' => $this->cogsGl->id,
            'quantity_on_hand' => 0,
            'unit_cost' => 0,
            'total_value' => 0,
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
