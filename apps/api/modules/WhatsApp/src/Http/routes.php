<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\WhatsApp\Http\Controllers\MessageController;
use Modules\WhatsApp\Http\Controllers\NumberController;
use Modules\WhatsApp\Http\Controllers\TemplateController;

Route::middleware(['auth:sanctum', 'workspace'])->prefix('whatsapp')->name('whatsapp.')->group(function (): void {
    Route::get('numbers', [NumberController::class, 'index'])->name('numbers.index');
    Route::post('numbers', [NumberController::class, 'store'])->name('numbers.store');

    Route::prefix('numbers/{number}')->whereUuid('number')->group(function (): void {
        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::delete('templates/{name}', [TemplateController::class, 'destroy'])->name('templates.destroy');
        Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
        Route::post('messages', [MessageController::class, 'store'])->name('messages.store');
    });
});
