<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\WhatsApp\Application\ConnectNumber;
use Modules\WhatsApp\Application\Jobs\ProcessWebhookEvents;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\WebhookEvent;
use Modules\WhatsApp\Tests\Fixtures\CloudApi;

/*
 * The sweeper works across workspaces on the owner connection, so its fixtures are committed
 * through that connection (the test's own transaction is invisible to it) and removed afterwards.
 */
beforeEach(function (): void {
    $o = DB::connection('owner');
    $this->ids = ['user' => (string) Str::uuid7(), 'ws' => (string) Str::uuid7(), 'number' => (string) Str::uuid7()];
    $o->table('users')->insert(['id' => $this->ids['user'], 'name' => 'Sweep', 'email' => $this->ids['user'].'@probe.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $o->table('workspaces')->insert(['id' => $this->ids['ws'], 'name' => 'Sweep', 'slug' => 'sweep-'.$this->ids['ws'], 'owner_id' => $this->ids['user'], 'created_at' => now(), 'updated_at' => now()]);
    $o->table('whatsapp_numbers')->insert(['id' => $this->ids['number'], 'workspace_id' => $this->ids['ws'], 'provider' => 'fake', 'external_id' => 'sweep-'.$this->ids['number'], 'credentials' => encrypt([]), 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(function (): void {
    DB::connection('owner')->table('workspaces')->where('id', $this->ids['ws'])->delete(); // cascades
    DB::connection('owner')->table('users')->where('id', $this->ids['user'])->delete();
});

it('wakes the drain job for deliveries that were stored but never queued', function (): void {
    Queue::fake();
    DB::connection('owner')->table('whatsapp_webhook_events')->insert([
        'id' => (string) Str::uuid7(), 'workspace_id' => $this->ids['ws'], 'number_id' => $this->ids['number'],
        'provider' => 'fake', 'payload' => '{}', 'created_at' => now()->subMinutes(5),
    ]);

    $this->artisan('whatsapp:sweep')->assertSuccessful();

    Queue::assertPushed(ProcessWebhookEvents::class, fn (ProcessWebhookEvents $job) => $job->workspaceId === $this->ids['ws']);
});

it('marks messages that were never sent as failed instead of sending them late', function (): void {
    $id = (string) Str::uuid7();
    DB::connection('owner')->table('whatsapp_messages')->insert([
        'id' => $id, 'workspace_id' => $this->ids['ws'], 'number_id' => $this->ids['number'], 'direction' => 'out',
        'contact_phone' => '201012345678', 'type' => 'text', 'body' => '{}', 'status' => 'queued',
        'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
    ]);

    $this->artisan('whatsapp:sweep')->assertSuccessful();

    $row = DB::connection('owner')->table('whatsapp_messages')->where('id', $id)->first();
    expect($row->status)->toBe('failed')->and(json_decode($row->error, true)['code'])->toBe('dispatch-lost');
});

it('still answers 200 when the queue is down, because the delivery is stored', function (): void {
    $owner = ownerWithWorkspace();
    $number = app(TenantContext::class)->run($owner['workspace'], fn () => app(ConnectNumber::class)
        ->handle(Provider::Fake, 'down-1', ['api_key' => 'k']));
    config(['queue.default' => 'broken']);
    config(['queue.connections.broken' => ['driver' => 'redis', 'connection' => 'missing', 'queue' => 'default']]);

    $this->withHeader('X-Trik-Webhook-Secret', $number->webhookSecret())
        ->postJson("/webhooks/whatsapp/fake/{$number->id}", CloudApi::inboundText())
        ->assertOk();

    expect(app(TenantContext::class)->run($owner['workspace'], fn () => WebhookEvent::query()->count()))->toBe(1);
});
