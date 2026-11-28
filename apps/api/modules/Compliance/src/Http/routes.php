<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Compliance\Http\Controllers\PrivacyRequestController;

Route::middleware(['auth:sanctum', 'verified.email', 'workspace'])->prefix('compliance')->name('compliance.')->group(function (): void {
    Route::get('requests', [PrivacyRequestController::class, 'index'])->middleware('can:compliance.requests.view')->name('requests.index');
    Route::get('requests/{privacyRequest}/export', [PrivacyRequestController::class, 'export'])
        ->whereUuid('privacyRequest')->middleware(['can:compliance.requests.export', 'throttle:30,1'])->name('requests.export');
});
