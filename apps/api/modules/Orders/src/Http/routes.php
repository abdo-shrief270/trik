<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\BoardController;
use Modules\Orders\Http\Controllers\OrderController;

Route::middleware(['auth:sanctum', 'verified.email', 'workspace', 'feature:orders'])->prefix('orders')->name('orders.')->group(function (): void {
    Route::get('/', [OrderController::class, 'index'])->middleware('can:orders.orders.view')->name('index');
    Route::get('board', [BoardController::class, 'index'])->middleware('can:orders.orders.view')->name('board');
    Route::post('/', [OrderController::class, 'store'])->middleware(['can:orders.orders.create', 'idempotency:required'])->name('store');

    Route::prefix('{order}')->whereUuid('order')->group(function (): void {
        Route::get('/', [OrderController::class, 'show'])->middleware('can:orders.orders.view')->name('show');
        Route::patch('/', [OrderController::class, 'update'])->middleware('can:orders.orders.update')->name('update');
        Route::post('confirm', [OrderController::class, 'confirm'])->middleware('can:orders.orders.confirm')->name('confirm');
        Route::post('cancel', [OrderController::class, 'cancel'])->middleware('can:orders.orders.confirm')->name('cancel');
        Route::get('timeline', [OrderController::class, 'timeline'])->middleware('can:orders.orders.view')->name('timeline');
    });
});
