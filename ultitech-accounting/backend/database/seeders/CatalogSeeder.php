<?php

namespace Database\Seeders;

use App\Models\AccountType;
use App\Models\Currency;
use App\Models\Framework;
use App\Models\JournalSource;
use App\Models\Jurisdiction;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['TZS', 'Tanzanian Shilling', 'TSh', 2],
            ['USD', 'US Dollar', '$', 2],
            ['EUR', 'Euro', '€', 2],
            ['GBP', 'British Pound', '£', 2],
            ['KES', 'Kenyan Shilling', 'KSh', 2],
            ['UGX', 'Ugandan Shilling', 'USh', 0],
        ] as [$code, $name, $symbol, $decimals]) {
            Currency::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'symbol' => $symbol, 'decimal_places' => $decimals, 'is_active' => true]
            );
        }

        $ifrs = Framework::query()->updateOrCreate(
            ['code' => 'IFRS'],
            ['name' => 'Full IFRS', 'description' => 'International Financial Reporting Standards']
        );
        $sme = Framework::query()->updateOrCreate(
            ['code' => 'IFRS_SME'],
            ['name' => 'IFRS for SMEs', 'description' => 'IFRS for Small and Medium-sized Entities']
        );

        Jurisdiction::query()->updateOrCreate(
            ['code' => 'TZ'],
            ['name' => 'Tanzania', 'default_currency' => 'TZS', 'metadata_json' => ['vat' => true, 'wht' => true]]
        );
        Jurisdiction::query()->updateOrCreate(
            ['code' => 'INTL'],
            ['name' => 'International', 'default_currency' => 'USD', 'metadata_json' => []]
        );

        foreach ([
            ['asset', 'Assets', 'debit', 'balance_sheet'],
            ['liability', 'Liabilities', 'credit', 'balance_sheet'],
            ['equity', 'Equity', 'credit', 'balance_sheet'],
            ['revenue', 'Revenue', 'credit', 'profit_and_loss'],
            ['expense', 'Expenses', 'debit', 'profit_and_loss'],
        ] as [$code, $name, $balance, $fs]) {
            AccountType::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'normal_balance' => $balance, 'fs_classification' => $fs]
            );
        }

        foreach ([
            ['manual', 'Manual Journal'],
            ['opening', 'Opening Balance'],
            ['reversal', 'Reversal'],
            ['system', 'System'],
            ['sales_invoice', 'Sales Invoice'],
            ['customer_receipt', 'Customer Receipt'],
            ['purchase_bill', 'Purchase Bill'],
            ['supplier_payment', 'Supplier Payment'],
        ] as [$code, $name]) {
            JournalSource::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }

        foreach ([
            ['straight_line', 'Straight Line'],
            ['reducing_balance', 'Reducing Balance'],
        ] as [$code, $name]) {
            \App\Models\DepreciationMethod::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }

        $permissions = [
            ['companies.create', 'Create companies', 'companies'],
            ['companies.update', 'Update companies', 'companies'],
            ['companies.settings', 'Manage company settings', 'companies'],
            ['books.view', 'View books', 'books'],
            ['books.manage', 'Manage books', 'books'],
            ['periods.view', 'View periods', 'periods'],
            ['periods.manage', 'Manage periods', 'periods'],
            ['periods.lock', 'Lock periods', 'periods'],
            ['fx.view', 'View FX rates', 'fx'],
            ['fx.manage', 'Manage FX rates', 'fx'],
            ['coa.view', 'View chart of accounts', 'coa'],
            ['coa.manage', 'Manage chart of accounts', 'coa'],
            ['coa.openings', 'Manage openings', 'coa'],
            ['journals.view', 'View journals', 'journals'],
            ['journals.create', 'Create journals', 'journals'],
            ['journals.post', 'Post journals', 'journals'],
            ['journals.reverse', 'Reverse journals', 'journals'],
            ['reports.trial_balance', 'View trial balance', 'reports'],
            ['reports.financials', 'View financial statements', 'reports'],
            ['audit.view', 'View audit logs', 'audit'],
            ['ar.view', 'View receivables', 'ar'],
            ['ar.manage', 'Manage customers', 'ar'],
            ['ar.invoice', 'Post customer invoices', 'ar'],
            ['ar.receipt', 'Post customer receipts', 'ar'],
            ['ap.view', 'View payables', 'ap'],
            ['ap.manage', 'Manage suppliers', 'ap'],
            ['ap.bill', 'Post supplier bills', 'ap'],
            ['ap.payment', 'Post supplier payments', 'ap'],
            ['banking.view', 'View banking', 'banking'],
            ['banking.manage', 'Manage bank accounts', 'banking'],
            ['banking.reconcile', 'Bank reconciliation', 'banking'],
            ['assets.view', 'View fixed assets', 'assets'],
            ['assets.manage', 'Manage fixed assets', 'assets'],
            ['assets.depreciate', 'Run depreciation', 'assets'],
            ['inventory.view', 'View inventory accounting', 'inventory'],
            ['inventory.manage', 'Manage inventory movements', 'inventory'],
            ['tax.view', 'View tax control', 'tax'],
            ['tax.manage', 'Manage tax rates and returns', 'tax'],
            ['standards.view', 'View IFRS/SME standards', 'standards'],
            ['standards.manage', 'Manage accounting policies', 'standards'],
            ['cost_centers.view', 'View cost centers', 'cost'],
            ['cost_centers.manage', 'Manage cost centers', 'cost'],
            ['budgets.view', 'View budgets', 'budgets'],
            ['budgets.manage', 'Manage budgets', 'budgets'],
            ['fx.revalue', 'Run FX revaluation', 'fx'],
            ['consolidation.view', 'View consolidation', 'consolidation'],
            ['consolidation.manage', 'Manage consolidation', 'consolidation'],
            ['leases.view', 'View leases', 'leases'],
            ['leases.manage', 'Manage leases', 'leases'],
            ['impairment.manage', 'Record impairments', 'impairment'],
            ['approvals.view', 'View approval workflows', 'approvals'],
            ['approvals.manage', 'Manage approval workflows', 'approvals'],
            ['approvals.submit', 'Submit items for approval', 'approvals'],
            ['approvals.act', 'Approve or reject requests', 'approvals'],
            ['closing.view', 'View close checklists', 'closing'],
            ['closing.manage', 'Run period and year close', 'closing'],
            ['compliance.view', 'View compliance reports', 'compliance'],
            ['compliance.manage', 'Generate compliance reports', 'compliance'],
            ['erp.view', 'View ERP integration status', 'erp'],
            ['erp.sync', 'Run read-only ERP pull sync', 'erp'],
        ];

        foreach ($permissions as [$code, $name, $module]) {
            Permission::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'module' => $module]
            );
        }

        $admin = Role::query()->updateOrCreate(
            ['company_id' => null, 'code' => 'admin'],
            ['name' => 'Administrator', 'is_system' => true]
        );
        $accountant = Role::query()->updateOrCreate(
            ['company_id' => null, 'code' => 'accountant'],
            ['name' => 'Accountant', 'is_system' => true]
        );
        $viewer = Role::query()->updateOrCreate(
            ['company_id' => null, 'code' => 'viewer'],
            ['name' => 'Viewer', 'is_system' => true]
        );

        $admin->permissions()->sync(Permission::query()->pluck('id'));
        $accountant->permissions()->sync(
            Permission::query()->whereIn('code', [
                'books.view', 'periods.view', 'periods.manage', 'periods.lock',
                'fx.view', 'fx.manage', 'coa.view', 'coa.manage', 'coa.openings',
                'journals.view', 'journals.create', 'journals.post', 'journals.reverse',
                'reports.trial_balance', 'reports.financials',
                'ar.view', 'ar.manage', 'ar.invoice', 'ar.receipt',
                'ap.view', 'ap.manage', 'ap.bill', 'ap.payment',
                'banking.view', 'banking.manage', 'banking.reconcile',
                'assets.view', 'assets.manage', 'assets.depreciate',
                'inventory.view', 'inventory.manage',
                'tax.view', 'tax.manage', 'standards.view', 'standards.manage',
                'cost_centers.view', 'cost_centers.manage', 'budgets.view', 'budgets.manage',
                'fx.revalue', 'consolidation.view', 'consolidation.manage',
                'leases.view', 'leases.manage', 'impairment.manage',
                'approvals.view', 'approvals.manage', 'approvals.submit', 'approvals.act',
                'closing.view', 'closing.manage', 'compliance.view', 'compliance.manage', 'audit.view',
                'erp.view', 'erp.sync',
            ])->pluck('id')
        );
        $viewer->permissions()->sync(
            Permission::query()->whereIn('code', [
                'books.view', 'periods.view', 'fx.view', 'coa.view', 'journals.view',
                'reports.trial_balance', 'reports.financials',
                'ar.view', 'ap.view', 'banking.view', 'assets.view', 'inventory.view',
                'tax.view', 'standards.view',
                'cost_centers.view', 'budgets.view', 'consolidation.view', 'leases.view',
                'approvals.view', 'closing.view', 'compliance.view', 'audit.view', 'erp.view',
            ])->pluck('id')
        );

        $standards = [
            [$ifrs->id, 'IAS 1', 'Presentation of Financial Statements'],
            [$ifrs->id, 'IFRS 18', 'Presentation and Disclosure in Financial Statements'],
            [$ifrs->id, 'IAS 2', 'Inventories'],
            [$ifrs->id, 'IAS 7', 'Statement of Cash Flows'],
            [$ifrs->id, 'IAS 8', 'Accounting Policies, Changes in Accounting Estimates and Errors'],
            [$ifrs->id, 'IAS 12', 'Income Taxes'],
            [$ifrs->id, 'IAS 16', 'Property, Plant and Equipment'],
            [$ifrs->id, 'IAS 19', 'Employee Benefits'],
            [$ifrs->id, 'IAS 21', 'The Effects of Changes in Foreign Exchange Rates'],
            [$ifrs->id, 'IAS 36', 'Impairment of Assets'],
            [$ifrs->id, 'IAS 37', 'Provisions, Contingent Liabilities and Contingent Assets'],
            [$ifrs->id, 'IFRS 9', 'Financial Instruments'],
            [$ifrs->id, 'IFRS 15', 'Revenue from Contracts with Customers'],
            [$ifrs->id, 'IFRS 16', 'Leases'],
            [$ifrs->id, 'IFRS 13', 'Fair Value Measurement'],
            [$sme->id, 'SME Section 2', 'Concepts and Pervasive Principles'],
            [$sme->id, 'SME Section 13', 'Inventories'],
            [$sme->id, 'SME Section 23', 'Revenue'],
        ];

        foreach ($standards as [$frameworkId, $code, $title]) {
            $standardId = DB::table('acc_standards')->updateOrInsert(
                ['framework_id' => $frameworkId, 'code' => $code],
                ['title' => $title, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $ifrs18 = DB::table('acc_standards')->where('code', 'IFRS 18')->value('id');
        if ($ifrs18) {
            DB::table('acc_standard_versions')->updateOrInsert(
                ['standard_id' => $ifrs18, 'version_label' => '2024 issued / 2027 effective'],
                [
                    'effective_from' => '2027-01-01',
                    'notes' => 'IFRS 18 effective for annual periods beginning on or after 1 January 2027.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Tax types + effective-dated Tanzania rates (configuration, not hardcoded engine constants)
        DB::table('acc_tax_types')->updateOrInsert(
            ['code' => 'VAT'],
            ['name' => 'Value Added Tax', 'direction' => 'both', 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('acc_tax_types')->updateOrInsert(
            ['code' => 'WHT'],
            ['name' => 'Withholding Tax', 'direction' => 'input', 'created_at' => now(), 'updated_at' => now()]
        );

        $vatTypeId = DB::table('acc_tax_types')->where('code', 'VAT')->value('id');
        $whtTypeId = DB::table('acc_tax_types')->where('code', 'WHT')->value('id');
        $tzId = DB::table('acc_jurisdictions')->where('code', 'TZ')->value('id');

        if ($tzId && $vatTypeId) {
            DB::table('acc_tax_rates')->updateOrInsert(
                ['jurisdiction_id' => $tzId, 'code' => 'VAT-STD', 'effective_from' => '2015-07-01'],
                [
                    'tax_type_id' => $vatTypeId,
                    'name' => 'Tanzania Standard VAT 18%',
                    'rate_percent' => 18.0000,
                    'effective_to' => null,
                    'is_active' => true,
                    'authorized_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            DB::table('acc_tax_rates')->updateOrInsert(
                ['jurisdiction_id' => $tzId, 'code' => 'VAT-ZERO', 'effective_from' => '2015-07-01'],
                [
                    'tax_type_id' => $vatTypeId,
                    'name' => 'Tanzania Zero-rated VAT',
                    'rate_percent' => 0.0000,
                    'effective_to' => null,
                    'is_active' => true,
                    'authorized_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if ($tzId && $whtTypeId) {
            DB::table('acc_tax_rates')->updateOrInsert(
                ['jurisdiction_id' => $tzId, 'code' => 'WHT-SVC', 'effective_from' => '2020-07-01'],
                [
                    'tax_type_id' => $whtTypeId,
                    'name' => 'Tanzania WHT Services 5%',
                    'rate_percent' => 5.0000,
                    'effective_to' => null,
                    'is_active' => true,
                    'authorized_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        app(\App\Application\Services\AccountingPolicyService::class)->seedDefaultDisclosures($ifrs->id);
        app(\App\Application\Services\AccountingPolicyService::class)->seedDefaultDisclosures($sme->id);
    }
}
