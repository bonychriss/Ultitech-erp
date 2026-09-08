<?php

use App\Http\Controllers\SalesDashboardApiController;
use App\Http\Controllers\SalesDeskApiController;
use App\Http\Middleware\AttachErpContext;
use Illuminate\Support\Facades\Route;

Route::middleware([AttachErpContext::class])->group(function () {
    Route::get('/api/dashboard', [SalesDashboardApiController::class, 'init'])->name('sales.api.dashboard');
    Route::get('/api/dashboard/init', [SalesDashboardApiController::class, 'init'])->name('sales.api.dashboard.init');

    Route::match(['get', 'post'], '/api/desk/{desk}', [SalesDeskApiController::class, 'desk'])
        ->where('desk', 'my-sales|invoices|orders|customers|catalogue|pricelist|settings|quotations|create-init|create-quote|create-invoice|order-view-init|order-view-status|invoice-view-init|quote-edit-init|quote-edit-save|convert-invoice|exchange-rate')
        ->name('sales.api.desk');
});
