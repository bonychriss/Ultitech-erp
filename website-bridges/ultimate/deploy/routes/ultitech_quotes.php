<?php

use App\Http\Controllers\Admin\QuoteAdminController;
use Illuminate\Support\Facades\Route;

Route::controller(QuoteAdminController::class)->group(function () {
    Route::get('/quotes', 'index')->name('quotes.index');
    Route::get('/quotes/{quote}/print', 'printView')->name('quotes.print');
    Route::get('/quotes/{quote}', 'show')->name('quotes.show');
    Route::post('/quotes/{quote}/status', 'updateStatus')->name('quotes.status');
    Route::post('/quotes/{quote}/retry', 'retry')->name('quotes.retry');
});
