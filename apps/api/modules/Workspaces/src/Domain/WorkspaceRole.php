<?php

declare(strict_types=1);

namespace Modules\Workspaces\Domain;

/**
 * Built-in roles, defined in code so every workspace has them and they never drift.
 * Custom roles (per workspace, stored in workspaces_roles) use Custom plus their own grants.
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Agent = 'agent';
    case Custom = 'custom';

    /** @return list<string> grants (see PermissionRegistry for the syntax) */
    public function grants(): array
    {
        return match ($this) {
            self::Owner => ['*'],
            self::Admin => ['*', '!workspace.workspace.delete', '!billing.*'],
            self::Agent => [
                'workspace.members.view',
                'whatsapp.numbers.view',
                'whatsapp.templates.view',
                'whatsapp.messages.view',
                'whatsapp.messages.send',
                'inbox.conversations.view',
                'inbox.conversations.reply',
                'orders.orders.view',
                'orders.orders.create',
                'orders.orders.update',
                'orders.orders.confirm',
            ],
            self::Custom => [],
        };
    }

    public function isBuiltIn(): bool
    {
        return $this !== self::Custom;
    }
}
