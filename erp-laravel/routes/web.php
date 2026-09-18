<?php

use App\Domains\Sales\DeskShell;
use App\Domains\Sales\LegacyApiBridge;
use App\Domains\Stock\DeskShell as StockDeskShell;
use App\Domains\Payroll\DeskShell as PayrollDeskShell;
use App\Domains\Letter\DeskShell as LetterDeskShell;
use App\Domains\Admin\DeskShell as AdminDeskShell;
use App\Http\Controllers\HomePageController;
use App\Http\Controllers\TrialPageController;
use App\Http\Controllers\SalesDashboardApiController;
use App\Http\Controllers\SalesDashboardPageController;
use App\Http\Controllers\SalesDeskApiController;
use App\Http\Controllers\SalesDeskPageController;
use App\Http\Controllers\StockDeskPageController;
use App\Http\Controllers\StockPageController;
use App\Http\Controllers\PayrollDeskPageController;
use App\Http\Controllers\PayrollPageController;
use App\Http\Controllers\LetterDeskPageController;
use App\Http\Controllers\LetterPageController;
use App\Http\Controllers\AdminDeskPageController;
use App\Http\Controllers\SuggestApiController;
use App\Http\Controllers\SuggestPageController;
use App\Http\Controllers\CashBookApiController;
use App\Http\Controllers\CashBookDeskPageController;
use App\Http\Controllers\CashBookPageController;
use App\Http\Controllers\AccountingPageController;
use App\Http\Controllers\RevenueDeskPageController;
use App\Http\Controllers\RevenuePageController;
use App\Http\Controllers\AttendancePageController;
use App\Domains\CashBook\DeskShell as CashBookDeskShell;
use App\Domains\Revenue\DeskShell as RevenueDeskShell;
use App\Http\Middleware\AttachErpContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| erp-laravel routes — Home + Trial (public) + Sales + Suggest + Stock + Payroll
|--------------------------------------------------------------------------
*/

// Public marketing homepage (no ERP session required)
Route::get('/home', [HomePageController::class, 'show'])->name('home.page');
Route::get('/pricing', [HomePageController::class, 'show'])->name('home.pricing');

// Public free-trial signup (POST stays on free-trial.php; GET shell via Laravel)
Route::get('/free-trial', [TrialPageController::class, 'show'])->name('trial.page');

Route::middleware([AttachErpContext::class])->group(function () {
    // Sales
    Route::get('/sales/dashboard', [SalesDashboardPageController::class, 'show'])->name('sales.page.dashboard');

    Route::get('/sales/desk/{desk}', [SalesDeskPageController::class, 'show'])
        ->where('desk', DeskShell::deskRegex())
        ->name('sales.page.desk');

    Route::get('/api/dashboard', [SalesDashboardApiController::class, 'init'])->name('sales.api.dashboard');
    Route::get('/api/dashboard/init', [SalesDashboardApiController::class, 'init'])->name('sales.api.dashboard.init');

    Route::match(['get', 'post'], '/api/desk/{desk}', [SalesDeskApiController::class, 'desk'])
        ->where('desk', LegacyApiBridge::deskRegex())
        ->name('sales.api.desk');

    // Suggest
    Route::get('/suggest', [SuggestPageController::class, 'show'])->name('suggest.page');
    Route::get('/api/suggestions', [SuggestApiController::class, 'index'])->name('suggest.api.index');
    Route::post('/api/suggestions', [SuggestApiController::class, 'store'])->name('suggest.api.store');

    // Payroll
    Route::get('/payroll', [PayrollPageController::class, 'show'])->name('payroll.page');
    Route::match(['get', 'post'], '/payroll/desk/{desk}', [PayrollDeskPageController::class, 'show'])
        ->where('desk', PayrollDeskShell::deskRegex())
        ->name('payroll.page.desk');

    // Letter
    Route::get('/letter', [LetterPageController::class, 'show'])->name('letter.page');
    Route::match(['get', 'post'], '/letter/desk/{desk}', [LetterDeskPageController::class, 'show'])
        ->where('desk', LetterDeskShell::deskRegex())
        ->name('letter.page.desk');

    // Admin
    Route::match(['get', 'post'], '/admin/desk/{desk}', [AdminDeskPageController::class, 'show'])
        ->where('desk', AdminDeskShell::deskRegex())
        ->name('admin.page.desk');

    // Stock (full module via legacy desk bridge)
    Route::match(['get', 'post'], '/stock', [StockPageController::class, 'show'])->name('stock.page');
    Route::match(['get', 'post'], '/stock/desk/{desk}', [StockDeskPageController::class, 'show'])
        ->where('desk', StockDeskShell::deskRegex())
        ->name('stock.page.desk');

    // Cash Book (replaces Petty Cash voucher workflow)
    Route::get('/cashbook', [CashBookPageController::class, 'show'])->name('cashbook.page');
    Route::match(['get', 'post'], '/cashbook/desk/{desk}', [CashBookDeskPageController::class, 'show'])
        ->where('desk', CashBookDeskShell::deskRegex())
        ->name('cashbook.page.desk');
    Route::match(['get', 'post', 'put', 'delete'], '/api/cashbook/{resource}/{id?}', [CashBookApiController::class, 'handle'])
        ->where('resource', 'init|books|entries|categories|reports|import|delete-requests')
        ->where('id', '[0-9]+')
        ->name('cashbook.api');

    // Accounting hub
    Route::get('/accounting', [AccountingPageController::class, 'show'])->name('accounting.page');

    // Revenue (existing React UI via Laravel shell; APIs stay under modules/revenue/api)
    Route::get('/revenue', [RevenuePageController::class, 'show'])->name('revenue.page');
    Route::match(['get', 'post'], '/revenue/desk/{desk}', [RevenueDeskPageController::class, 'show'])
        ->where('desk', RevenueDeskShell::deskRegex())
        ->name('revenue.page.desk');

    // Attendance (React clock desk + analytics; actions stay under attendance/api)
    Route::get('/attendance', [AttendancePageController::class, 'show'])->name('attendance.page');
    Route::get('/attendance/analytics', [AttendancePageController::class, 'analytics'])->name('attendance.page.analytics');
    Route::get('/attendance/overtime', [AttendancePageController::class, 'overtime'])->name('attendance.page.overtime');
});
