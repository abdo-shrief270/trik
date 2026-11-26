<?php

declare(strict_types=1);

namespace Modules\Workspaces\Infrastructure;

use App\Support\Authorization\PermissionRegistry;

/**
 * The organization permission catalogue. Kept apart from the workspace catalogue on purpose:
 * organization powers are checked against the organization role (X-Organization-Id), never
 * against workspace grants, and can't be put into custom workspace roles.
 */
final class OrganizationPermissions
{
    public const PERMISSIONS = [
        'organization.settings.view' => 'See the organization',
        'organization.settings.update' => 'Rename the organization',
        'organization.members.view' => 'See the organization\'s people',
        'organization.members.manage' => 'Change organization roles and remove people from the organization',
        'organization.workspaces.view' => 'See the list of the organization\'s workspaces (names only, no data)',
        'organization.workspaces.create' => 'Create workspaces in the organization',
        'organization.workspace_members.manage' => 'Add, change and remove people in the organization\'s workspaces',
        'organization.audit.view' => 'Read the organization audit log',
        'organization.billing.manage' => 'Invoices and payment methods',
        'organization.organization.delete' => 'Delete the organization (owners only)',
    ];

    public static function registry(): PermissionRegistry
    {
        $registry = new PermissionRegistry;
        $registry->register('Organization', self::PERMISSIONS);

        return $registry;
    }
}
