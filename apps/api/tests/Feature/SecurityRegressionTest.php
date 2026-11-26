<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveWorkspace;
use App\Support\Authorization\PermissionGate;
use App\Support\Http\Idempotency;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Models\User;
use Modules\WhatsApp\Application\ConnectNumber;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Http\Controllers\WebhookController;
use Modules\WhatsApp\Tests\Fixtures\CloudApi;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\CustomRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/*
 * One test per finding of docs/security/2026-11-review.md, so none can come back.
 */

function headersFor(User $user, string $workspace): array
{
    app('auth')->forgetGuards();
    app(PermissionGate::class)->forget();

    return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken, ResolveWorkspace::HEADER => $workspace];
}

function addMember(string $workspace, WorkspaceRole $role, ?string $customRole = null, bool $verified = true): User
{
    $user = $verified ? User::factory()->create() : User::factory()->unverified()->create();
    WorkspaceMember::query()->create(['workspace_id' => $workspace, 'user_id' => $user->id, 'role' => $role, 'custom_role_id' => $customRole]);

    return $user;
}

beforeEach(function (): void {
    $this->owner = ownerWithWorkspace();
    $this->ws = $this->owner['workspace'];
});

it('F-01: a role editor cannot give their own role, or anyone, more than they have', function (): void {
    $roleId = $this->flushHeaders()->withHeaders($this->owner['headers'])
        ->postJson('/v1/workspace/roles', ['name' => 'Role editors', 'permissions' => ['workspace.roles.manage', 'workspace.members.view']])
        ->json('data.id');
    $editor = addMember($this->ws, WorkspaceRole::Custom, $roleId);
    $h = headersFor($editor, $this->ws);

    $this->flushHeaders()->withHeaders($h)->patchJson("/v1/workspace/roles/{$roleId}", ['permissions' => ['workspace.roles.manage', 'whatsapp.numbers.manage']])
        ->assertForbidden()->assertJsonPath('code', 'cannot-edit-own-role');

    $this->flushHeaders()->withHeaders($h)->postJson('/v1/workspace/roles', ['name' => 'Sneaky', 'permissions' => ['whatsapp.numbers.manage']])
        ->assertForbidden()->assertJsonPath('code', 'beyond-own-permissions');

    $this->flushHeaders()->withHeaders($h)->postJson('/v1/workspace/roles', ['name' => 'Fine', 'permissions' => ['workspace.members.view']])
        ->assertCreated();
});

it('F-01: a member manager cannot hand out or touch roles above their own', function (): void {
    $managerRole = CustomRole::query()->getModel()->newQuery();
    $roleId = $this->flushHeaders()->withHeaders($this->owner['headers'])
        ->postJson('/v1/workspace/roles', ['name' => 'Team leads', 'permissions' => ['workspace.members.*', 'whatsapp.messages.*']])
        ->json('data.id');
    $lead = addMember($this->ws, WorkspaceRole::Custom, $roleId);
    $agent = addMember($this->ws, WorkspaceRole::Agent);
    $admin = addMember($this->ws, WorkspaceRole::Admin);
    $h = headersFor($lead, $this->ws);

    $this->flushHeaders()->withHeaders($h)->patchJson("/v1/workspace/members/{$agent->id}", ['role' => 'admin'])
        ->assertForbidden()->assertJsonPath('code', 'beyond-own-permissions');
    $this->flushHeaders()->withHeaders($h)->patchJson("/v1/workspace/members/{$admin->id}", ['role' => 'agent'])
        ->assertForbidden()->assertJsonPath('code', 'beyond-own-permissions');
    $this->flushHeaders()->withHeaders($h)->deleteJson("/v1/workspace/members/{$admin->id}")
        ->assertForbidden()->assertJsonPath('code', 'beyond-own-permissions');
});

it('F-02: an unverified email cannot use WhatsApp features', function (): void {
    $user = addMember($this->ws, WorkspaceRole::Admin, verified: false);

    $this->flushHeaders()->withHeaders(headersFor($user, $this->ws))
        ->postJson('/v1/whatsapp/numbers', ['provider' => 'fake', 'external_id' => 'x1', 'credentials' => ['api_key' => 'k']])
        ->assertForbidden()->assertJsonPath('code', 'email-not-verified');
});

it('F-03: sign-in responses are never cached by Idempotency-Key', function (): void {
    $user = User::factory()->create(['email' => 'cache@probe.test']);
    $body = ['email' => 'cache@probe.test', 'password' => 'password', 'device_name' => 'p'];

    $first = $this->flushHeaders()->withHeader(Idempotency::HEADER, 'k')->postJson('/v1/auth/login', $body)->assertOk();
    $second = $this->withHeader(Idempotency::HEADER, 'k')->postJson('/v1/auth/login', $body)->assertOk();

    expect($second->headers->get(Idempotency::REPLAYED_HEADER))->toBeNull()
        ->and($second->json('token'))->not->toBe($first->json('token'))
        ->and($user->tokens()->count())->toBe(2);
});

it('F-04: oversized bodies are refused before any work', function (): void {
    config(['trik.max_body_bytes.webhooks' => 1024]);

    $this->call('POST', '/webhooks/whatsapp/fake/'.str()->uuid(), [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('a', 2048))
        ->assertStatus(413)->assertJsonPath('code', 'payload-too-large');
});

it('F-05: an address flooding bad webhooks is throttled, valid traffic is not counted', function (): void {
    foreach (range(1, WebhookController::UNKNOWN_PER_MINUTE) as $_) {
        $this->postJson('/webhooks/whatsapp/fake/'.str()->uuid(), [])->assertNotFound();
    }

    $this->postJson('/webhooks/whatsapp/fake/'.str()->uuid(), [])->assertStatus(429)->assertHeader('Retry-After');
    RateLimiter::clear('webhook-failures:ip:127.0.0.1');
});

it('F-05: one number with a wrong secret never blocks other numbers on the same provider IP', function (): void {
    $connect = fn (string $ext) => app(TenantContext::class)->run($this->ws, fn () => app(ConnectNumber::class)
        ->handle(Provider::Fake, $ext, ['api_key' => 'k']));
    $broken = $connect('broken');
    $healthy = $connect('healthy');

    foreach (range(1, WebhookController::FAILURES_PER_MINUTE + 5) as $_) {
        $this->withHeader('X-Trik-Webhook-Secret', 'old-secret')->postJson("/webhooks/whatsapp/fake/{$broken->id}", []);
    }

    $this->withHeader('X-Trik-Webhook-Secret', 'old-secret')->postJson("/webhooks/whatsapp/fake/{$broken->id}", [])->assertStatus(429);
    $this->withHeader('X-Trik-Webhook-Secret', $healthy->webhookSecret())
        ->postJson("/webhooks/whatsapp/fake/{$healthy->id}", CloudApi::inboundText())
        ->assertOk();
});

it('F-06: provider ids are validated before they reach provider URLs', function (): void {
    $this->flushHeaders()->withHeaders($this->owner['headers'])->postJson('/v1/whatsapp/numbers', [
        'provider' => 'meta_cloud', 'external_id' => '123/../../me?fields=x', 'waba_id' => '1234567', 'credentials' => ['access_token' => 't'],
    ])->assertUnprocessable()->assertJsonValidationErrors('external_id');

    $this->postJson('/v1/whatsapp/numbers', [
        'provider' => '360dialog', 'external_id' => 'chan nel/../x', 'credentials' => ['api_key' => 'k'],
    ])->assertUnprocessable()->assertJsonValidationErrors('external_id');

    Http::assertNothingSent();
});

it('F-07: CORS allows only the web app, with credentials', function (): void {
    $evil = $this->call('OPTIONS', '/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
    expect($evil->headers->get('Access-Control-Allow-Origin'))->not->toBeIn(['*', 'https://evil.example']);

    $this->call('OPTIONS', '/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('F-08: tokens expire and a password reset signs out browsers too', function (): void {
    expect(config('sanctum.expiration'))->toBeGreaterThan(0);

    $user = User::factory()->create(['email' => 'reset@probe.test']);
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/v1/auth/reset-password', [
        'token' => $token, 'email' => 'reset@probe.test', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
    ])->assertOk();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('F-09: one account is locked after 20 failures from any mix of addresses', function (): void {
    User::factory()->create(['email' => 'target@probe.test']);
    RateLimiter::increment('login-account:'.sha1('target@probe.test'), 3600, 20);

    $this->postJson('/v1/auth/login', ['email' => 'target@probe.test', 'password' => 'password', 'device_name' => 'p'])
        ->assertStatus(429);
});

it('F-09: unknown accounts still cost a password hash (no timing oracle)', function (): void {
    Hash::shouldReceive('make')->andReturn('$2y$04$dummydummydummydummydu');
    Hash::shouldReceive('check')->once()->andReturnFalse();

    $this->postJson('/v1/auth/login', ['email' => 'nobody@probe.test', 'password' => 'x', 'device_name' => 'p'])
        ->assertUnprocessable();
});

it('F-10: a bad contact filter answers 422, not 500', function (): void {
    $number = $this->flushHeaders()->withHeaders($this->owner['headers'])
        ->postJson('/v1/whatsapp/numbers', ['provider' => 'fake', 'external_id' => 'f10', 'credentials' => ['api_key' => 'k']])->json('data.id');

    $this->getJson("/v1/whatsapp/numbers/{$number}/messages?contact=1'or'1'='1")->assertUnprocessable()->assertJsonValidationErrors('contact');
});

it('F-11: security headers on every response', function (): void {
    $this->getJson('/healthz')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

it('F-12: the app role cannot touch the migrations table', function (): void {
    expect(DB::scalar("select has_table_privilege(current_user, 'migrations', 'select')"))->toBeFalse();
});

it('F-13: a user owns a limited number of workspaces', function (): void {
    config(['trik.max_owned_workspaces' => 1]);

    $this->flushHeaders()->withHeaders($this->owner['headers'])->postJson('/v1/workspaces', ['name' => 'Second'])
        ->assertForbidden()->assertJsonPath('code', 'workspace-limit');
});

it('R2-01: behind the proxy, rate limits see the real client IP; direct clients cannot fake it', function (): void {
    Route::get('/v1/_ip', fn (Request $r) => ['ip' => $r->ip(), 'secure' => $r->isSecure()]);

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '41.33.1.2', 'HTTP_X_FORWARDED_PROTO' => 'https'])
        ->getJson('/v1/_ip')->assertJson(['ip' => '41.33.1.2', 'secure' => true]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '41.33.1.2'])
        ->getJson('/v1/_ip')->assertJson(['ip' => '203.0.113.9']);
});

it('R2-02: readiness checks are throttled', function (): void {
    foreach (range(1, 60) as $_) {
        $this->getJson('/readyz');
    }

    $this->getJson('/readyz')->assertStatus(429);
});

it('R2-03: a crafted pagination cursor answers 400, not 500', function (): void {
    $number = $this->flushHeaders()->withHeaders($this->owner['headers'])
        ->postJson('/v1/whatsapp/numbers', ['provider' => 'fake', 'external_id' => 'r203', 'credentials' => ['api_key' => 'k']])->json('data.id');
    $evil = rtrim(strtr(base64_encode(json_encode(['created_at' => "x' or 1=1", 'id' => 'nope', '_pointsToNextItems' => true])), '+/', '-_'), '=');

    $this->getJson("/v1/whatsapp/numbers/{$number}/messages?cursor={$evil}")->assertStatus(400)->assertJsonPath('code', 'invalid-cursor');
    $this->getJson('/v1/workspace/members?cursor=not-base64-at-all')->assertStatus(400);
});

it('R2-04: method spoofing is refused', function (): void {
    $this->flushHeaders()->withHeaders([...$this->owner['headers'], 'X-HTTP-Method-Override' => 'DELETE'])
        ->postJson('/v1/workspaces', ['name' => 'x'])->assertStatus(400)->assertJsonPath('code', 'method-override-not-allowed');
    $this->flushHeaders()->withHeaders($this->owner['headers'])
        ->postJson('/v1/workspaces', ['name' => 'x', '_method' => 'DELETE'])->assertStatus(400);
});
