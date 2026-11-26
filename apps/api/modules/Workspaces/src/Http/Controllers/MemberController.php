<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use App\Support\Http\CursorPage;
use App\Support\Identity\UserLookup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Workspaces\Application\ChangeMemberRole;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

final class MemberController
{
    public function index(Request $request, TenantContext $context, UserLookup $users): JsonResponse
    {
        $page = WorkspaceMember::query()
            ->with('customRole')
            ->where('workspace_id', $context->require())
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate(CursorPage::perPage($request));

        $people = $users->find(array_values(array_map(fn (WorkspaceMember $m): string => $m->user_id, $page->items())));

        return CursorPage::response($page, fn (WorkspaceMember $m): array => [
            'user_id' => $m->user_id,
            'name' => $people[$m->user_id]['name'] ?? null,
            'email' => $people[$m->user_id]['email'] ?? null,
            'role' => $m->role->value,
            'custom_role' => $m->customRole?->only(['id', 'name']),
            'joined_at' => $m->created_at?->toIso8601String(),
        ]);
    }

    public function update(Request $request, string $user, TenantContext $context, ChangeMemberRole $change): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
            'custom_role_id' => ['nullable', 'uuid'],
        ]);

        $member = $change->handle(
            $context->require(),
            (string) $request->user()?->getAuthIdentifier(),
            $user,
            WorkspaceRole::from($data['role']),
            $data['custom_role_id'] ?? null,
        );

        return response()->json(['data' => ['user_id' => $member->user_id, 'role' => $member->role->value, 'custom_role_id' => $member->custom_role_id]]);
    }

    public function destroy(Request $request, string $user, TenantContext $context, ChangeMemberRole $change): JsonResponse
    {
        $actor = (string) $request->user()?->getAuthIdentifier();

        if ($user !== $actor) {
            Gate::authorize('workspace.members.manage'); // anyone may leave; removing others needs the permission
        }

        $change->remove($context->require(), $actor, $user);

        return response()->json(null, 204);
    }
}
