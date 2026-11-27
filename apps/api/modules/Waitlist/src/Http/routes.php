<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Waitlist\Http\Controllers\WaitlistController;

// Public: no sign-in. The signed links carry their own proof (checked in the controller, so a browser
// with an expired link is sent back to the form instead of seeing an error).
Route::prefix('waitlist')->name('waitlist.')->middleware('feature:waitlist')->group(function (): void {
    Route::post('/', [WaitlistController::class, 'join'])->middleware('throttle:waitlist')->name('join');

    Route::middleware('throttle:30,1')->whereUuid('entry')->group(function (): void {
        Route::get('{entry}/confirm', [WaitlistController::class, 'confirm'])->name('confirm');
        Route::match(['GET', 'POST'], '{entry}/unsubscribe', [WaitlistController::class, 'unsubscribe'])->name('unsubscribe');
    });
});
