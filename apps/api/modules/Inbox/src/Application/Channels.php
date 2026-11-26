<?php

declare(strict_types=1);

namespace Modules\Inbox\Application;

/** Real-time channel names. Clients subscribe with a token the API signs (#98). */
final class Channels
{
    public static function inbox(string $workspaceId): string
    {
        return 'inbox:'.$workspaceId;
    }

    /** A person's own channel, across workspaces (badges, notifications). */
    public static function user(string $userId): string
    {
        return 'user:'.$userId;
    }
}
