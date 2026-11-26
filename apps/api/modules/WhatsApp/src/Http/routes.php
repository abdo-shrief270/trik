<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\WhatsApp\Http\Controllers\MessageController;
use Modules\WhatsApp\Http\Controllers\NumberController;
use Modules\WhatsApp\Http\Controllers\TemplateController;

Route::middleware(['auth:sanctum', 'verified.email', 'workspace'])->prefix('whatsapp')->name('whatsapp.')->group(function (): void {
    Route::get('numbers', [NumberController::class, 'index'])->middleware('can:whatsapp.numbers.view')->name('numbers.index');
    Route::post('numbers', [NumberController::class, 'store'])->middleware('can:whatsapp.numbers.manage')->name('numbers.store');

    Route::prefix('numbers/{number}')->whereUuid('number')->group(function (): void {
        Route::get('templates', [TemplateController::class, 'index'])->middleware('can:whatsapp.templates.view')->name('templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->middleware('can:whatsapp.templates.manage')->name('templates.store');
        Route::delete('templates/{name}', [TemplateController::class, 'destroy'])->middleware('can:whatsapp.templates.manage')->name('templates.destroy');
        Route::get('messages', [MessageController::class, 'index'])->middleware('can:whatsapp.messages.view')->name('messages.index');
        Route::post('messages', [MessageController::class, 'store'])->middleware(['can:whatsapp.messages.send', 'idempotency:required'])->name('messages.store');
    });
});
