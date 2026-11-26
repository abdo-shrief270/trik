<?php

declare(strict_types=1);

use App\Support\Realtime\Realtime;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Inbox\Application\BadgeCounts;
use Modules\WhatsApp\Application\ConnectNumber;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;
use Modules\Workspaces\Application\CreateWorkspace;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/** Badge rows straight from the table: non-zero counters per user (the zero uuid is the whole workspace). */
function badgeRows(string $workspace): array
{
    return app(TenantContext::class)->run($workspace, fn () => DB::table('inbox_badge_counts')
        ->where(fn ($q) => $q->where('unread', '<>', 0)->orWhere('assigned', '<>', 0))
        ->orderBy('user_id')->get(['user_id', 'unread', 'assigned'])
        ->map(fn ($r): array => (array) $r)->all());
}

function badgesIn(string $workspace, User $user): array
{
    app('auth')->forgetGuards();

    $me = test()->flushHeaders()->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)->getJson('/v1/auth/me')->assertOk();

    return collect($me->json('workspaces'))->firstWhere('id', $workspace)['badges'];
}

beforeEach(function (): void {
    Queue::fake([SendOutgoingMessage::class]);
    $this->realtime = new RecordingRealtime;
    app()->instance(Realtime::class, $this->realtime);

    $this->owner = ownerWithWorkspace('Brand A');
    $this->ws = $this->owner['workspace'];
    $this->number = app(TenantContext::class)->run($this->ws, fn (): WhatsAppNumber => app(ConnectNumber::class)->handle(Provider::Fake, 'PNID-1', ['api_key' => 'k']));
    $this->agent = teammate($this->ws, WorkspaceRole::Agent);
});

it('counts unread and assigned conversations as they change', function (): void {
    deliver($this->number, customerWrites(id: 'wamid.1'));
    deliver($this->number, customerWrites(from: '201011111111', id: 'wamid.2'));
    expect(badgesIn($this->ws, $this->agent['user']))->toBe(['unread' => 2, 'assigned_to_me' => 0]);

    $id = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data.0.id');
    inboxAs($this->agent['headers'])->patchJson("/v1/inbox/conversations/{$id}", ['assignee_id' => $this->agent['user']->id])->assertOk();
    expect(badgesIn($this->ws, $this->agent['user']))->toBe(['unread' => 2, 'assigned_to_me' => 1])
        ->and(badgesIn($this->ws, $this->owner['user']))->toBe(['unread' => 2, 'assigned_to_me' => 0]);

    inboxAs($this->agent['headers'])->postJson("/v1/inbox/conversations/{$id}/read")->assertOk();
    expect(badgesIn($this->ws, $this->agent['user']))->toBe(['unread' => 1, 'assigned_to_me' => 1]);

    inboxAs($this->agent['headers'])->patchJson("/v1/inbox/conversations/{$id}", ['status' => 'closed'])->assertOk();
    expect(badgesIn($this->ws, $this->agent['user']))->toBe(['unread' => 1, 'assigned_to_me' => 0]);

    // the customer writes again: reopened, unread, still assigned
    $phone = inboxAs($this->owner['headers'])->getJson("/v1/inbox/conversations/{$id}")->json('data.contact.phone');
    deliver($this->number, customerWrites(from: $phone, id: 'wamid.3'));
    expect(badgesIn($this->ws, $this->agent['user']))->toBe(['unread' => 2, 'assigned_to_me' => 1]);

    // a reply reads it
    inboxAs([...$this->agent['headers'], 'Idempotency-Key' => 'b-1'])->postJson("/v1/inbox/conversations/{$id}/messages", ['type' => 'text', 'text' => 'تمام'])->assertStatus(202);
    expect(badgesIn($this->ws, $this->agent['user']))->toBe(['unread' => 1, 'assigned_to_me' => 1]);
});

it('shows badges for each of the user\'s workspaces in /me, and only theirs', function (): void {
    $orgId = DB::table('workspaces')->where('id', $this->ws)->value('organization_id');
    $b = app(CreateWorkspace::class)->handle('Brand B', $this->owner['user']->id, (string) $orgId);
    $c = app(CreateWorkspace::class)->handle('Brand C', $this->owner['user']->id, (string) $orgId);
    WorkspaceMember::query()->create(['workspace_id' => $b->id, 'user_id' => $this->agent['user']->id, 'role' => WorkspaceRole::Agent]);
    $nb = app(TenantContext::class)->run($b->id, fn (): WhatsAppNumber => app(ConnectNumber::class)->handle(Provider::Fake, 'PNID-B', ['api_key' => 'k']));
    $nc = app(TenantContext::class)->run($c->id, fn (): WhatsAppNumber => app(ConnectNumber::class)->handle(Provider::Fake, 'PNID-C', ['api_key' => 'k']));

    deliver($this->number, customerWrites(id: 'wamid.a'));
    deliver($nb, customerWrites(id: 'wamid.b1', pnid: 'PNID-B'));
    deliver($nb, customerWrites(from: '201022222222', id: 'wamid.b2', pnid: 'PNID-B'));
    deliver($nc, customerWrites(id: 'wamid.c', pnid: 'PNID-C'));

    app('auth')->forgetGuards();
    $me = $this->flushHeaders()->withHeader('Authorization', 'Bearer '.$this->agent['user']->createToken('t')->plainTextToken)->getJson('/v1/auth/me')->assertOk();

    $byId = collect($me->json('workspaces'))->keyBy('id');
    expect($byId->keys()->sort()->values()->all())->toBe(collect([$this->ws, $b->id])->sort()->values()->all()) // not Brand C
        ->and($byId[$this->ws]['badges'])->toBe(['unread' => 1, 'assigned_to_me' => 0])
        ->and($byId[$b->id]['badges'])->toBe(['unread' => 2, 'assigned_to_me' => 0]);

    // the owner sees all three, grouped under the organization
    app('auth')->forgetGuards();
    $org = $this->flushHeaders()->withHeaders($this->owner['headers'])->getJson('/v1/auth/me')->json('organizations.0.workspaces');
    expect(collect($org)->pluck('badges.unread', 'id')->all())->toBe([$this->ws => 1, $b->id => 2, $c->id => 1]);
});

it('pushes the new badges to every member on their own channel', function (): void {
    deliver($this->number, customerWrites());

    $pushed = collect($this->realtime->sent)->where('data.type', 'badges.updated')->keyBy('channel');
    expect($pushed->keys()->sort()->values()->all())->toBe(collect(['user:'.$this->owner['user']->id, 'user:'.$this->agent['user']->id])->sort()->values()->all())
        ->and($pushed['user:'.$this->agent['user']->id]['data'])->toBe(['type' => 'badges.updated', 'workspace_id' => $this->ws, 'unread' => 1, 'assigned_to_me' => 0]);
});

it('keeps the counters equal to a full recount through every kind of change', function (): void {
    $people = [$this->owner['user']->id, $this->agent['user']->id, teammate($this->ws, WorkspaceRole::Agent)['user']->id];

    foreach (range(1, 60) as $i) {
        deliver($this->number, customerWrites(from: '2010'.str_pad((string) ($i % 15), 8, '0', STR_PAD_LEFT), id: "wamid.R{$i}"));
    }

    mt_srand(42);
    app(TenantContext::class)->run($this->ws, function () use ($people): void {
        foreach (range(1, 80) as $_) {
            $id = DB::table('inbox_conversations')->inRandomOrder()->value('id');
            match (mt_rand(0, 3)) {
                0 => DB::table('inbox_conversations')->where('id', $id)->update(['assignee_id' => mt_rand(0, 3) === 0 ? null : $people[array_rand($people)]]),
                1 => DB::table('inbox_conversations')->where('id', $id)->update(['status' => mt_rand(0, 1) === 1 ? 'open' : 'closed']),
                2 => DB::table('inbox_conversations')->where('id', $id)->update(['unread_count' => mt_rand(0, 3)]),
                default => DB::table('inbox_conversations')->whereIn('id', DB::table('inbox_conversations')->limit(5)->pluck('id'))->update(['unread_count' => DB::raw('unread_count + 1')]),
            };
        }
        // a contact deleted (cascades to the conversation)
        DB::table('contacts_contacts')->where('id', DB::table('inbox_conversations')->value('contact_id'))->delete();
    });

    $maintained = badgeRows($this->ws);
    // the same rebuild the nightly command runs, on the test's connection (its data is not committed)
    app(TenantContext::class)->run($this->ws, fn () => BadgeCounts::recount($this->ws, 'pgsql'));

    expect($maintained)->not->toBe([])->and($maintained)->toBe(badgeRows($this->ws));
});

it('answers /me quickly for someone in 20 workspaces', function (): void {
    $user = $this->owner['user'];
    $orgId = (string) DB::table('workspaces')->where('id', $this->ws)->value('organization_id');
    config(['trik.max_workspaces_per_organization' => 50]);
    foreach (range(1, 19) as $i) {
        app(CreateWorkspace::class)->handle("Brand {$i}", $user->id, $orgId);
    }
    $token = $user->createToken('t')->plainTextToken;

    $times = [];
    foreach (range(1, 21) as $i) {
        app('auth')->forgetGuards();
        $t = hrtime(true);
        $this->flushHeaders()->withHeader('Authorization', 'Bearer '.$token)->getJson('/v1/auth/me')->assertOk()->assertJsonCount(20, 'workspaces');
        $times[] = (hrtime(true) - $t) / 1e6;
    }
    sort($times);

    expect($times[19])->toBeLessThan(100.0); // p95, in milliseconds
});

it('rebuilds the counters with the nightly command', function (): void {
    $this->artisan('inbox:recount-badges')->assertSuccessful();
});
