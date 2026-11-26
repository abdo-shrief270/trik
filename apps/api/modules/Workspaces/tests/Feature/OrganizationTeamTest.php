<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveWorkspace;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Workspaces\Application\CreateWorkspace;
use Modules\Workspaces\Domain\OrganizationRole;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Http\Middleware\ResolveOrganization;
use Modules\Workspaces\Infrastructure\Models\OrganizationMember;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/** Request headers for a user acting in an organization (and optionally a workspace). */
function orgAs(User $user, string $orgId, ?string $workspaceId = null): TestCase
{
    app('auth')->forgetGuards();
    app()->forgetScopedInstances();

    return test()->flushHeaders()->withHeaders(array_filter([
        'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
        ResolveOrganization::HEADER => $orgId,
        ResolveWorkspace::HEADER => $workspaceId,
    ]));
}

function joinOrg(string $orgId, OrganizationRole $role): User
{
    $user = User::factory()->create();
    OrganizationMember::query()->create(['organization_id' => $orgId, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->a = app(CreateWorkspace::class)->forUser('Brand A', $this->owner->id);
    $this->b = app(CreateWorkspace::class)->forUser('Brand B', $this->owner->id);
    $this->org = $this->a->organizationId;
});

it('shows the organization, the caller\'s role and organization permissions', function (): void {
    orgAs($this->owner, $this->org)->getJson('/v1/organization')
        ->assertOk()->assertJsonPath('data.role', 'owner')->assertJsonPath('data.name', 'Brand A');

    $billing = joinOrg($this->org, OrganizationRole::Billing);
    $perms = orgAs($billing, $this->org)->getJson('/v1/organization')->json('data.permissions');
    expect($perms)->toContain('organization.billing.manage')->not->toContain('organization.members.manage');

    orgAs(User::factory()->create(), $this->org)->getJson('/v1/organization')->assertForbidden()->assertJsonPath('code', 'not-in-organization');
});

it('lets an org admin add people (themselves too) to a workspace without giving data access by default', function (): void {
    $admin = joinOrg($this->org, OrganizationRole::Admin);
    $agent = joinOrg($this->org, OrganizationRole::Member);

    // an organization admin is not in Brand A, so can't open its data…
    orgAs($admin, $this->org, $this->a->id)->getJson('/v1/workspace')->assertForbidden()->assertJsonPath('code', 'not-a-member');

    // …but can add an agent, and themselves, on purpose
    orgAs($admin, $this->org)->postJson("/v1/organization/workspaces/{$this->a->id}/members", ['user_id' => $agent->id, 'role' => 'agent'])->assertCreated();
    orgAs($admin, $this->org)->postJson("/v1/organization/workspaces/{$this->a->id}/members", ['user_id' => $admin->id, 'role' => 'admin'])->assertCreated();
    orgAs($admin, $this->org, $this->a->id)->getJson('/v1/workspace')->assertOk()->assertJsonPath('data.role', 'admin');

    // both adds are in the workspace audit log, visible to the workspace's admins
    $events = orgAs($this->owner, $this->org, $this->a->id)->getJson('/v1/workspace/audit')->assertOk()->json('data');
    $added = array_values(array_filter($events, fn ($e) => $e['action'] === 'member.added'));
    expect($added)->toHaveCount(2)
        ->and(collect($added)->firstWhere('subject.id', $admin->id)['data'])->toMatchArray(['self' => true, 'via' => 'organization']);

    // and in the organization audit log
    $orgEvents = collect(orgAs($this->owner, $this->org)->getJson('/v1/organization/audit')->json('data'))->pluck('action');
    expect($orgEvents)->toContain('workspace.member.added', 'workspace.created');
});

it('keeps organization role rules', function (): void {
    $admin = joinOrg($this->org, OrganizationRole::Admin);
    $admin2 = joinOrg($this->org, OrganizationRole::Admin);
    $member = joinOrg($this->org, OrganizationRole::Member);

    orgAs($admin, $this->org)->patchJson("/v1/organization/members/{$admin->id}", ['role' => 'owner'])->assertForbidden()->assertJsonPath('code', 'cannot-change-own-role');
    orgAs($admin, $this->org)->patchJson("/v1/organization/members/{$member->id}", ['role' => 'owner'])->assertForbidden()->assertJsonPath('code', 'owner-only');
    orgAs($admin, $this->org)->patchJson("/v1/organization/members/{$this->owner->id}", ['role' => 'member'])->assertForbidden()->assertJsonPath('code', 'owner-only');
    orgAs($admin, $this->org)->patchJson("/v1/organization/members/{$admin2->id}", ['role' => 'member'])->assertForbidden()->assertJsonPath('code', 'beyond-own-permissions');
    orgAs($admin, $this->org)->patchJson("/v1/organization/members/{$member->id}", ['role' => 'billing'])->assertOk();
    orgAs($member, $this->org)->patchJson("/v1/organization/members/{$admin->id}", ['role' => 'member'])->assertForbidden(); // no permission at all

    orgAs($this->owner, $this->org)->deleteJson("/v1/organization/members/{$this->owner->id}")->assertConflict()->assertJsonPath('code', 'last-owner');
});

it('removes someone from the organization and all its workspaces, but never strands a workspace without an owner', function (): void {
    $agent = joinOrg($this->org, OrganizationRole::Member);
    foreach ([$this->a, $this->b] as $ws) {
        WorkspaceMember::query()->create(['workspace_id' => $ws->id, 'user_id' => $agent->id, 'role' => WorkspaceRole::Agent]);
    }

    orgAs($this->owner, $this->org)->deleteJson("/v1/organization/members/{$agent->id}")->assertNoContent();
    expect(WorkspaceMember::query()->where('user_id', $agent->id)->count())->toBe(0)
        ->and(OrganizationMember::query()->where('user_id', $agent->id)->exists())->toBeFalse();

    // a co-owner who is the only owner of Brand B can't be removed until B has another owner
    $coOwner = joinOrg($this->org, OrganizationRole::Owner);
    WorkspaceMember::query()->where('workspace_id', $this->b->id)->where('user_id', $this->owner->id)->update(['role' => 'admin']);
    WorkspaceMember::query()->create(['workspace_id' => $this->b->id, 'user_id' => $coOwner->id, 'role' => WorkspaceRole::Owner]);

    orgAs($this->owner, $this->org)->deleteJson("/v1/organization/members/{$coOwner->id}")->assertConflict()->assertJsonPath('code', 'last-workspace-owner');
});

it('lets anyone leave the organization', function (): void {
    $member = joinOrg($this->org, OrganizationRole::Member);
    $other = joinOrg($this->org, OrganizationRole::Member);

    orgAs($member, $this->org)->deleteJson("/v1/organization/members/{$other->id}")->assertForbidden();
    orgAs($member, $this->org)->deleteJson("/v1/organization/members/{$member->id}")->assertNoContent();
});

it('adds only people from the organization, and only owners create workspace owners', function (): void {
    $admin = joinOrg($this->org, OrganizationRole::Admin);
    $member = joinOrg($this->org, OrganizationRole::Member);
    $stranger = User::factory()->create();

    orgAs($admin, $this->org)->postJson("/v1/organization/workspaces/{$this->a->id}/members", ['user_id' => $stranger->id, 'role' => 'agent'])
        ->assertUnprocessable()->assertJsonPath('code', 'not-in-organization');
    orgAs($admin, $this->org)->postJson("/v1/organization/workspaces/{$this->a->id}/members", ['user_id' => $member->id, 'role' => 'owner'])
        ->assertForbidden()->assertJsonPath('code', 'owner-only');
    orgAs($this->owner, $this->org)->postJson("/v1/organization/workspaces/{$this->a->id}/members", ['user_id' => $member->id, 'role' => 'owner'])->assertCreated();
    orgAs($this->owner, $this->org)->postJson("/v1/organization/workspaces/{$this->a->id}/members", ['user_id' => $member->id, 'role' => 'agent'])
        ->assertConflict()->assertJsonPath('code', 'already-a-member');

    // admins can't demote or remove a workspace owner
    orgAs($admin, $this->org)->patchJson("/v1/organization/workspaces/{$this->a->id}/members/{$member->id}", ['role' => 'agent'])->assertForbidden();
    orgAs($admin, $this->org)->deleteJson("/v1/organization/workspaces/{$this->a->id}/members/{$member->id}")->assertForbidden();

    // the last workspace owner stays
    WorkspaceMember::query()->where('workspace_id', $this->a->id)->where('user_id', $member->id)->delete();
    orgAs($this->owner, $this->org)->patchJson("/v1/organization/workspaces/{$this->a->id}/members/{$this->owner->id}", ['role' => 'admin'])
        ->assertConflict()->assertJsonPath('code', 'last-workspace-owner');
});

it('never touches workspaces of another organization', function (): void {
    $other = app(CreateWorkspace::class)->forUser('Elsewhere', User::factory()->create()->id);
    $member = joinOrg($this->org, OrganizationRole::Member);

    orgAs($this->owner, $this->org)->postJson("/v1/organization/workspaces/{$other->id}/members", ['user_id' => $member->id, 'role' => 'agent'])
        ->assertNotFound()->assertJsonPath('code', 'workspace-not-found');
    orgAs($this->owner, $this->org)->getJson("/v1/organization/workspaces/{$other->id}/members")->assertNotFound();
    orgAs($this->owner, $other->organizationId)->getJson('/v1/organization/members')->assertForbidden();
});

it('lists people with their workspaces in this organization, and workspace names without data', function (): void {
    $agent = joinOrg($this->org, OrganizationRole::Member);
    WorkspaceMember::query()->create(['workspace_id' => $this->b->id, 'user_id' => $agent->id, 'role' => WorkspaceRole::Agent]);

    $people = collect(orgAs($this->owner, $this->org)->getJson('/v1/organization/members')->assertOk()->json('data'))->keyBy('user_id');
    expect($people[$agent->id]['workspaces'])->toBe([['id' => $this->b->id, 'name' => 'Brand B', 'role' => 'agent']]);

    $workspaces = orgAs($agent, $this->org)->getJson('/v1/organization/workspaces');
    $workspaces->assertForbidden(); // members don't get the organization's workspace list

    $list = orgAs($this->owner, $this->org)->getJson('/v1/organization/workspaces')->assertOk()->json('data');
    expect(array_column($list, 'name'))->toBe(['Brand A', 'Brand B'])->and($list[0]['my_role'])->toBe('owner');
});

it('keeps audit logs append-only for the app', function (): void {
    expect(DB::scalar("select has_table_privilege(current_user, 'workspaces_audit_events', 'update')"))->toBeFalse()
        ->and(DB::scalar("select has_table_privilege(current_user, 'workspaces_audit_events', 'delete')"))->toBeFalse()
        ->and(DB::scalar("select has_table_privilege(current_user, 'workspaces_organization_audit_events', 'delete')"))->toBeFalse();
});

it('keeps workspace audit logs inside their workspace', function (): void {
    $count = app(TenantContext::class)->run($this->b->id, fn () => DB::table('workspaces_audit_events')->where('workspace_id', $this->a->id)->count());
    expect($count)->toBe(0);
});

it('cannot use organization permissions inside custom workspace roles', function (): void {
    orgAs($this->owner, $this->org, $this->a->id)->postJson('/v1/workspace/roles', ['name' => 'Sneaky', 'permissions' => ['organization.members.manage']])
        ->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
});
