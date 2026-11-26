<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveWorkspace;
use App\Support\Authorization\PermissionGate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\CustomRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/** Adds a user to a workspace with a role and returns request headers for them. */
function memberOf(string $workspace, WorkspaceRole $role, ?string $customRoleId = null): array
{
    $user = User::factory()->create();
    WorkspaceMember::query()->create(['workspace_id' => $workspace, 'user_id' => $user->id, 'role' => $role, 'custom_role_id' => $customRoleId]);

    return [
        'user' => $user,
        'headers' => ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken, ResolveWorkspace::HEADER => $workspace],
    ];
}

function as_(array $member): TestCase
{
    app('auth')->forgetGuards();
    app(PermissionGate::class)->forget();

    return test()->flushHeaders()->withHeaders($member['headers']);
}

beforeEach(function (): void {
    $this->owner = ownerWithWorkspace();
    $this->ws = $this->owner['workspace'];
});

it('tells each member what they can do', function (): void {
    $agent = memberOf($this->ws, WorkspaceRole::Agent);

    as_($agent)->getJson('/v1/workspace/permissions')
        ->assertOk()
        ->assertJsonPath('data.role', 'agent')
        ->assertJsonPath('data.permissions', ['confirmation.settings.view', 'inbox.conversations.reply', 'inbox.conversations.view', 'orders.orders.confirm', 'orders.orders.create', 'orders.orders.update', 'orders.orders.view', 'whatsapp.messages.send', 'whatsapp.messages.view', 'whatsapp.numbers.view', 'whatsapp.templates.view', 'workspace.members.view']);

    $perms = as_($this->owner)->getJson('/v1/workspace/permissions')->json('data.permissions');
    expect($perms)->toContain('workspace.workspace.delete', 'whatsapp.numbers.manage');

    $admin = memberOf($this->ws, WorkspaceRole::Admin);
    expect(as_($admin)->getJson('/v1/workspace/permissions')->json('data.permissions'))
        ->toContain('workspace.members.manage')->not->toContain('workspace.workspace.delete');
});

it('lists the permission catalogue by module', function (): void {
    $catalog = collect(as_($this->owner)->getJson('/v1/workspace/permissions/catalog')->assertOk()->json('data'))->keyBy('module');

    expect($catalog->keys()->all())->toContain('WhatsApp', 'Workspaces')
        ->and(collect($catalog['WhatsApp']['permissions'])->pluck('key')->all())->toContain('whatsapp.messages.send');
});

it('stops an agent from connecting numbers but lets them send', function (): void {
    $agent = memberOf($this->ws, WorkspaceRole::Agent);

    as_($agent)->postJson('/v1/whatsapp/numbers', ['provider' => 'fake', 'external_id' => 'x', 'credentials' => ['api_key' => 'k']])
        ->assertForbidden()
        ->assertJsonPath('code', 'missing-permission')
        ->assertJsonPath('detail', 'You need the whatsapp.numbers.manage permission in this workspace.');

    $number = as_($this->owner)->postJson('/v1/whatsapp/numbers', ['provider' => 'fake', 'external_id' => 'x', 'credentials' => ['api_key' => 'k']])->json('data.id');

    as_($agent)->withHeader('Idempotency-Key', 'a-1')
        ->postJson("/v1/whatsapp/numbers/{$number}/messages", ['to' => '01012345678', 'type' => 'text', 'text' => 'hi'])
        ->assertAccepted();
});

it('applies a role only in its own workspace', function (): void {
    $other = ownerWithWorkspace('Other');
    // the owner of "Other" is only an agent here
    WorkspaceMember::query()->create(['workspace_id' => $this->ws, 'user_id' => $other['user']->id, 'role' => WorkspaceRole::Agent]);

    as_(['headers' => [...$other['headers'], ResolveWorkspace::HEADER => $this->ws]])
        ->postJson('/v1/workspace/roles', ['name' => 'x', 'permissions' => ['whatsapp.messages.view']])
        ->assertForbidden();
});

it('creates custom roles from the catalogue and assigns them', function (): void {
    $role = as_($this->owner)->postJson('/v1/workspace/roles', [
        'name' => 'Confirmation team',
        'permissions' => ['whatsapp.messages.*', 'whatsapp.numbers.view'],
    ])->assertCreated()->json('data');

    $person = memberOf($this->ws, WorkspaceRole::Agent);
    as_($this->owner)->patchJson("/v1/workspace/members/{$person['user']->id}", ['role' => 'custom', 'custom_role_id' => $role['id']])
        ->assertOk()->assertJsonPath('data.role', 'custom');

    as_($person)->getJson('/v1/workspace/permissions')
        ->assertJsonPath('data.role', 'custom')
        ->assertJsonPath('data.permissions', ['whatsapp.messages.send', 'whatsapp.messages.view', 'whatsapp.numbers.view']);

    as_($person)->getJson('/v1/workspace/members')->assertForbidden(); // the custom role has no team access

    as_($this->owner)->deleteJson("/v1/workspace/roles/{$role['id']}")->assertConflict()->assertJsonPath('code', 'role-in-use');
});

it('rejects unknown and owner-only grants in custom roles', function (): void {
    as_($this->owner)->postJson('/v1/workspace/roles', ['name' => 'Bad', 'permissions' => ['orders.everything', '*']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions.0', 'permissions.1']);
});

it('keeps team rules: no self-promotion, owners managed by owners, always one owner', function (): void {
    $admin = memberOf($this->ws, WorkspaceRole::Admin);
    $agent = memberOf($this->ws, WorkspaceRole::Agent);
    $ownerId = $this->owner['user']->id;

    as_($admin)->patchJson("/v1/workspace/members/{$admin['user']->id}", ['role' => 'owner'])->assertForbidden()->assertJsonPath('code', 'cannot-change-own-role');
    as_($admin)->patchJson("/v1/workspace/members/{$agent['user']->id}", ['role' => 'owner'])->assertForbidden()->assertJsonPath('code', 'owner-only');
    as_($admin)->patchJson("/v1/workspace/members/{$ownerId}", ['role' => 'agent'])->assertForbidden()->assertJsonPath('code', 'owner-only');
    as_($admin)->deleteJson("/v1/workspace/members/{$ownerId}")->assertForbidden();
    as_($admin)->patchJson("/v1/workspace/members/{$agent['user']->id}", ['role' => 'admin'])->assertOk();

    as_($this->owner)->deleteJson("/v1/workspace/members/{$ownerId}")->assertConflict()->assertJsonPath('code', 'last-owner');

    as_($this->owner)->patchJson("/v1/workspace/members/{$admin['user']->id}", ['role' => 'owner'])->assertOk();
    as_($this->owner)->deleteJson("/v1/workspace/members/{$ownerId}")->assertNoContent(); // now they can leave
});

it('lets any member leave, but not remove others without permission', function (): void {
    $a = memberOf($this->ws, WorkspaceRole::Agent);
    $b = memberOf($this->ws, WorkspaceRole::Agent);

    as_($a)->deleteJson("/v1/workspace/members/{$b['user']->id}")->assertForbidden();
    as_($a)->deleteJson("/v1/workspace/members/{$a['user']->id}")->assertNoContent();
});

it('lists the team with names and roles, a page at a time', function (): void {
    foreach (range(1, 3) as $_) {
        memberOf($this->ws, WorkspaceRole::Agent);
    }

    $first = as_($this->owner)->getJson('/v1/workspace/members?per_page=2')->assertOk();
    expect($first->json('data'))->toHaveCount(2)
        ->and($first->json('data.0.role'))->toBe('owner')
        ->and($first->json('data.0.email'))->toBe($this->owner['user']->email)
        ->and($first->json('meta.next_cursor'))->toBeString();

    $second = as_($this->owner)->getJson('/v1/workspace/members?per_page=2&cursor='.$first->json('meta.next_cursor'))->assertOk();
    expect($second->json('data'))->toHaveCount(2)->and($second->json('meta.next_cursor'))->toBeNull();
});

it('reads custom roles under row-level security', function (): void {
    $role = as_($this->owner)->postJson('/v1/workspace/roles', ['name' => 'Ops', 'permissions' => ['whatsapp.*']])->json('data.id');
    $elsewhere = ownerWithWorkspace('Elsewhere');

    $seen = app(TenantContext::class)->run($elsewhere['workspace'], fn () => CustomRole::query()->whereKey($role)->exists());
    expect($seen)->toBeFalse();
});
