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
}
