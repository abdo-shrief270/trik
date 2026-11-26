<?php

declare(strict_types=1);

use App\Support\Events\EventPublisher;
use App\Support\Realtime\Realtime;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Contacts\Infrastructure\Models\Contact;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Inbox\Infrastructure\Models\Conversation;
use Modules\WhatsApp\Application\ConnectNumber;
use Modules\WhatsApp\Application\Jobs\ProcessWebhookEvents;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\WhatsApp\Application\WebhookBatchProcessor;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\WebhookEvent;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;
use Modules\WhatsApp\Tests\Fixtures\CloudApi;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;
use Tests\TestCase;

/** Records what would be pushed to Centrifugo. */
final class RecordingRealtime implements Realtime
{
    /** @var list<array{channel: string, data: array<string, mixed>}> */
    public array $sent = [];

    public function publish(array $messages): void
    {
        array_push($this->sent, ...$messages);
    }
}

/** A customer message, as the provider sends it, written $minutesAgo minutes ago. */
function customerWrites(string $from = '201012345678', string $text = 'السلام عليكم', string $id = 'wamid.A1', string $pnid = 'PNID-1', int $minutesAgo = 0, bool $fromAd = false): array
{
    return CloudApi::wrap($pnid, [
        'contacts' => [['profile' => ['name' => 'Mona'], 'wa_id' => $from]],
        'messages' => [array_filter([
            'from' => $from, 'id' => $id, 'timestamp' => (string) (time() - $minutesAgo * 60),
            'type' => 'text', 'text' => ['body' => $text],
            'referral' => $fromAd ? ['source_type' => 'ad', 'source_id' => '120200000', 'ctwa_clid' => 'abc'] : null,
        ])],
    ]);
}

/** Requests as one user: forgets the previous user (the test client keeps the first one otherwise). */
function inboxAs(array $headers): TestCase
{
    app('auth')->forgetGuards();

    return test()->flushHeaders()->withHeaders($headers);
}

function deliver(WhatsAppNumber $number, array $payload): void
{
    test()->flushHeaders()->withHeader('X-Trik-Webhook-Secret', $number->webhookSecret())
        ->postJson("/webhooks/whatsapp/fake/{$number->id}", $payload)
        ->assertOk();
}

function teammate(string $workspace, WorkspaceRole $role): array
{
    $user = User::factory()->create();
    WorkspaceMember::query()->create(['workspace_id' => $workspace, 'user_id' => $user->id, 'role' => $role]);

    return ['user' => $user, 'headers' => [
        'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
        'X-Workspace-Id' => $workspace,
    ]];
}

beforeEach(function (): void {
    Queue::fake([SendOutgoingMessage::class]); // webhook draining still runs inline (sync queue)
    $this->realtime = new RecordingRealtime;
    app()->instance(Realtime::class, $this->realtime);

    $this->owner = ownerWithWorkspace('Mona Store');
    $this->ws = $this->owner['workspace'];
    $this->ctx = app(TenantContext::class);
    [$this->n1, $this->n2] = $this->ctx->run($this->ws, fn (): array => [
        app(ConnectNumber::class)->handle(Provider::Fake, 'PNID-1', ['api_key' => 'k']),
        app(ConnectNumber::class)->handle(Provider::Fake, 'PNID-2', ['api_key' => 'k']),
    ]);
});

it('gives each customer one conversation across the workspace\'s numbers', function (): void {
    deliver($this->n1, customerWrites(id: 'wamid.A1', minutesAgo: 5));
    deliver($this->n2, customerWrites(id: 'wamid.A2', text: 'فين الأوردر؟', pnid: 'PNID-2', minutesAgo: 1));
    deliver($this->n1, customerWrites(from: '201099999999', id: 'wamid.B1'));

    $list = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->assertOk()->json('data');

    expect($list)->toHaveCount(2)
        ->and($list[0]['contact']['phone'])->toBe('201099999999') // newest activity first
        ->and($list[1]['contact'])->toMatchArray(['phone' => '201012345678', 'name' => 'Mona'])
        ->and($list[1]['unread_count'])->toBe(2)
        ->and($list[1]['number_id'])->toBe($this->n2->id) // replies go from the number they last wrote to
        ->and($list[1]['last_message'])->toMatchArray(['direction' => 'in', 'type' => 'text', 'preview' => 'فين الأوردر؟'])
        ->and($list[1]['window']['open'])->toBeTrue()
        ->and($list[1]['window']['entry'])->toBeNull();

    $this->ctx->run($this->ws, fn () => expect(Contact::query()->count())->toBe(2)->and(Conversation::query()->count())->toBe(2));

    $messages = inboxAs($this->owner['headers'])->getJson("/v1/inbox/conversations/{$list[1]['id']}/messages")->assertOk()->json('data');
    expect(array_column($messages, 'content'))->toBe([
        ['text' => 'فين الأوردر؟', 'contact_name' => 'Mona'],
        ['text' => 'السلام عليكم', 'contact_name' => 'Mona'],
    ]);
});

it('replies inside the 24-hour window, marks the conversation read and records who replied', function (): void {
    deliver($this->n1, customerWrites(minutesAgo: 60));
    $c = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data.0');

    inboxAs([...$this->owner['headers'], 'Idempotency-Key' => 'r-1'])
        ->postJson("/v1/inbox/conversations/{$c['id']}/messages", ['type' => 'text', 'text' => 'أهلاً يا مونا، الأوردر في الطريق'])
        ->assertStatus(202)
        ->assertJsonPath('data.sent_by', $this->owner['user']->id)
        ->assertJsonPath('data.number_id', $this->n1->id)
        ->assertJsonPath('data.content.text.body', 'أهلاً يا مونا، الأوردر في الطريق');

    $after = inboxAs($this->owner['headers'])->getJson("/v1/inbox/conversations/{$c['id']}")->json('data');
    expect($after['unread_count'])->toBe(0)
        ->and($after['last_message'])->toMatchArray(['direction' => 'out', 'preview' => 'أهلاً يا مونا، الأوردر في الطريق'])
        ->and($after['window']['open'])->toBeTrue(); // only the customer's messages move the window
});

it('allows only templates once the customer last wrote more than 24 hours ago', function (): void {
    deliver($this->n1, customerWrites(minutesAgo: 25 * 60));
    $c = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data.0');
    expect($c['window']['open'])->toBeFalse();

    inboxAs([...$this->owner['headers'], 'Idempotency-Key' => 'w-1'])
        ->postJson("/v1/inbox/conversations/{$c['id']}/messages", ['type' => 'text', 'text' => 'hi'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'window-closed');

    inboxAs([...$this->owner['headers'], 'Idempotency-Key' => 'w-2'])
        ->postJson("/v1/inbox/conversations/{$c['id']}/messages", ['type' => 'template', 'template' => ['name' => 'order_followup', 'language' => 'ar']])
        ->assertStatus(202);
});

it('recognises customers from ads and opens the 72 free hours when the team answers in time', function (): void {
    deliver($this->n1, customerWrites(fromAd: true, minutesAgo: 30));
    $c = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data.0');
    expect($c['window'])->toMatchArray(['open' => true, 'entry' => 'ad', 'free_until' => null]);

    inboxAs([...$this->owner['headers'], 'Idempotency-Key' => 'ad-1'])
        ->postJson("/v1/inbox/conversations/{$c['id']}/messages", ['type' => 'text', 'text' => 'أهلاً بيكي'])
        ->assertStatus(202);

    $window = inboxAs($this->owner['headers'])->getJson("/v1/inbox/conversations/{$c['id']}")->json('data.window');
    expect($window['entry'])->toBe('ad')
        ->and(abs(strtotime((string) $window['free_until']) - (time() + 72 * 3600)))->toBeLessThan(60)
        // free, but free-form messages still follow the 24-hour window
        ->and(strtotime((string) $window['closes_at']))->toBeLessThan(time() + 24 * 3600);
});

it('reopens a closed conversation when the customer writes again', function (): void {
    deliver($this->n1, customerWrites(id: 'wamid.1'));
    $id = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data.0.id');

    inboxAs($this->owner['headers'])->patchJson("/v1/inbox/conversations/{$id}", ['status' => 'closed'])
        ->assertOk()->assertJsonPath('data.status', 'closed');
    expect(inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data'))->toBe([]);
    expect(inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations?status=closed')->json('data.0.id'))->toBe($id);

    deliver($this->n1, customerWrites(id: 'wamid.2', text: 'تاني'));
    inboxAs($this->owner['headers'])->getJson("/v1/inbox/conversations/{$id}")
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.closed_at', null);
});

it('lets agents take and release conversations, and only assigners give them to others', function (): void {
    deliver($this->n1, customerWrites());
    $id = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data.0.id');
    $agent = teammate($this->ws, WorkspaceRole::Agent);
    $other = teammate($this->ws, WorkspaceRole::Agent);

    inboxAs($agent['headers'])->patchJson("/v1/inbox/conversations/{$id}", ['assignee_id' => $other['user']->id])
        ->assertForbidden()->assertJsonPath('code', 'cannot-assign');
    inboxAs($agent['headers'])->patchJson("/v1/inbox/conversations/{$id}", ['assignee_id' => $agent['user']->id])
        ->assertOk()->assertJsonPath('data.assignee_id', $agent['user']->id);
    // someone else's conversation can't be taken without the permission
    inboxAs($other['headers'])->patchJson("/v1/inbox/conversations/{$id}", ['assignee_id' => $other['user']->id])
        ->assertForbidden();

    expect(inboxAs($agent['headers'])->getJson('/v1/inbox/conversations?assignee=me')->json('data.0.id'))->toBe($id)
        ->and(inboxAs($agent['headers'])->getJson('/v1/inbox/conversations?assignee=unassigned')->json('data'))->toBe([]);

    inboxAs($this->owner['headers'])->patchJson("/v1/inbox/conversations/{$id}", ['assignee_id' => $other['user']->id])
        ->assertOk()->assertJsonPath('data.assignee_id', $other['user']->id);
    // only people on the team who can see conversations
    inboxAs($this->owner['headers'])->patchJson("/v1/inbox/conversations/{$id}", ['assignee_id' => User::factory()->create()->id])
        ->assertUnprocessable()->assertJsonPath('code', 'assignee-cannot-view');
});

it('marks a conversation read and filters unread ones', function (): void {
    deliver($this->n1, customerWrites(id: 'wamid.1'));
    deliver($this->n1, customerWrites(from: '201011111111', id: 'wamid.2'));
    $id = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data.0.id');

    inboxAs($this->owner['headers'])->postJson("/v1/inbox/conversations/{$id}/read")->assertOk()->assertJsonPath('data.unread_count', 0);
    expect(inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations?unread=1')->json('data'))->toHaveCount(1);
});

it('keeps conversations inside their workspace', function (): void {
    deliver($this->n1, customerWrites());
    $id = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data.0.id');
    $stranger = ownerWithWorkspace('Other Store');

    inboxAs($stranger['headers'])->getJson('/v1/inbox/conversations')->assertOk()->assertJsonPath('data', []);
    inboxAs($stranger['headers'])->getJson("/v1/inbox/conversations/{$id}")->assertNotFound();
    inboxAs($stranger['headers'])->getJson("/v1/inbox/conversations/{$id}/messages")->assertNotFound();
    inboxAs([...$stranger['headers'], 'Idempotency-Key' => 'x'])->postJson("/v1/inbox/conversations/{$id}/messages", ['type' => 'text', 'text' => 'x'])->assertNotFound();
    inboxAs($stranger['headers'])->patchJson("/v1/inbox/conversations/{$id}", ['status' => 'closed'])->assertNotFound();

    // and at the database level, without the application's scope
    $this->ctx->run($stranger['workspace'], fn () => expect(DB::table('inbox_conversations')->count())->toBe(0)->and(DB::table('contacts_contacts')->count())->toBe(0));
});

it('stays behind the feature flag until it is rolled out to a workspace', function (): void {
    config(['trik.features.inbox' => 'none']);
    inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->assertNotFound();

    config(['trik.features.inbox' => '00000000-0000-0000-0000-000000000000, '.$this->ws]);
    inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->assertOk();
});

it('updates conversations for a backlog of customers with a fixed number of queries', function (): void {
    $this->ctx->run($this->ws, function (): void {
        foreach (range(1, 120) as $i) {
            WebhookEvent::query()->create(['number_id' => $this->n1->id, 'provider' => Provider::Fake, 'payload' => customerWrites(from: '2010'.str_pad((string) ($i % 40), 8, '0', STR_PAD_LEFT), id: "wamid.L{$i}")]);
        }
    });

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    (new ProcessWebhookEvents($this->ws))->handle(app(WebhookBatchProcessor::class), app(EventPublisher::class), $this->ctx);

    expect($queries)->toBeLessThan(20);
    $this->ctx->run($this->ws, function (): void {
        expect(Conversation::query()->count())->toBe(40)
            ->and((int) Conversation::query()->sum('unread_count'))->toBe(120);
    });
    $updates = array_values(array_filter($this->realtime->sent, fn (array $m): bool => $m['data']['type'] === 'conversation.updated'));
    expect($updates)->toHaveCount(40)
        ->and($updates[0]['channel'])->toBe('inbox:'.$this->ws);
});

it('does not push anything when the batch rolls back', function (): void {
    DB::beginTransaction();
    deliver($this->n1, customerWrites());
    DB::rollBack();

    expect($this->realtime->sent)->toBe([]);
});
