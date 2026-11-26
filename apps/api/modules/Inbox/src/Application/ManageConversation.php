<?php

declare(strict_types=1);

namespace Modules\Inbox\Application;

use App\Support\Authorization\PermissionRegistry;
use App\Support\Authorization\WorkspacePermissions;
use App\Support\Http\Problem;
use App\Support\Realtime\Realtime;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Inbox\Domain\ConversationStatus;
use Modules\Inbox\Infrastructure\Models\Conversation;

/** Status, assignment and read state of a conversation. */
final class ManageConversation
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly WorkspacePermissions $permissions,
        private readonly PermissionRegistry $registry,
        private readonly Realtime $realtime,
        private readonly BadgeCounts $badges,
    ) {}

    /**
     * Assigning someone else (or taking a conversation from someone) needs inbox.conversations.assign;
     * anyone who can reply may take an unassigned conversation or let go of their own.
     */
    public function assign(Conversation $conversation, ?string $assigneeId, string $actorId, bool $actorCanAssign): Conversation
    {
        $selfService = ($assigneeId === $actorId && $conversation->assignee_id === null)
            || ($assigneeId === null && $conversation->assignee_id === $actorId);

        if (! $actorCanAssign && ! $selfService) {
            throw Problem::forbidden('cannot-assign', 'You can take unassigned conversations and let go of your own. Assigning others needs the "assign conversations" permission.');
        }

        if ($assigneeId !== null && $assigneeId !== $conversation->assignee_id) {
            $access = $this->permissions->access($this->tenant->require(), $assigneeId);

            if ($access === null || ! $this->registry->allows($access->grants, 'inbox.conversations.view')) {
                throw Problem::unprocessable('assignee-cannot-view', 'That person is not on this workspace\'s team or cannot see conversations.');
            }
        }

        return $this->save($conversation, ['assignee_id' => $assigneeId]);
    }

    public function setStatus(Conversation $conversation, ConversationStatus $status): Conversation
    {
        return $this->save($conversation, [
            'status' => $status,
            'closed_at' => $status === ConversationStatus::Closed ? ($conversation->closed_at ?? now()) : null,
        ]);
    }

    public function markRead(Conversation $conversation): Conversation
    {
        return $conversation->unread_count === 0 ? $conversation : $this->save($conversation, ['unread_count' => 0]);
    }

    /** @param array<string, mixed> $changes */
    private function save(Conversation $conversation, array $changes): Conversation
    {
        $conversation->fill($changes);

        if (! $conversation->isDirty()) {
            return $conversation;
        }

        $conversation->save();
        $payload = [[
            'channel' => Channels::inbox($conversation->workspace_id),
            'data' => [
                'type' => 'conversation.updated',
                'id' => $conversation->id,
                'status' => $conversation->status->value,
                'assignee_id' => $conversation->assignee_id,
                'unread_count' => $conversation->unread_count,
                'last_message_at' => $conversation->last_message_at->toIso8601String(),
            ],
        ]];
        DB::afterCommit(function () use ($payload): void {
            $this->realtime->publish($payload);
            $this->badges->push();
        });

        return $conversation;
    }
}
