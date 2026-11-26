<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Inbox\Http\Controllers\ConversationController;
use Modules\Inbox\Http\Controllers\ConversationMessageController;
use Modules\Inbox\Http\Controllers\ConversationOrderController;

Route::middleware(['auth:sanctum', 'verified.email', 'workspace', 'feature:inbox'])->prefix('inbox')->name('inbox.')->group(function (): void {
    Route::get('conversations', [ConversationController::class, 'index'])->middleware('can:inbox.conversations.view')->name('conversations.index');

    Route::prefix('conversations/{conversation}')->whereUuid('conversation')->name('conversations.')->group(function (): void {
        Route::get('/', [ConversationController::class, 'show'])->middleware('can:inbox.conversations.view')->name('show');
        Route::patch('/', [ConversationController::class, 'update'])->middleware('can:inbox.conversations.reply')->name('update');
        Route::post('read', [ConversationController::class, 'read'])->middleware('can:inbox.conversations.view')->name('read');
        Route::get('messages', [ConversationMessageController::class, 'index'])->middleware('can:inbox.conversations.view')->name('messages.index');
        Route::get('orders', [ConversationOrderController::class, 'index'])->middleware(['can:orders.orders.view', 'feature:orders'])->name('orders.index');
        Route::post('messages', [ConversationMessageController::class, 'store'])->middleware(['can:inbox.conversations.reply', 'idempotency:required'])->name('messages.store');
    });
});
