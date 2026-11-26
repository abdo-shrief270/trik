<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\WhatsApp\Http\Controllers\WebhookController;

Route::prefix('whatsapp')->name('whatsapp.webhooks.')->group(function (): void {
    Route::get('meta_cloud', [WebhookController::class, 'metaVerify'])->name('meta.verify');
    Route::post('meta_cloud', [WebhookController::class, 'meta'])->name('meta');
    Route::post('{provider}/{number}', [WebhookController::class, 'perNumber'])
        ->whereIn('provider', ['360dialog', 'fake'])
        ->whereUuid('number')
        ->name('number');
});
