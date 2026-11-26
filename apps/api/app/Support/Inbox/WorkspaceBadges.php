<?php

declare(strict_types=1);

namespace App\Support\Inbox;

/**
 * Where work is waiting, per workspace, for the switcher (ADR 0012, #368). Implemented by the Inbox
 * module from maintained counters (never a COUNT per request).
 */
interface WorkspaceBadges
{
    /**
     * @param  list<string>  $workspaceIds  only workspaces the user belongs to
     * @return array<string, array{unread: int, assigned_to_me: int}> workspace id => badges
     */
    public function forUser(string $userId, array $workspaceIds): array;
}
