<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\AssetCategory;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepreciationMethod;
use App\Models\ExchangeRate;
use App\Models\InventoryItem;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Framework;
use App\Models\Jurisdiction;
use App\Models\PostingSequence;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserCompanyAccess;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $framework = Framework::query()->where('code', 'IFRS')->firstOrFail();
        $jurisdiction = Jurisdiction::query()->where('code', 'TZ')->firstOrFail();

        $user = User::query()->updateOrCreate(
            ['email' => 'admin@ultitech.local'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'UltiTech Admin',
                'password' => 'password',
                'is_active' => true,
            ]
        );

        $company = Company::query()->updateOrCreate(
            ['code' => 'DEMO'],
            [
                'uuid' => (string) Str::uuid(),
                'legal_name' => 'UltiTech Demo Limited',
                'trading_name' => 'UltiTech Demo',
                'country_code' => 'TZ',
                'base_currency_code' => 'TZS',
                'timezone' => 'Africa/Dar_es_Salaam',
                'status' => 'active',
            ]
        );

        CompanySetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            ['settings_json' => ['date_format' => 'Y-m-d', 'number_format' => 'en_US']]
        );

        UserCompanyAccess::query()->updateOrCreate(
            ['user_id' => $user->id, 'company_id' => $company->id],
            ['role_id' => $adminRole->id, 'is_default' => true, 'status' => 'active']
        );

        $book = AccountingBook::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'IFRS-TZ'],
            [
                'name' => 'Primary IFRS Tanzania Book',
                'framework_id' => $framework->id,
                'jurisdiction_id' => $jurisdiction->id,
                'functional_currency_code' => 'TZS',
                'is_primary' => true,
                'status' => 'active',
            ]
        );

        PostingSequence::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'book_id' => $book->id, 'sequence_key' => 'journal'],
            ['prefix' => 'JE', 'next_number' => 1, 'padding' => 6]
        );

        ExchangeRate::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $company->id,
                'from_currency' => 'TZS',
                'to_currency' => 'TZS',
                'rate_date' => '2026-01-01',
            ],
            ['rate' => 1, 'source' => 'system', 'created_by' => $user->id]
        );

        ExchangeRate::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $company->id,
                'from_currency' => 'USD',
                'to_currency' => 'TZS',
                'rate_date' => '2026-01-01',
            ],
            ['rate' => 2500, 'source' => 'manual', 'created_by' => $user->id]
        );

        $year = FiscalYear::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'name' => 'FY2026'],
            [
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'open',
            ]
        );

        if ($year->periods()->count() === 0) {
            $start = Carbon::parse('2026-01-01');
            for ($i = 1; $i <= 12; $i++) {
                FiscalPeriod::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'fiscal_year_id' => $year->id,
                    'period_number' => $i,
                    'name' => $start->format('M Y'),
                    'start_date' => $start->copy()->startOfMonth()->toDateString(),
                    'end_date' => $start->copy()->endOfMonth()->toDateString(),
                    'status' => 'open',
                ]);
                $start->addMonth();
            }
        }

        $types = AccountType::query()->pluck('id', 'code');
        $accounts = [
            ['1000', 'Cash', 'asset', 'debit'],
            ['1100', 'Bank', 'asset', 'debit'],
            ['1200', 'Accounts Receivable', 'asset', 'debit'],
            ['1300', 'Inventory', 'asset', 'debit'],
            ['1500', 'Furniture & Equipment', 'asset', 'debit'],
            ['1510', 'Accumulated Depreciation - F&E', 'asset', 'credit'],
            ['2000', 'Accounts Payable', 'liability', 'credit'],
            ['2100', 'VAT Output', 'liability', 'credit'],
            ['2200', 'VAT Input', 'asset', 'debit'],
            ['3000', 'Share Capital', 'equity', 'credit'],
            ['3100', 'Retained Earnings', 'equity', 'credit'],
            ['4000', 'Sales Revenue', 'revenue', 'credit'],
            ['5000', 'Operating Expenses', 'expense', 'debit'],
            ['5100', 'Rent Expense', 'expense', 'debit'],
            ['5200', 'Purchases', 'expense', 'debit'],
            ['5300', 'Cost of Goods Sold', 'expense', 'debit'],
            ['5400', 'Depreciation Expense', 'expense', 'debit'],
            ['5500', 'FX Gain', 'revenue', 'credit'],
            ['5600', 'FX Loss', 'expense', 'debit'],
            ['1600', 'Right-of-Use Assets', 'asset', 'debit'],
            ['2300', 'Lease Liability', 'liability', 'credit'],
            ['5700', 'Lease Interest', 'expense', 'debit'],
        ];

        $accountIds = [];
        foreach ($accounts as [$code, $name, $type, $balance]) {
            $account = Account::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'book_id' => $book->id, 'code' => $code],
                [
                    'account_type_id' => $types[$type],
                    'name' => $name,
                    'normal_balance' => $balance,
                    'is_postable' => true,
                    'is_system' => in_array($code, ['3100'], true),
                    'allow_manual_entry' => true,
                    'status' => 'active',
                    'level' => 1,
                ]
            );
            $accountIds[$code] = $account->id;
        }

        foreach ([
            'cash' => '1000',
            'bank' => '1100',
            'accounts_receivable' => '1200',
            'accounts_payable' => '2000',
            'vat_output' => '2100',
            'vat_input' => '2200',
            'sales_revenue' => '4000',
            'purchases' => '5200',
            'retained_earnings' => '3100',
            'fx_gain' => '5500',
            'fx_loss' => '5600',
            'rou_asset' => '1600',
            'lease_liability' => '2300',
            'lease_interest' => '5700',
        ] as $key => $code) {
            AccountMapping::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'book_id' => $book->id, 'mapping_key' => $key],
                ['account_id' => $accountIds[$code]]
            );
        }

        BankAccount::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'book_id' => $book->id, 'code' => 'CRDB-MAIN'],
            [
                'name' => 'CRDB Main Operating',
                'bank_name' => 'CRDB Bank',
                'account_number' => '0150123456789',
                'currency_code' => 'TZS',
                'gl_account_id' => $accountIds['1100'],
                'account_type' => 'bank',
                'status' => 'active',
            ]
        );

        CashAccount::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'book_id' => $book->id, 'code' => 'PETTY'],
            [
                'name' => 'Petty Cash',
                'currency_code' => 'TZS',
                'gl_account_id' => $accountIds['1000'],
                'status' => 'active',
            ]
        );

        Customer::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'CUST001'],
            [
                'name' => 'Dar Trading Co Ltd',
                'email' => 'accounts@dartrading.example',
                'phone' => '+255712000001',
                'currency_code' => 'TZS',
                'status' => 'active',
            ]
        );

        Supplier::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'SUP001'],
            [
                'name' => 'Mwanza Supplies Ltd',
                'email' => 'billing@mwanzasupplies.example',
                'phone' => '+255754000001',
                'currency_code' => 'TZS',
                'status' => 'active',
            ]
        );

        $method = DepreciationMethod::query()->where('code', 'straight_line')->first();
        if ($method) {
            AssetCategory::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'book_id' => $book->id, 'code' => 'FNE'],
                [
                    'name' => 'Furniture & Equipment',
                    'asset_account_id' => $accountIds['1500'],
                    'accum_depr_account_id' => $accountIds['1510'],
                    'depr_expense_account_id' => $accountIds['5400'],
                    'depreciation_method_id' => $method->id,
                    'useful_life_months' => 36,
                    'salvage_percent' => 0,
                ]
            );
        }

        InventoryItem::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'book_id' => $book->id, 'sku' => 'SKU-001'],
            [
                'name' => 'Standard Widget',
                'valuation_method' => 'weighted_average',
                'inventory_account_id' => $accountIds['1300'],
                'cogs_account_id' => $accountIds['5300'],
                'quantity_on_hand' => 0,
                'unit_cost' => 0,
                'total_value' => 0,
                'status' => 'active',
            ]
        );

        $workflow = \App\Models\ApprovalWorkflow::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'JE-APPROVE'],
            [
                'name' => 'Manual journal approval',
                'entity_type' => 'journal_entry',
                'is_active' => true,
            ]
        );
        if ($workflow->steps()->count() === 0) {
            \App\Models\ApprovalStep::query()->create([
                'workflow_id' => $workflow->id,
                'step_no' => 1,
                'name' => 'Accountant review',
                'required_permission' => 'approvals.act',
            ]);
        }

        \App\Models\CostCenter::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'book_id' => $book->id, 'code' => 'ADMIN'],
            ['name' => 'Administration', 'status' => 'active']
        );
        \App\Models\CostCenter::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'book_id' => $book->id, 'code' => 'OPS'],
            ['name' => 'Operations', 'status' => 'active']
        );

        $vatRateId = \Illuminate\Support\Facades\DB::table('acc_tax_rates')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->where('code', 'VAT-STD')
            ->where('effective_from', '2015-07-01')
            ->value('id');

        if ($vatRateId) {
            \App\Models\TaxCode::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'code' => 'VAT18'],
                [
                    'jurisdiction_id' => $jurisdiction->id,
                    'tax_rate_id' => $vatRateId,
                    'name' => 'Standard VAT 18%',
                    'applies_to' => 'both',
                    'is_recoverable' => true,
                    'is_active' => true,
                ]
            );
            \App\Models\TaxCode::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'code' => 'VAT0'],
                [
                    'jurisdiction_id' => $jurisdiction->id,
                    'tax_rate_id' => \Illuminate\Support\Facades\DB::table('acc_tax_rates')
                        ->where('jurisdiction_id', $jurisdiction->id)
                        ->where('code', 'VAT-ZERO')
                        ->value('id'),
                    'name' => 'Zero-rated VAT',
                    'applies_to' => 'both',
                    'is_recoverable' => false,
                    'is_active' => true,
                ]
            );
        }

        \App\Models\AccountingPolicy::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'book_id' => $book->id, 'code' => 'REVENUE'],
            ['name' => 'Revenue recognition']
        );
        $policy = \App\Models\AccountingPolicy::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('book_id', $book->id)
            ->where('code', 'REVENUE')
            ->first();
        if ($policy && $policy->versions()->count() === 0) {
            \App\Models\PolicyVersion::query()->create([
                'policy_id' => $policy->id,
                'version_no' => 1,
                'effective_from' => '2026-01-01',
                'policy_json' => [
                    'basis' => 'IFRS 15',
                    'summary' => 'Revenue recognized when performance obligations are satisfied.',
                ],
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
        }
    }
}
