<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveWorkspace;
use App\Support\Realtime\Realtime;
use Modules\Identity\Infrastructure\Models\User;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;
use Modules\WhatsApp\Tests\Fixtures\CloudApi;
use Modules\Workspaces\Application\CreateWorkspace;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;
use Tests\TestCase;

/** Creates a user and returns its id. */
function userId(): string
{
    return User::factory()->create()->id;
}

/**
 * A signed-in owner with a fresh workspace.
 *
 * @return array{user: User, workspace: string, headers: array<string, string>}
 */
function ownerWithWorkspace(string $name = 'Test Store'): array
{
    $user = User::factory()->create();
    $workspace = app(CreateWorkspace::class)->forUser($name, $user->id);

    return [
        'user' => $user,
        'workspace' => $workspace->id,
        'headers' => [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            ResolveWorkspace::HEADER => $workspace->id,
        ],
    ];
}

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
