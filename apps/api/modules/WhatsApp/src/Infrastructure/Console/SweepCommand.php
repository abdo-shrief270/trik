<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Console;

use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\WhatsApp\Application\Jobs\ProcessWebhookEvents;

/**
 * Every minute (owner connection, across workspaces):
 *  - wakes the drain job for workspaces with deliveries waiting over a minute (a lost queue
 *    push, Redis restart or crash never strands a stored webhook);
 *  - marks outbound messages still queued after 2 hours (broadcast sends after 13 hours) as
 *    failed (`dispatch-lost`), so the seller sees them and can resend; never re-sends
 *    automatically (no duplicates).
 */
final class SweepCommand extends Command
{
    protected $signature = 'whatsapp:sweep';

    protected $description = 'Recover stranded webhook deliveries and surface messages that were never sent';

    public function handle(TenantContext $context): int
    {
        $owner = DB::connection('owner');

        $workspaces = $owner->table('whatsapp_webhook_events')
            ->whereNull('processed_at')
            ->where('attempts', '<', ProcessWebhookEvents::MAX_ATTEMPTS)
            ->where('created_at', '<', now()->subMinute())
            ->where('created_at', '>', now()->subDays(7))
            ->distinct()
            ->pluck('workspace_id');

        foreach ($workspaces as $workspaceId) {
            $context->run((string) $workspaceId, fn () => ProcessWebhookEvents::dispatch((string) $workspaceId));
        }

        $lost = $owner->table('whatsapp_messages')
            ->where('direction', 'out')
            ->where('status', 'queued')
            ->where('created_at', '<', now()->subHours(2))
            ->where('created_at', '>', now()->subDays(2))
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error' => json_encode(['code' => 'dispatch-lost', 'message' => 'Not sent: the send job never ran. Resend if still needed.']),
                'updated_at' => now(),
            ]);

        // Broadcast chunks retry for up to 12 hours (SendBroadcastChunk::retryUntil); after that a
        // still-queued send was never going to go out.
        $lost += $owner->table('whatsapp_broadcast_sends')
            ->where('status', 'queued')
            ->where('created_at', '<', now()->subHours(13))
            ->where('created_at', '>', now()->subDays(3))
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error' => json_encode(['code' => 'dispatch-lost', 'message' => 'Not sent within the broadcast window.']),
                'updated_at' => now(),
            ]);

        $this->info(count($workspaces).' workspaces woken, '.$lost.' messages marked failed');

        return self::SUCCESS;
    }
}
