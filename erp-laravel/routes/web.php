<?php

use App\Domains\Sales\DeskShell;
use App\Domains\Sales\LegacyApiBridge;
use App\Domains\Stock\DeskShell as StockDeskShell;
use App\Http\Controllers\HomePageController;
use App\Http\Controllers\TrialPageController;
use App\Http\Controllers\SalesDashboardApiController;
use App\Http\Controllers\SalesDashboardPageController;
use App\Http\Controllers\SalesDeskApiController;
use App\Http\Controllers\SalesDeskPageController;
use App\Http\Controllers\StockDeskPageController;
use App\Http\Controllers\StockPageController;
use App\Http\Controllers\PayrollPageController;
use App\Http\Controllers\SuggestApiController;
use App\Http\Controllers\SuggestPageController;
use App\Http\Middleware\AttachErpContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| erp-laravel routes — Home + Trial (public) + Sales + Suggest + Stock + Payroll
|--------------------------------------------------------------------------
*/

// Public marketing homepage (no ERP session required)
Route::get('/home', [HomePageController::class, 'show'])->name('home.page');

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

    // Stock (full module via legacy desk bridge)
    Route::match(['get', 'post'], '/stock', [StockPageController::class, 'show'])->name('stock.page');
    Route::match(['get', 'post'], '/stock/desk/{desk}', [StockDeskPageController::class, 'show'])
        ->where('desk', StockDeskShell::deskRegex())
        ->name('stock.page.desk');
});
