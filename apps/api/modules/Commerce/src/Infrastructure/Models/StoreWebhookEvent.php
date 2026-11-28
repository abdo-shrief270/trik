<?php

declare(strict_types=1);

namespace Modules\Commerce\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One raw store delivery, stored before any processing.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $store_id
 * @property string $topic e.g. orders/create
 * @property string $delivery_id the platform's delivery id (X-Shopify-Webhook-Id): retries reuse it
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $outcome recorded, stale, skipped:<reason>, ignored
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 */
final class StoreWebhookEvent extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'commerce_webhook_events';

    protected $fillable = ['store_id', 'topic', 'delivery_id', 'payload', 'attempts', 'last_error', 'outcome', 'processed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
