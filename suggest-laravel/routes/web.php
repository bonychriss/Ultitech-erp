<?php

use App\Http\Controllers\SuggestApiController;
use App\Http\Controllers\SuggestController;
use App\Http\Middleware\AttachErpContext;
use Illuminate\Support\Facades\Route;

Route::middleware([AttachErpContext::class])->group(function () {
    // Legacy Blade (kept for comparison / fallback)
    Route::match(['get', 'post'], '/suggest', function (\Illuminate\Http\Request $request) {
        $controller = app(SuggestController::class);
        if ($request->isMethod('post')) {
            return $controller->store($request);
        }
        return $controller->index($request);
    })->name('suggest.index');

    Route::get('/api/suggestions', [SuggestApiController::class, 'index'])->name('suggest.api.index');
    Route::post('/api/suggestions', [SuggestApiController::class, 'store'])->name('suggest.api.store');
});
