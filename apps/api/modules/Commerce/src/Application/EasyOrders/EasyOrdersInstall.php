<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\EasyOrders;

use App\Support\Authorization\PermissionRegistry;
use App\Support\Authorization\WorkspacePermissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Application\Shopify\InstallFailed;
use Modules\Commerce\Application\Shopify\ShopifyInstall;
use Modules\Commerce\Domain\Platform;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Commerce\Infrastructure\StoreRouter;

/**
 * Connecting an EasyOrders store through its "authorized app link": the seller accepts Trik on EasyOrders,
 * which creates an API key and the order webhooks, then POSTs {api_key, store_id} to our callback.
 *
 * EasyOrders signs neither the callback nor the webhooks, so both URLs carry our own secrets:
 *  - the callback URL a single-use token (30 minutes) that remembers the workspace and the member;
 *  - the webhook URL the store's own token, of which only the SHA-256 is kept.
 * The callback still re-checks the member's permission and that the store isn't connected elsewhere.
 */
final class EasyOrdersInstall
{
    public function __construct(
        private readonly StoreRouter $router,
        private readonly TenantContext $tenant,
        private readonly WorkspacePermissions $permissions,
        private readonly PermissionRegistry $registry,
    ) {}

    public function start(string $workspaceId, string $userId): string
    {
        $token = Str::random(48);
        $hook = Str::random(48);
        Cache::put(self::key($token), ['workspace_id' => $workspaceId, 'user_id' => $userId, 'hook' => $hook], (int) config('commerce.easyorders.install_ttl_seconds', 1800));

        $base = ShopifyInstall::publicUrl();
        $webhook = "{$base}/webhooks/easyorders/{$hook}";
        $query = array_filter([
            'app_name' => (string) config('commerce.easyorders.app_name'),
            'app_description' => (string) config('commerce.easyorders.app_description'),
            'app_icon' => (string) (config('commerce.easyorders.app_icon') ?: rtrim((string) config('app.frontend_url'), '/').'/icon-512.png'),
            'permissions' => (string) config('commerce.easyorders.permissions'),
            'callback_url' => "{$base}/webhooks/easyorders/install/{$token}",
            'orders_webhook' => $webhook,
            'order_status_webhook' => $webhook,
            'redirect_url' => rtrim((string) config('app.frontend_url'), '/').'/settings/stores?easyorders=connected',
        ]);

        return (string) config('commerce.easyorders.install_url').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, mixed>  $body  EasyOrders' callback: {api_key, store_id}
     *
     * @throws InstallFailed
     */
    public function finish(string $token, array $body): Store
    {
        $pending = strlen($token) === 48 ? Cache::pull(self::key($token)) : null; // single use
        if (! is_array($pending)) {
            throw new InstallFailed('expired');
        }

        $apiKey = is_string($body['api_key'] ?? null) ? trim($body['api_key']) : '';
        $storeId = is_string($body['store_id'] ?? null) ? strtolower(trim($body['store_id'])) : '';
        if ($apiKey === '' || strlen($apiKey) > 512 || ! Str::isUuid($storeId)) {
            throw new InstallFailed('invalid-callback');
        }

        $workspaceId = (string) $pending['workspace_id'];
        $userId = (string) $pending['user_id'];
        $access = $this->permissions->access($workspaceId, $userId);
        if ($access === null || ! $this->registry->allows($access->grants, ShopifyInstall::PERMISSION)) {
            throw new InstallFailed('forbidden');
        }

        $route = $this->router->byShop(Platform::EasyOrders, $storeId);
        if ($route !== null && $route['workspace_id'] !== $workspaceId) {
            throw new InstallFailed('store-connected-elsewhere');
        }

        return $this->tenant->run($workspaceId, function () use ($storeId, $apiKey, $userId, $pending): Store {
            try {
                return DB::transaction(function () use ($storeId, $apiKey, $userId, $pending): Store {
                    /** @var Store $store */
                    $store = Store::query()->firstOrNew(['platform' => Platform::EasyOrders, 'shop_domain' => $storeId]);
                    $store->fill([
                        'access_token' => $apiKey,
                        'scopes' => (string) config('commerce.easyorders.permissions'),
                        'status' => StoreStatus::Active,
                        'installed_by' => $userId,
                        'installed_at' => now(),
                        'uninstalled_at' => null,
                        'webhook_token_hash' => hash('sha256', (string) $pending['hook']),
                        'webhook_secret' => null,
                        // EasyOrders has no way to list orders: there is nothing to backfill
                        'backfill' => ['status' => 'unsupported'],
                    ])->save();

                    return $store;
                });
            } catch (UniqueConstraintViolationException) {
                throw new InstallFailed('store-connected-elsewhere');
            }
        });
    }

    private static function key(string $token): string
    {
        return 'commerce:easyorders:install:'.hash('sha256', $token);
    }
}
