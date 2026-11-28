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
 * @property array<string, bool> $settings see SETTINGS
 * @property Carbon $created_at
 */
final class Store extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'commerce_stores';

    protected $fillable = ['platform', 'shop_domain', 'name', 'access_token', 'scopes', 'status', 'installed_by', 'installed_at', 'uninstalled_at', 'backfill', 'last_webhook_at', 'settings'];

    protected $hidden = ['access_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => StoreStatus::class,
            'access_token' => 'encrypted',
            'backfill' => 'array',
            'settings' => 'array',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }

    /**
     * What Trik writes back to the store, and the defaults. Cancelling in the store is opt-in: it
     * restocks the items there, which a seller should choose knowingly.
     */
    public const SETTINGS = [
        'tag_orders' => true,      // tag orders trik-confirmed / trik-cancelled
        'write_address' => true,   // send an address fixed in Trik to the store
        'cancel_in_store' => false, // cancel (and restock) the order in the store when it's cancelled in Trik
    ];

    public function setting(string $key): bool
    {
        return (bool) ($this->settings[$key] ?? self::SETTINGS[$key] ?? false);
    }

    /** @return array<string, bool> */
    public function effectiveSettings(): array
    {
        $out = [];
        foreach (array_keys(self::SETTINGS) as $key) {
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
