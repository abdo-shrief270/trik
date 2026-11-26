<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Confirmation\Http\Controllers\BulkController;
use Modules\Confirmation\Http\Controllers\ConfirmationController;

Route::middleware(['auth:sanctum', 'verified.email', 'workspace', 'feature:orders'])->group(function (): void {
    Route::get('confirmation/settings', [ConfirmationController::class, 'settings'])->middleware('can:confirmation.settings.view')->name('confirmation.settings');
    Route::put('confirmation/settings', [ConfirmationController::class, 'updateSettings'])->middleware('can:confirmation.settings.manage')->name('confirmation.settings.update');
    Route::post('confirmation/bulk', [BulkController::class, 'store'])->middleware(['can:orders.orders.confirm', 'idempotency:required'])->name('confirmation.bulk');
    Route::get('orders/{order}/confirmation', [ConfirmationController::class, 'forOrder'])->whereUuid('order')->middleware('can:orders.orders.view')->name('orders.confirmation');
});
