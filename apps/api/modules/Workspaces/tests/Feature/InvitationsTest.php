<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Workspaces\Application\Invitations;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\CustomRole;
use Modules\Workspaces\Infrastructure\Models\Invitation;
use Modules\Workspaces\Infrastructure\Models\OrganizationMember;
use Modules\Workspaces\Infrastructure\Models\Workspace;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;
use Modules\Workspaces\Infrastructure\Notifications\WorkspaceInvitationMail;

beforeEach(function (): void {
    Notification::fake();
});

/** Invites through the API and returns the link's token. */
function invite(array $headers, string $email, string $role = 'agent', array $extra = []): string
{
    $res = inboxAs($headers + ['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/v1/workspace/invitations', ['email' => $email, 'role' => $role, ...$extra])
        ->assertCreated();

    return basename((string) $res->json('data.url'));
}

function tokenHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
}

it('invites by email, keeps only the hash, and sends the link', function (): void {
    $owner = ownerWithWorkspace('Nour Store');

    $res = inboxAs($owner['headers'] + ['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/v1/workspace/invitations', ['email' => ' Agent@Example.COM ', 'role' => 'agent'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'agent@example.com')
        ->assertJsonPath('data.role', 'agent')
        ->assertJsonPath('data.status', 'pending');

    $url = (string) $res->json('data.url');
    $token = basename($url);
    expect($url)->toStartWith(rtrim((string) config('app.frontend_url'), '/').'/invite/')
        ->and(strlen($token))->toBe(43);

    $stored = inWs($owner['workspace'], fn () => Invitation::query()->sole());
    expect($stored->token_hash)->toBe(hash('sha256', $token))
        ->and($stored->getAttributes())->not->toContain($token)
        ->and($stored->expires_at->isAfter(now()->addDays(6)))->toBeTrue();

    Notification::assertSentOnDemand(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail, array $channels, AnonymousNotifiable $to) use ($url): bool {
        return $to->routes['mail'] === 'agent@example.com' && $mail->url === $url && $mail->workspace === 'Nour Store';
    });

    inboxAs($owner['headers'])->getJson('/v1/workspace/invitations')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'agent@example.com')
        ->assertJsonMissingPath('data.0.url')->assertJsonMissingPath('data.0.token_hash');
});

it('shows what a link is for without signing in', function (): void {
    $owner = ownerWithWorkspace('Nour Store');
    $token = invite($owner['headers'], 'agent@example.com');

    app('auth')->forgetGuards();
    test()->flushHeaders()->getJson("/v1/invitations/{$token}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.workspace.name', 'Nour Store')
        ->assertJsonPath('data.inviter.name', $owner['user']->name)
        ->assertJsonPath('data.email', 'agent@example.com')
        ->assertJsonPath('data.status', 'pending');

    test()->getJson('/v1/invitations/'.str_repeat('a', 43))->assertNotFound()->assertJsonPath('code', 'invitation-not-found');
    test()->getJson('/v1/invitations/short')->assertNotFound();
});

it('lets the invited account join, once, and puts it in the organization', function (): void {
    $owner = ownerWithWorkspace();
    $agent = User::factory()->create(['email' => 'agent@example.com']);
    $token = invite($owner['headers'], 'agent@example.com');

    inboxAs(tokenHeaders($agent))->postJson("/v1/invitations/{$token}/accept")
        ->assertOk()->assertJsonPath('data.workspace.id', $owner['workspace'])->assertJsonPath('data.workspace.role', 'agent');

    expect(WorkspaceMember::query()->where('workspace_id', $owner['workspace'])->where('user_id', $agent->id)->value('role'))->toBe(WorkspaceRole::Agent);
    $org = Workspace::query()->findOrFail($owner['workspace'])->organization_id;
    expect(OrganizationMember::query()->where('organization_id', $org)->where('user_id', $agent->id)->value('role')?->value)->toBe('member');
    expect(inWs($owner['workspace'], fn () => Invitation::query()->sole()->status()))->toBe('accepted');

    // the workspace is now theirs to open
    inboxAs(tokenHeaders($agent) + ['X-Workspace-Id' => $owner['workspace']])->getJson('/v1/workspace')->assertOk();

    // a link works once
    inboxAs(tokenHeaders($agent))->postJson("/v1/invitations/{$token}/accept")->assertStatus(409)->assertJsonPath('code', 'invitation-used');
});

it('refuses another account, even a signed-in one', function (): void {
    $owner = ownerWithWorkspace();
    $token = invite($owner['headers'], 'agent@example.com');
    $other = User::factory()->create(['email' => 'someone@example.com']);

    inboxAs(tokenHeaders($other))->postJson("/v1/invitations/{$token}/accept")
        ->assertForbidden()->assertJsonPath('code', 'invitation-other-email');
    expect(WorkspaceMember::query()->where('user_id', $other->id)->where('workspace_id', $owner['workspace'])->exists())->toBeFalse();

    app('auth')->forgetGuards();
    test()->flushHeaders()->postJson("/v1/invitations/{$token}/accept")->assertUnauthorized();
});

it('signs up straight into the workspace with an invitation, verified, without a workspace of its own', function (): void {
    $owner = ownerWithWorkspace('Nour Store');
    $token = invite($owner['headers'], 'new@example.com', 'admin');

    app('auth')->forgetGuards();
    test()->flushHeaders()->postJson('/v1/auth/register', [
        'name' => 'Mona', 'email' => 'New@example.com', 'password' => 'secret12345', 'password_confirmation' => 'secret12345',
        'invitation' => $token, 'device_name' => 'test',
    ])->assertCreated()->assertJsonPath('workspace.id', $owner['workspace'])->assertJsonPath('workspace.role', 'admin');

    $user = User::query()->where('email', 'new@example.com')->sole();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and(WorkspaceMember::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(OrganizationMember::query()->where('user_id', $user->id)->count())->toBe(1);
    Notification::assertNotSentTo($user, VerifyEmail::class);
});

it('refuses sign-up with an invitation for another address, and still needs a workspace name without one', function (): void {
    $owner = ownerWithWorkspace();
    $token = invite($owner['headers'], 'new@example.com');
    $form = ['name' => 'X', 'email' => 'thief@example.com', 'password' => 'secret12345', 'password_confirmation' => 'secret12345'];

    app('auth')->forgetGuards();
    test()->flushHeaders()->postJson('/v1/auth/register', [...$form, 'invitation' => $token])->assertUnprocessable()->assertJsonValidationErrors('invitation');
    test()->postJson('/v1/auth/register', $form)->assertUnprocessable()->assertJsonValidationErrors('workspace_name');
    expect(User::query()->where('email', 'thief@example.com')->exists())->toBeFalse();
});

it('stops expired, withdrawn and replaced links', function (): void {
    $owner = ownerWithWorkspace();
    $agent = User::factory()->create(['email' => 'agent@example.com']);

    $first = invite($owner['headers'], 'agent@example.com');
    $second = invite($owner['headers'], 'agent@example.com'); // inviting again replaces the first link
    inboxAs(tokenHeaders($agent))->postJson("/v1/invitations/{$first}/accept")->assertStatus(409)->assertJsonPath('code', 'invitation-revoked');

    $id = inWs($owner['workspace'], fn () => Invitation::query()->whereNull('revoked_at')->sole()->id);
    inboxAs($owner['headers'])->deleteJson("/v1/workspace/invitations/{$id}")->assertNoContent();
    inboxAs(tokenHeaders($agent))->postJson("/v1/invitations/{$second}/accept")->assertStatus(409)->assertJsonPath('code', 'invitation-revoked');
    inboxAs($owner['headers'])->deleteJson("/v1/workspace/invitations/{$id}")->assertStatus(409);

    $third = invite($owner['headers'], 'agent@example.com');
    $this->travel(Invitations::TTL_DAYS + 1)->days();
    inboxAs(tokenHeaders($agent))->postJson("/v1/invitations/{$third}/accept")->assertStatus(409)->assertJsonPath('code', 'invitation-expired');
    test()->getJson("/v1/invitations/{$third}")->assertOk()->assertJsonPath('data.status', 'expired');
});

it('lets only people who can manage the team invite, within their own permissions', function (): void {
    $owner = ownerWithWorkspace();
    $agent = teammate($owner['workspace'], WorkspaceRole::Agent);
    $admin = teammate($owner['workspace'], WorkspaceRole::Admin);
    $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];

    inboxAs($agent['headers'] + $key())->postJson('/v1/workspace/invitations', ['email' => 'a@example.com', 'role' => 'agent'])->assertForbidden();
    inboxAs($admin['headers'] + $key())->postJson('/v1/workspace/invitations', ['email' => 'a@example.com', 'role' => 'owner'])
        ->assertForbidden()->assertJsonPath('code', 'owner-only');

    // a custom role with a permission the admin doesn't have (deleting the workspace is owners only)
    $big = inWs($owner['workspace'], fn () => CustomRole::query()->create(['name' => 'Big', 'permissions' => ['workspace.workspace.delete']]));
    inboxAs($admin['headers'] + $key())->postJson('/v1/workspace/invitations', ['email' => 'a@example.com', 'role' => 'custom', 'custom_role_id' => $big->id])
        ->assertForbidden()->assertJsonPath('code', 'beyond-own-permissions');

    inboxAs($admin['headers'] + $key())->postJson('/v1/workspace/invitations', ['email' => 'a@example.com', 'role' => 'agent'])->assertCreated();

    // an unverified owner can't send invitations from trik.chat
    $unverified = ownerWithWorkspace();
    $unverified['user']->forceFill(['email_verified_at' => null])->save();
    inboxAs($unverified['headers'] + $key())->postJson('/v1/workspace/invitations', ['email' => 'a@example.com', 'role' => 'agent'])->assertForbidden();

    // the key is required: this request sends mail
    inboxAs($owner['headers'])->postJson('/v1/workspace/invitations', ['email' => 'b@example.com', 'role' => 'agent'])->assertStatus(400);
});

it('keeps each workspace\'s invitations to itself', function (): void {
    $mine = ownerWithWorkspace();
    $theirs = ownerWithWorkspace();
    invite($theirs['headers'], 'x@example.com');
    $theirId = inWs($theirs['workspace'], fn () => Invitation::query()->sole()->id);
    $theirRole = inWs($theirs['workspace'], fn () => CustomRole::query()->create(['name' => 'R', 'permissions' => ['inbox.conversations.view']]));

    inboxAs($mine['headers'])->getJson('/v1/workspace/invitations')->assertOk()->assertJsonCount(0, 'data');
    inboxAs($mine['headers'])->deleteJson("/v1/workspace/invitations/{$theirId}")->assertNotFound();
    inboxAs($mine['headers'] + ['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/v1/workspace/invitations', ['email' => 'y@example.com', 'role' => 'custom', 'custom_role_id' => $theirRole->id])
        ->assertUnprocessable()->assertJsonValidationErrors('custom_role_id');
    expect(inWs($theirs['workspace'], fn () => Invitation::query()->sole()->revoked_at))->toBeNull();
});

it('refuses to invite a member, and voids links from someone who can no longer manage the team', function (): void {
    $owner = ownerWithWorkspace();
    $agent = teammate($owner['workspace'], WorkspaceRole::Agent);
    inboxAs($owner['headers'] + ['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/v1/workspace/invitations', ['email' => $agent['user']->email, 'role' => 'admin'])
        ->assertStatus(409)->assertJsonPath('code', 'already-a-member');

    $admin = teammate($owner['workspace'], WorkspaceRole::Admin);
    $token = invite($admin['headers'], 'late@example.com');
    inboxAs($owner['headers'])->deleteJson("/v1/workspace/members/{$admin['user']->id}")->assertNoContent();

    $late = User::factory()->create(['email' => 'late@example.com']);
    inboxAs(tokenHeaders($late))->postJson("/v1/invitations/{$token}/accept")->assertStatus(409)->assertJsonPath('code', 'invitation-revoked');
});

it('withdraws invitations that offered a deleted custom role', function (): void {
    $owner = ownerWithWorkspace();
    $role = inWs($owner['workspace'], fn () => CustomRole::query()->create(['name' => 'Packer', 'permissions' => ['orders.orders.view']]));
    $token = invite($owner['headers'], 'p@example.com', 'custom', ['custom_role_id' => $role->id]);
    test()->getJson("/v1/invitations/{$token}")->assertJsonPath('data.custom_role', 'Packer');

    inboxAs($owner['headers'])->deleteJson("/v1/workspace/roles/{$role->id}")->assertNoContent();
    test()->getJson("/v1/invitations/{$token}")->assertNotFound();
});

it('reduces names in the email to plain words', function (): void {
    expect(WorkspaceInvitationMail::plain('[Click here](https://evil.example/login) <b>x</b> ![img](x)'))
        ->not->toContain('[')->not->toContain('](')->not->toContain('<')->not->toContain('evil.example')
        ->and(WorkspaceInvitationMail::plain('متجر نور'))->toBe('متجر نور')
        ->and(WorkspaceInvitationMail::plain("Nour's Store & Co."))->toBe("Nour's Store & Co.");
});
