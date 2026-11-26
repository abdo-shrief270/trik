<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use App\Support\Authorization\MemberAccess;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Http\Problem;
use Modules\Workspaces\Infrastructure\Models\CustomRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/**
 * Custom roles: a name and a list of grants from the permission catalogue.
 *  - Owner-level powers (`*`, deleting the workspace) are never available to custom roles.
 *  - Nobody can grant more than they hold themselves (no escalation through a role), and
 *    nobody can edit or delete the custom role they are assigned.
 */
final class ManageRoles
{
    private const FORBIDDEN_GRANTS = ['*', 'workspace.*', 'workspace.workspace.delete'];

    public function __construct(private readonly PermissionRegistry $registry) {}

    /** @param list<string> $permissions */
    public function create(MemberAccess $actor, string $name, ?string $description, array $permissions): CustomRole
    {
        $this->assertName($name);
        $clean = $this->clean($permissions);
        $this->assertWithinCeiling($actor, $clean);

        return CustomRole::query()->create(['name' => $name, 'description' => $description, 'permissions' => $clean]);
    }

    /** @param list<string>|null $permissions */
    public function update(MemberAccess $actor, CustomRole $role, ?string $name, ?string $description, ?array $permissions): CustomRole
    {
        $this->assertNotOwnRole($actor, $role);

        if ($name !== null && $name !== $role->name) {
            $this->assertName($name);
            $role->name = $name;
        }

        if ($description !== null) {
            $role->description = $description;
        }

        if ($permissions !== null) {
            $clean = $this->clean($permissions);
            $this->assertWithinCeiling($actor, $clean);
            $role->permissions = $clean;
        }

        $role->save();

        return $role;
    }

    public function delete(MemberAccess $actor, CustomRole $role): void
    {
        $this->assertNotOwnRole($actor, $role);

        if (WorkspaceMember::query()->where('custom_role_id', $role->id)->exists()) {
            throw Problem::conflict('role-in-use', 'Move the members who have this role to another role first.');
        }

        $role->delete();
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function clean(array $permissions): array
    {
        $clean = array_values(array_unique(array_map('trim', $permissions)));
        $errors = [];

        foreach ($clean as $i => $grant) {
            if (in_array(ltrim($grant, '!'), self::FORBIDDEN_GRANTS, true)) {
                $errors["permissions.{$i}"] = ["{$grant} is reserved for owners."];
            } elseif (! $this->registry->isValidGrant($grant)) {
                $errors["permissions.{$i}"] = ["{$grant} is not a known permission."];
            }
        }

        if ($errors !== []) {
            throw Problem::invalid($errors);
        }

        sort($clean);

        return $clean;
    }

    /** @param list<string> $grants */
    private function assertWithinCeiling(MemberAccess $actor, array $grants): void
    {
        $mine = $this->registry->expand($actor->grants);
        $beyond = array_values(array_diff($this->registry->expand($grants), $mine));

        if ($beyond !== []) {
            throw Problem::forbidden('beyond-own-permissions', 'You can only grant permissions you have yourself. Not yours: '.implode(', ', $beyond).'.');
        }
    }

    private function assertNotOwnRole(MemberAccess $actor, CustomRole $role): void
    {
        if ($actor->customRoleId === $role->id) {
            throw Problem::forbidden('cannot-edit-own-role', 'Ask an owner or admin to change the role you have.');
        }
    }

    private function assertName(string $name): void
    {
        if (CustomRole::query()->where('name', $name)->exists()) {
            throw Problem::invalid(['name' => ['A role with this name already exists.']]);
        }
    }
}
