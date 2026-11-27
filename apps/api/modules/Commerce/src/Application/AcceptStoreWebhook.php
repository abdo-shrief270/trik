<?php

declare(strict_types=1);

namespace Modules\Commerce\Application;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Application\Jobs\ProcessStoreWebhooks;
use Throwable;

/**
 * The fast path of a store webhook: store it once and answer. Shopify retries a delivery with the
 * same X-Shopify-Webhook-Id; the unique (store, delivery) key turns the retry into a no-op, so a
 * delivery is processed once however often it arrives. Must run inside the store's workspace.
 */
final class AcceptStoreWebhook
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return bool false when this delivery was already stored
     */
    public function handle(string $storeId, string $topic, string $deliveryId, array $payload): bool
    {
        $workspaceId = $this->tenant->require();

        $stored = DB::table('commerce_webhook_events')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspaceId,
            'store_id' => $storeId,
            'topic' => $topic,
            'delivery_id' => Str::limit($deliveryId, 128, ''),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]) === 1;

        // at most one write a minute per store for "last heard from"
        DB::table('commerce_stores')->where('id', $storeId)
            ->where(fn ($q) => $q->whereNull('last_webhook_at')->orWhere('last_webhook_at', '<', now()->subMinute()))
            ->update(['last_webhook_at' => now()]);

        if ($stored) {
            // stored already: if the queue is down, `commerce:sweep` wakes the drain within a minute
            try {
                ProcessStoreWebhooks::dispatch($workspaceId);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $stored;
    }
}
