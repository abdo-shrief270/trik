<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Commerce\Http\Controllers\ShopifyController;

Route::prefix('shopify')->name('commerce.shopify.')->group(function (): void {
    Route::get('app', [ShopifyController::class, 'app'])->middleware('throttle:60,1')->name('app');
    Route::get('oauth/callback', [ShopifyController::class, 'callback'])->middleware('throttle:30,1')->name('callback');
    Route::post('/', [ShopifyController::class, 'webhook'])->name('webhook');
    Route::post('compliance', [ShopifyController::class, 'compliance'])->name('compliance');
});
