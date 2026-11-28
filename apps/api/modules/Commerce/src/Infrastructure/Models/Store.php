<?php

declare(strict_types=1);

namespace Modules\Commerce\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Commerce\Domain\Platform;
use Modules\Commerce\Domain\StoreStatus;

/**
 * A connected store.
 *
 * @property string $id
 * @property string $workspace_id
 * @property Platform $platform
 * @property string $shop_domain
 * @property string|null $name
 * @property string|null $access_token encrypted at rest, never serialised
 * @property string $scopes comma-separated, as granted
 * @property StoreStatus $status
 * @property string|null $installed_by
 * @property Carbon|null $installed_at
 * @property Carbon|null $uninstalled_at
 * @property array<string, mixed> $backfill status, operation, since, count, skipped, error, started_at, finished_at
 * @property Carbon|null $last_webhook_at
 * @property array<string, bool> $settings see settingDefaults()
 * @property string|null $webhook_token_hash SHA-256 of the token in the store's webhook URL (EasyOrders)
 * @property string|null $webhook_secret encrypted; the `secret` header of a hand-made EasyOrders webhook
 * @property Carbon $created_at
 */
final class Store extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'commerce_stores';

    protected $fillable = ['platform', 'shop_domain', 'name', 'access_token', 'scopes', 'status', 'installed_by', 'installed_at', 'uninstalled_at', 'backfill', 'last_webhook_at', 'settings', 'webhook_token_hash', 'webhook_secret'];

    protected $hidden = ['access_token', 'webhook_token_hash', 'webhook_secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => StoreStatus::class,
            'access_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'backfill' => 'array',
            'settings' => 'array',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }

    /**
     * What Trik writes back to a store, per platform, and the defaults. Cancelling in Shopify is opt-in:
     * it restocks the items there, which a seller should choose knowingly.
     */
    public const SETTINGS = [
        'shopify' => [
            'tag_orders' => true,       // tag orders trik-confirmed / trik-cancelled
            'write_address' => true,    // send an address fixed in Trik as the shipping address
            'cancel_in_store' => false, // cancel (and restock) the order in Shopify when it's cancelled in Trik
        ],
        'easyorders' => [
            'write_status' => true,     // set the order's status to confirmed / canceled
            'write_address' => true,    // add a private note with the fixed address (the API can't edit addresses)
        ],
    ];

    /** @return array<string, bool> */
    public function settingDefaults(): array
    {
        return self::SETTINGS[$this->platform->value] ?? [];
    }

    public function setting(string $key): bool
    {
        return (bool) ($this->settings[$key] ?? $this->settingDefaults()[$key] ?? false);
    }

    /** @return array<string, bool> */
    public function effectiveSettings(): array
    {
        $out = [];
        foreach (array_keys($this->settingDefaults()) as $key) {
            $out[$key] = $this->setting($key);
        }

        return $out;
    }

    /** @return list<string> */
    public function grantedScopes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->scopes))));
    }
}
