<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\PilotController;

Route::middleware(['auth:sanctum', 'verified.email', 'workspace'])->prefix('analytics')->name('analytics.')->group(function (): void {
    Route::get('pilot', [PilotController::class, 'show'])->middleware('can:analytics.reports.view')->name('pilot.show');
    Route::put('pilot', [PilotController::class, 'update'])->middleware('can:analytics.reports.manage')->name('pilot.update');
    Route::get('pilot/weekly.csv', [PilotController::class, 'weekly'])->middleware(['can:analytics.reports.view', 'throttle:20,1'])->name('pilot.weekly');
});
