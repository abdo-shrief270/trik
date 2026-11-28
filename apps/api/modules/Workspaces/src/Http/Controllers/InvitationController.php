<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use App\Support\Http\CursorPage;
use App\Support\Identity\UserLookup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Workspaces\Application\Invitations;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\Invitation;

final class InvitationController
{
    /** Open invitations (pending and expired; accepted and withdrawn ones are in the audit log). */
    public function index(Request $request, TenantContext $context, UserLookup $users): JsonResponse
    {
        $page = Invitation::query()
            ->with('customRole')
            ->where('workspace_id', $context->require())
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request), cursor: CursorPage::cursor($request, ['created_at' => 'datetime', 'id' => 'uuid']));

        $people = $users->find(array_values(array_unique(array_filter(array_map(fn (Invitation $i): ?string => $i->invited_by, $page->items())))));

        return CursorPage::response($page, fn (Invitation $i): array => self::present($i, $people));
    }

    public function store(Request $request, TenantContext $context, Invitations $invitations): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
            'custom_role_id' => ['nullable', 'uuid'],
        ]);

        $actor = (string) $request->user()?->getAuthIdentifier();
        [$invitation, $url] = $invitations->invite($context->require(), $actor, $data['email'], WorkspaceRole::from($data['role']), $data['custom_role_id'] ?? null);
        $invitation->load('customRole');

        return response()->json(['data' => [...self::present($invitation, []), 'url' => $url]], 201);
    }

    public function destroy(Request $request, string $invitation, TenantContext $context, Invitations $invitations): JsonResponse
    {
        $invitations->revoke($context->require(), (string) $request->user()?->getAuthIdentifier(), $invitation);

        return response()->json(null, 204);
    }

    /**
     * @param  array<string, array{id: string, name: string, email: string}>  $people
     * @return array<string, mixed>
     */
    public static function present(Invitation $i, array $people): array
    {
        return [
            'id' => $i->id,
            'email' => $i->email,
            'role' => $i->role->value,
            'custom_role' => $i->customRole?->only(['id', 'name']),
            'status' => $i->status(),
            'invited_by' => $i->invited_by !== null && isset($people[$i->invited_by]) ? ['id' => $i->invited_by, 'name' => $people[$i->invited_by]['name']] : null,
            'expires_at' => $i->expires_at->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
