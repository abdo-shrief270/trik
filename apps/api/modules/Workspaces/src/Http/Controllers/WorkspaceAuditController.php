<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use App\Support\Http\CursorPage;
use App\Support\Identity\UserLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkspaceAuditController
{
    /** The workspace's audit log, newest first (RLS keeps it to this workspace). */
    public function index(Request $request, UserLookup $users): JsonResponse
    {
        $page = DB::table('workspaces_audit_events')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request), cursor: CursorPage::cursor($request, ['created_at' => 'datetime', 'id' => 'uuid']));

        return AuditPresenter::page($page, $users);
    }
}
