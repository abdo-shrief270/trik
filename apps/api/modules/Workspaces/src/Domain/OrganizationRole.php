<?php

declare(strict_types=1);

namespace Modules\Workspaces\Domain;

/**
 * Organization roles manage the organization; they never give access to a workspace's data.
 * Access inside a workspace comes only from workspace membership (ADR 0012, §2).
 */
enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Billing = 'billing';
    case Member = 'member';

    public function canCreateWorkspaces(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /**
     * Grants in the organization permission catalogue (OrganizationPermissions). Built in only:
     * organizations have no custom roles.
     *
     * @return list<string>
     */
    public function grants(): array
    {
        return match ($this) {
            self::Owner => ['organization.*'],
            self::Admin => [
                'organization.settings.view',
                'organization.settings.update',
                'organization.members.view',
                'organization.members.manage',
                'organization.workspaces.view',
                'organization.workspaces.create',
                'organization.workspace_members.manage',
                'organization.audit.view',
            ],
            self::Billing => [
                'organization.settings.view',
                'organization.members.view',
                'organization.workspaces.view',
                'organization.billing.manage',
            ],
            self::Member => ['organization.settings.view'],
        };
    }

    /** Higher number = more power; used for "can't manage someone above you". */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Billing, self::Member => 1,
        };
    }
}
