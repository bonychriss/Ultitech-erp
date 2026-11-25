<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AdvancedAccountingController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BankingController;
use App\Http\Controllers\Api\V1\BookController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\ErpBridgeController;
use App\Http\Controllers\Api\V1\ErpIntegrationController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\FiscalPeriodController;
use App\Http\Controllers\Api\V1\FixedAssetController;
use App\Http\Controllers\Api\V1\GovernanceController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\StandardsController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TaxController;
use App\Http\Middleware\EnsureCompanyAccess;
use App\Http\Middleware\EnsurePermission;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/auth/erp-bridge', [ErpBridgeController::class, 'exchange'])->middleware('throttle:20,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/currencies', [CurrencyController::class, 'currencies']);
        Route::get('/frameworks', [CurrencyController::class, 'frameworks']);
        Route::get('/jurisdictions', [CurrencyController::class, 'jurisdictions']);

        Route::middleware([EnsureCompanyAccess::class])->group(function () {
            Route::get('/books', [BookController::class, 'index'])->middleware(EnsurePermission::class.':books.view');
            Route::post('/books', [BookController::class, 'store'])->middleware(EnsurePermission::class.':books.manage');

            Route::get('/fiscal-years', [FiscalPeriodController::class, 'years'])->middleware(EnsurePermission::class.':periods.view');
            Route::post('/fiscal-years', [FiscalPeriodController::class, 'storeYear'])->middleware(EnsurePermission::class.':periods.manage');
            Route::post('/period-locks', [FiscalPeriodController::class, 'lock'])->middleware(EnsurePermission::class.':periods.lock');

            Route::get('/exchange-rates', [CurrencyController::class, 'rates'])->middleware(EnsurePermission::class.':fx.view');
            Route::post('/exchange-rates', [CurrencyController::class, 'storeRate'])->middleware(EnsurePermission::class.':fx.manage');

            Route::get('/accounts', [AccountController::class, 'index'])->middleware(EnsurePermission::class.':coa.view');
            Route::post('/accounts', [AccountController::class, 'store'])->middleware(EnsurePermission::class.':coa.manage');
            Route::patch('/accounts/{id}', [AccountController::class, 'update'])->middleware(EnsurePermission::class.':coa.manage');
            Route::delete('/accounts/{id}', [AccountController::class, 'destroy'])->middleware(EnsurePermission::class.':coa.manage');

            Route::get('/journals', [JournalController::class, 'index'])->middleware(EnsurePermission::class.':journals.view');
            Route::post('/journals', [JournalController::class, 'store'])->middleware(EnsurePermission::class.':journals.create');
            Route::get('/journals/{id}', [JournalController::class, 'show'])->middleware(EnsurePermission::class.':journals.view');
            Route::patch('/journals/{id}', [JournalController::class, 'update'])->middleware(EnsurePermission::class.':journals.create');
            Route::post('/journals/{id}/post', [JournalController::class, 'post'])->middleware(EnsurePermission::class.':journals.post');
            Route::post('/journals/{id}/reverse', [JournalController::class, 'reverse'])->middleware(EnsurePermission::class.':journals.reverse');

            Route::get('/trial-balance', [ReportController::class, 'trialBalance'])->middleware(EnsurePermission::class.':reports.trial_balance');
            Route::get('/reports/profit-and-loss', [ReportController::class, 'profitAndLoss'])->middleware(EnsurePermission::class.':reports.financials');
            Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware(EnsurePermission::class.':reports.financials');
            Route::get('/reports/cash-flow', [ReportController::class, 'cashFlow'])->middleware(EnsurePermission::class.':reports.financials');
            Route::get('/reports/equity', [ReportController::class, 'equity'])->middleware(EnsurePermission::class.':reports.financials');
            Route::get('/reports/general-ledger', [ReportController::class, 'generalLedger'])->middleware(EnsurePermission::class.':reports.financials');

            Route::get('/customers', [CustomerController::class, 'index'])->middleware(EnsurePermission::class.':ar.view');
            Route::post('/customers', [CustomerController::class, 'store'])->middleware(EnsurePermission::class.':ar.manage');
            Route::get('/customer-invoices', [CustomerController::class, 'invoices'])->middleware(EnsurePermission::class.':ar.view');
            Route::post('/customer-invoices', [CustomerController::class, 'storeInvoice'])->middleware(EnsurePermission::class.':ar.invoice');
            Route::get('/customer-receipts', [CustomerController::class, 'receipts'])->middleware(EnsurePermission::class.':ar.view');
            Route::post('/customer-receipts', [CustomerController::class, 'storeReceipt'])->middleware(EnsurePermission::class.':ar.receipt');
            Route::post('/manual-revenues', [CustomerController::class, 'storeManualRevenue'])->middleware(EnsurePermission::class.':ar.receipt');

            Route::get('/suppliers', [SupplierController::class, 'index'])->middleware(EnsurePermission::class.':ap.view');
            Route::post('/suppliers', [SupplierController::class, 'store'])->middleware(EnsurePermission::class.':ap.manage');
            Route::get('/supplier-bills', [SupplierController::class, 'bills'])->middleware(EnsurePermission::class.':ap.view');
            Route::post('/supplier-bills', [SupplierController::class, 'storeBill'])->middleware(EnsurePermission::class.':ap.bill');
            Route::get('/purchase-vouchers', [SupplierController::class, 'purchaseVouchers'])->middleware(EnsurePermission::class.':ap.view');
            Route::get('/expense-vouchers', [SupplierController::class, 'expenseVouchers'])->middleware(EnsurePermission::class.':ap.view');
            Route::get('/supplier-payments', [SupplierController::class, 'payments'])->middleware(EnsurePermission::class.':ap.view');
            Route::post('/supplier-payments', [SupplierController::class, 'storePayment'])->middleware(EnsurePermission::class.':ap.payment');

            Route::get('/bank-accounts', [BankingController::class, 'bankAccounts'])->middleware(EnsurePermission::class.':banking.view');
            Route::post('/bank-accounts', [BankingController::class, 'storeBankAccount'])->middleware(EnsurePermission::class.':banking.manage');
            Route::patch('/bank-accounts/{id}', [BankingController::class, 'updateBankAccount'])->middleware(EnsurePermission::class.':banking.manage');
            Route::get('/cash-accounts', [BankingController::class, 'cashAccounts'])->middleware(EnsurePermission::class.':banking.view');
            Route::post('/cash-accounts', [BankingController::class, 'storeCashAccount'])->middleware(EnsurePermission::class.':banking.manage');
            Route::patch('/cash-accounts/{id}', [BankingController::class, 'updateCashAccount'])->middleware(EnsurePermission::class.':banking.manage');
            Route::get('/bank-transactions', [BankingController::class, 'transactions'])->middleware(EnsurePermission::class.':banking.view');

            Route::get('/bank-statements', [ReconciliationController::class, 'statements'])->middleware(EnsurePermission::class.':banking.reconcile');
            Route::post('/bank-statements', [ReconciliationController::class, 'storeStatement'])->middleware(EnsurePermission::class.':banking.reconcile');
            Route::post('/bank-reconciliations', [ReconciliationController::class, 'start'])->middleware(EnsurePermission::class.':banking.reconcile');
            Route::post('/bank-reconciliations/{id}/match', [ReconciliationController::class, 'match'])->middleware(EnsurePermission::class.':banking.reconcile');
            Route::post('/bank-reconciliations/{id}/complete', [ReconciliationController::class, 'complete'])->middleware(EnsurePermission::class.':banking.reconcile');
            Route::get('/bank-unmatched', [ReconciliationController::class, 'unmatched'])->middleware(EnsurePermission::class.':banking.reconcile');

            Route::get('/depreciation-methods', [FixedAssetController::class, 'methods'])->middleware(EnsurePermission::class.':assets.view');
            Route::get('/asset-categories', [FixedAssetController::class, 'categories'])->middleware(EnsurePermission::class.':assets.view');
            Route::post('/asset-categories', [FixedAssetController::class, 'storeCategory'])->middleware(EnsurePermission::class.':assets.manage');
            Route::get('/fixed-assets', [FixedAssetController::class, 'index'])->middleware(EnsurePermission::class.':assets.view');
            Route::post('/fixed-assets', [FixedAssetController::class, 'acquire'])->middleware(EnsurePermission::class.':assets.manage');
            Route::post('/depreciation-runs', [FixedAssetController::class, 'depreciate'])->middleware(EnsurePermission::class.':assets.depreciate');
            Route::get('/depreciation-runs', [FixedAssetController::class, 'runs'])->middleware(EnsurePermission::class.':assets.view');

            Route::get('/inventory-items', [InventoryController::class, 'items'])->middleware(EnsurePermission::class.':inventory.view');
            Route::post('/inventory-items', [InventoryController::class, 'storeItem'])->middleware(EnsurePermission::class.':inventory.manage');
            Route::post('/inventory-items/{id}/receive', [InventoryController::class, 'receive'])->middleware(EnsurePermission::class.':inventory.manage');
            Route::post('/inventory-items/{id}/issue', [InventoryController::class, 'issue'])->middleware(EnsurePermission::class.':inventory.manage');
            Route::get('/inventory-movements', [InventoryController::class, 'movements'])->middleware(EnsurePermission::class.':inventory.view');
            Route::get('/inventory-valuation', [InventoryController::class, 'valuation'])->middleware(EnsurePermission::class.':inventory.view');

            Route::get('/tax-types', [TaxController::class, 'types'])->middleware(EnsurePermission::class.':tax.view');
            Route::get('/tax-rates', [TaxController::class, 'rates'])->middleware(EnsurePermission::class.':tax.view');
            Route::get('/tax-codes', [TaxController::class, 'codes'])->middleware(EnsurePermission::class.':tax.view');
            Route::post('/tax/calculate', [TaxController::class, 'calculate'])->middleware(EnsurePermission::class.':tax.view');
            Route::get('/tax-transactions', [TaxController::class, 'transactions'])->middleware(EnsurePermission::class.':tax.view');
            Route::get('/tax-returns', [TaxController::class, 'returns'])->middleware(EnsurePermission::class.':tax.view');
            Route::post('/tax-returns', [TaxController::class, 'prepareReturn'])->middleware(EnsurePermission::class.':tax.manage');
            Route::post('/tax-returns/{id}/reconcile', [TaxController::class, 'reconcile'])->middleware(EnsurePermission::class.':tax.manage');
            Route::post('/tax-returns/{id}/file', [TaxController::class, 'file'])->middleware(EnsurePermission::class.':tax.manage');
            Route::post('/tax-returns/{id}/pay', [TaxController::class, 'pay'])->middleware(EnsurePermission::class.':tax.manage');
            Route::post('/tax/adjustments', [TaxController::class, 'adjust'])->middleware(EnsurePermission::class.':tax.manage');
            Route::get('/tax-reconciliations', [TaxController::class, 'reconciliations'])->middleware(EnsurePermission::class.':tax.view');
            Route::post('/tax/sync-register', [TaxController::class, 'syncRegister'])->middleware(EnsurePermission::class.':tax.manage');

            Route::get('/standards/frameworks', [StandardsController::class, 'frameworks'])->middleware(EnsurePermission::class.':standards.view');
            Route::get('/standards/disclosures', [StandardsController::class, 'disclosures'])->middleware(EnsurePermission::class.':standards.view');
            Route::get('/standards/policies', [StandardsController::class, 'policies'])->middleware(EnsurePermission::class.':standards.view');
            Route::post('/standards/policies', [StandardsController::class, 'storePolicy'])->middleware(EnsurePermission::class.':standards.manage');
            Route::post('/standards/policy-versions/{id}/approve', [StandardsController::class, 'approveVersion'])->middleware(EnsurePermission::class.':standards.manage');
            Route::get('/standards/checklist', [StandardsController::class, 'checklist'])->middleware(EnsurePermission::class.':standards.view');

            Route::get('/cost-centers', [AdvancedAccountingController::class, 'costCenters'])->middleware(EnsurePermission::class.':cost_centers.view');
            Route::post('/cost-centers', [AdvancedAccountingController::class, 'storeCostCenter'])->middleware(EnsurePermission::class.':cost_centers.manage');
            Route::get('/cost-centers/profit-and-loss', [AdvancedAccountingController::class, 'costCenterPl'])->middleware(EnsurePermission::class.':cost_centers.view');

            Route::get('/budgets', [AdvancedAccountingController::class, 'budgets'])->middleware(EnsurePermission::class.':budgets.view');
            Route::post('/budgets', [AdvancedAccountingController::class, 'storeBudget'])->middleware(EnsurePermission::class.':budgets.manage');
            Route::post('/budgets/{id}/approve', [AdvancedAccountingController::class, 'approveBudget'])->middleware(EnsurePermission::class.':budgets.manage');
            Route::get('/budgets/{id}/versus-actual', [AdvancedAccountingController::class, 'budgetVersusActual'])->middleware(EnsurePermission::class.':budgets.view');

            Route::get('/fx-revaluations', [AdvancedAccountingController::class, 'fxRevaluations'])->middleware(EnsurePermission::class.':fx.revalue');
            Route::post('/fx-revaluations', [AdvancedAccountingController::class, 'runFxRevaluation'])->middleware(EnsurePermission::class.':fx.revalue');

            Route::get('/consolidation-groups', [AdvancedAccountingController::class, 'consolidationGroups'])->middleware(EnsurePermission::class.':consolidation.view');
            Route::post('/consolidation-groups', [AdvancedAccountingController::class, 'storeConsolidationGroup'])->middleware(EnsurePermission::class.':consolidation.manage');
            Route::post('/consolidation-groups/{id}/run', [AdvancedAccountingController::class, 'runConsolidation'])->middleware(EnsurePermission::class.':consolidation.manage');

            Route::get('/leases', [AdvancedAccountingController::class, 'leases'])->middleware(EnsurePermission::class.':leases.view');
            Route::post('/leases', [AdvancedAccountingController::class, 'storeLease'])->middleware(EnsurePermission::class.':leases.manage');
            Route::post('/leases/{id}/post-period', [AdvancedAccountingController::class, 'postLeasePeriod'])->middleware(EnsurePermission::class.':leases.manage');

            Route::get('/impairments', [AdvancedAccountingController::class, 'impairments'])->middleware(EnsurePermission::class.':impairment.manage');
            Route::post('/impairments', [AdvancedAccountingController::class, 'impairAsset'])->middleware(EnsurePermission::class.':impairment.manage');

            Route::get('/approval-workflows', [GovernanceController::class, 'workflows'])->middleware(EnsurePermission::class.':approvals.view');
            Route::post('/approval-workflows', [GovernanceController::class, 'storeWorkflow'])->middleware(EnsurePermission::class.':approvals.manage');
            Route::get('/approval-requests', [GovernanceController::class, 'approvalRequests'])->middleware(EnsurePermission::class.':approvals.view');
            Route::post('/journals/{id}/submit-approval', [GovernanceController::class, 'submitJournalApproval'])->middleware(EnsurePermission::class.':approvals.submit');
            Route::post('/approval-requests/{id}/act', [GovernanceController::class, 'actOnApproval'])->middleware(EnsurePermission::class.':approvals.act');

            Route::post('/period-close/checklist', [GovernanceController::class, 'monthEndChecklist'])->middleware(EnsurePermission::class.':closing.view');
            Route::post('/period-close', [GovernanceController::class, 'closePeriod'])->middleware(EnsurePermission::class.':closing.manage');
            Route::get('/period-closes', [GovernanceController::class, 'periodCloses'])->middleware(EnsurePermission::class.':closing.view');
            Route::post('/year-close/checklist', [GovernanceController::class, 'yearEndChecklist'])->middleware(EnsurePermission::class.':closing.view');
            Route::post('/year-close', [GovernanceController::class, 'closeYear'])->middleware(EnsurePermission::class.':closing.manage');
            Route::get('/year-closes', [GovernanceController::class, 'yearCloses'])->middleware(EnsurePermission::class.':closing.view');

            Route::get('/audit-logs', [GovernanceController::class, 'auditLogs'])->middleware(EnsurePermission::class.':audit.view');
            Route::get('/compliance-reports', [GovernanceController::class, 'complianceReports'])->middleware(EnsurePermission::class.':compliance.view');
            Route::post('/compliance-reports', [GovernanceController::class, 'generateCompliance'])->middleware(EnsurePermission::class.':compliance.manage');

            Route::get('/erp/status', [ErpIntegrationController::class, 'status'])->middleware(EnsurePermission::class.':erp.view');
            Route::post('/erp/test', [ErpIntegrationController::class, 'test'])->middleware(EnsurePermission::class.':erp.view');
            Route::post('/erp/pull', [ErpIntegrationController::class, 'pull'])->middleware(EnsurePermission::class.':erp.sync');
            Route::get('/erp/runs', [ErpIntegrationController::class, 'runs'])->middleware(EnsurePermission::class.':erp.view');
        });
    });
});

