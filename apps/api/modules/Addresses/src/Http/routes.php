<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Addresses\Http\Controllers\GovernorateController;

Route::middleware(['auth:sanctum'])->prefix('addresses')->name('addresses.')->group(function (): void {
    Route::get('governorates', [GovernorateController::class, 'index'])->name('governorates');
});
