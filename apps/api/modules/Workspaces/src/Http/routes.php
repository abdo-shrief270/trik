<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Workspaces\Http\Controllers\WorkspaceController;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    Route::middleware('workspace')->get('workspace', [WorkspaceController::class, 'current'])->name('workspaces.current');
});
