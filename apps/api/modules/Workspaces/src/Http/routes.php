<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Workspaces\Http\Controllers\MemberController;
use Modules\Workspaces\Http\Controllers\OrganizationController;
use Modules\Workspaces\Http\Controllers\OrganizationMemberController;
use Modules\Workspaces\Http\Controllers\OrganizationWorkspaceMemberController;
use Modules\Workspaces\Http\Controllers\PermissionController;
use Modules\Workspaces\Http\Controllers\RoleController;
use Modules\Workspaces\Http\Controllers\WorkspaceAuditController;
use Modules\Workspaces\Http\Controllers\WorkspaceController;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::get('organizations', [WorkspaceController::class, 'organizations'])->name('workspaces.organizations');

    Route::middleware('workspace')->prefix('workspace')->name('workspaces.')->group(function (): void {
        Route::get('/', [WorkspaceController::class, 'current'])->name('current');
        Route::get('permissions', [PermissionController::class, 'mine'])->name('permissions.mine');
        Route::get('permissions/catalog', [PermissionController::class, 'catalog'])->name('permissions.catalog');

        Route::get('members', [MemberController::class, 'index'])->middleware('can:workspace.members.view')->name('members.index');
        Route::patch('members/{user}', [MemberController::class, 'update'])->middleware('can:workspace.members.manage')->whereUuid('user')->name('members.update');
        Route::delete('members/{user}', [MemberController::class, 'destroy'])->whereUuid('user')->name('members.destroy');

        Route::get('audit', [WorkspaceAuditController::class, 'index'])->middleware('can:workspace.audit.view')->name('audit.index');

        Route::get('roles', [RoleController::class, 'index'])->middleware('can:workspace.members.view')->name('roles.index');
        Route::middleware('can:workspace.roles.manage')->group(function (): void {
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::patch('roles/{role}', [RoleController::class, 'update'])->whereUuid('role')->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereUuid('role')->name('roles.destroy');
        });
    });
});

Route::middleware(['auth:sanctum', 'organization'])->prefix('organization')->name('organizations.')->group(function (): void {
    Route::get('/', [OrganizationController::class, 'show'])->middleware('can:organization.settings.view')->name('show');
    Route::patch('/', [OrganizationController::class, 'update'])->middleware('can:organization.settings.update')->name('update');
    Route::get('audit', [OrganizationController::class, 'audit'])->middleware('can:organization.audit.view')->name('audit');
    Route::get('workspaces', [OrganizationController::class, 'workspaces'])->middleware('can:organization.workspaces.view')->name('workspaces');

    Route::get('members', [OrganizationMemberController::class, 'index'])->middleware('can:organization.members.view')->name('members.index');
    Route::patch('members/{user}', [OrganizationMemberController::class, 'update'])->middleware('can:organization.members.manage')->whereUuid('user')->name('members.update');
    Route::delete('members/{user}', [OrganizationMemberController::class, 'destroy'])->whereUuid('user')->name('members.destroy');

    Route::prefix('workspaces/{workspace}/members')->whereUuid('workspace')->middleware('can:organization.workspace_members.manage')->name('workspace_members.')->group(function (): void {
        Route::get('/', [OrganizationWorkspaceMemberController::class, 'index'])->name('index');
        Route::post('/', [OrganizationWorkspaceMemberController::class, 'store'])->name('store');
        Route::patch('{user}', [OrganizationWorkspaceMemberController::class, 'update'])->whereUuid('user')->name('update');
        Route::delete('{user}', [OrganizationWorkspaceMemberController::class, 'destroy'])->whereUuid('user')->name('destroy');
    });
});
