<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Commerce\Http\Controllers\StoreController;

Route::middleware(['auth:sanctum', 'verified.email', 'workspace', 'feature:commerce'])->prefix('commerce')->name('commerce.')->group(function (): void {
    Route::get('stores', [StoreController::class, 'index'])->middleware('can:commerce.stores.view')->name('stores.index');
    Route::post('shopify/install', [StoreController::class, 'installShopify'])->middleware(['can:commerce.stores.manage', 'throttle:10,1'])->name('shopify.install');
    Route::post('easyorders/install', [StoreController::class, 'installEasyOrders'])->middleware(['can:commerce.stores.manage', 'throttle:10,1'])->name('easyorders.install');

    Route::prefix('stores/{store}')->whereUuid('store')->name('stores.')->group(function (): void {
        Route::patch('/', [StoreController::class, 'update'])->middleware('can:commerce.stores.manage')->name('update');
        Route::delete('/', [StoreController::class, 'destroy'])->middleware('can:commerce.stores.manage')->name('destroy');
        Route::post('backfill', [StoreController::class, 'backfill'])->middleware(['can:commerce.stores.manage', 'idempotency:required'])->name('backfill');
    });
});
