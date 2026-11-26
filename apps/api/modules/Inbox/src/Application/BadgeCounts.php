<?php

declare(strict_types=1);

namespace Modules\Inbox\Application;

use App\Support\Inbox\WorkspaceBadges;
use App\Support\Realtime\Realtime;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Workspaces\Application\WorkspaceDirectory;

/** Reads the maintained badge counters, pushes them to members, and rebuilds them. */
final class BadgeCounts implements WorkspaceBadges
{
    public const WORKSPACE_ROW = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly WorkspaceDirectory $directory,
        private readonly Realtime $realtime,
    ) {}

    public function forUser(string $userId, array $workspaceIds): array
    {
        if ($workspaceIds === []) {
            return [];
        }

        // one index lookup across the user's workspaces, read-only
        $rows = $this->tenant->runReadOnly($workspaceIds, fn (): array => DB::table('inbox_badge_counts')
            ->whereIn('workspace_id', $workspaceIds)
            ->whereIn('user_id', [self::WORKSPACE_ROW, $userId])
            ->get(['workspace_id', 'user_id', 'unread', 'assigned'])
            ->all());

        $unread = [];
        $assigned = [];
        foreach ($rows as $r) {
            if ($r->user_id === self::WORKSPACE_ROW) {
                $unread[(string) $r->workspace_id] = max(0, (int) $r->unread);
            } else {
                $assigned[(string) $r->workspace_id] = max(0, (int) $r->assigned);
            }
        }

        $out = [];
        foreach ($workspaceIds as $ws) {
            $out[$ws] = ['unread' => $unread[$ws] ?? 0, 'assigned_to_me' => $assigned[$ws] ?? 0];
        }

        return $out;
    }

    /**
     * Tells everyone in the active workspace its current badges, on their own channel, so the
     * switcher updates wherever they are (two queries and one publish call).
     */
    public function push(): void
    {
        $workspace = $this->tenant->require();
        $rows = DB::table('inbox_badge_counts')->get(['user_id', 'unread', 'assigned'])->keyBy('user_id');
        $unread = max(0, (int) ($rows[self::WORKSPACE_ROW]->unread ?? 0));

        $messages = [];
        foreach ($this->directory->memberIds($workspace) as $userId) {
            $messages[] = [
                'channel' => Channels::user($userId),
                'data' => [
                    'type' => 'badges.updated',
                    'workspace_id' => $workspace,
                    'unread' => $unread,
                    'assigned_to_me' => max(0, (int) ($rows[$userId]->assigned ?? 0)),
                ],
            ];
        }

        $this->realtime->publish($messages);
    }

    /**
     * Rebuilds one workspace's counters from the conversations (run on the owner connection).
     * Locks out conversation writes for the moment it takes, so it never races the triggers.
     */
    public static function recount(string $workspaceId, string $connection = 'owner'): void
    {
        DB::connection($connection)->transaction(function () use ($workspaceId, $connection): void {
            $db = DB::connection($connection);
            $db->statement('lock table inbox_conversations in share mode');
            $db->delete('delete from inbox_badge_counts where workspace_id = ?', [$workspaceId]);
            $db->insert(<<<'SQL'
                insert into inbox_badge_counts (workspace_id, user_id, unread, assigned)
                select ?::uuid, '00000000-0000-0000-0000-000000000000'::uuid, count(*), 0
                from inbox_conversations where workspace_id = ? and status = 'open' and unread_count > 0
                union all
                select ?::uuid, assignee_id, count(*) filter (where unread_count > 0), count(*)
                from inbox_conversations where workspace_id = ? and status = 'open' and assignee_id is not null
                group by assignee_id
                SQL, [$workspaceId, $workspaceId, $workspaceId, $workspaceId]);
        });
    }
}
