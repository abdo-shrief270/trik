<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Risk\Http\Controllers\RiskController;

Route::middleware(['auth:sanctum', 'verified.email', 'workspace', 'feature:risk'])->group(function (): void {
    Route::get('orders/{order}/risk', [RiskController::class, 'show'])->whereUuid('order')->middleware('can:orders.orders.view')->name('risk.order');
    Route::get('risk/settings', [RiskController::class, 'settings'])->middleware('can:risk.settings.view')->name('risk.settings.show');
    Route::put('risk/settings', [RiskController::class, 'updateSettings'])->middleware('can:risk.settings.manage')->name('risk.settings.update');
});
