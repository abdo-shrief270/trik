<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Shopify;

use App\Support\Authorization\PermissionRegistry;
use App\Support\Authorization\WorkspacePermissions;
use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Commerce\Application\Jobs\BackfillShopifyOrders;
use Modules\Commerce\Domain\Platform;
use Modules\Commerce\Domain\Shopify\ShopDomain;
use Modules\Commerce\Domain\Shopify\ShopifySignature;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Commerce\Infrastructure\Shopify\ShopifyAdminClient;
use Modules\Commerce\Infrastructure\StoreRouter;

/**
 * Connecting a Shopify store (OAuth authorization code grant, offline token).
 *
 * start():  a signed-in member asks to connect a shop; Trik remembers (workspace, user, shop) under a
 *           random single-use state for 10 minutes and sends the browser to Shopify's consent screen.
 * finish(): Shopify sends the browser back with a code. Nothing is trusted until Shopify's HMAC, the
 *           shop domain, the age, the state (single use, same shop) and the member's permission all
 *           check out; then the code becomes a token, stored encrypted, and the backfill is queued.
 */
final class ShopifyInstall
{
    public const PERMISSION = 'commerce.stores.manage';

    public function __construct(
        private readonly ShopifyAdminClient $client,
        private readonly StoreRouter $router,
        private readonly TenantContext $tenant,
        private readonly WorkspacePermissions $permissions,
        private readonly PermissionRegistry $registry,
    ) {}

    public function start(string $workspaceId, string $userId, ShopDomain $shop): string
    {
        self::assertConfigured();

        $route = $this->router->byShop(Platform::Shopify, $shop->value);
        if ($route !== null && $route['workspace_id'] !== $workspaceId) {
            throw Problem::conflict('store-connected-elsewhere', 'This store is connected to another Trik workspace. Disconnect it there first.');
        }

        $state = Str::random(40);
        Cache::put(self::stateKey($state), ['workspace_id' => $workspaceId, 'user_id' => $userId, 'shop' => $shop->value], (int) config('commerce.shopify.oauth_max_age_seconds', 600));

        return "https://{$shop->value}/admin/oauth/authorize?".http_build_query([
            'client_id' => (string) config('commerce.shopify.client_id'),
            'scope' => (string) config('commerce.shopify.scopes'),
            'redirect_uri' => self::callbackUrl(),
            'state' => $state,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query  the callback's query string, as Shopify sent it
     *
     * @throws InstallFailed
     */
    public function finish(array $query): Store
    {
        if (! ShopifySignature::verifyQuery($query, self::secrets())) {
            throw new InstallFailed('invalid-signature');
        }

        try {
            $shop = ShopDomain::fromShopify(is_string($query['shop'] ?? null) ? $query['shop'] : '');
        } catch (InvalidArgumentException) {
            throw new InstallFailed('invalid-shop');
        }

        $timestamp = is_numeric($query['timestamp'] ?? null) ? (int) $query['timestamp'] : 0;
        if (abs(time() - $timestamp) > (int) config('commerce.shopify.oauth_max_age_seconds', 600)) {
            throw new InstallFailed('expired');
        }

        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $pending = $state === '' ? null : Cache::pull(self::stateKey($state)); // single use
        if (! is_array($pending)) {
            throw new InstallFailed('expired');
        }
        if (! hash_equals((string) $pending['shop'], $shop->value)) {
            throw new InstallFailed('shop-mismatch');
        }

        $workspaceId = (string) $pending['workspace_id'];
        $userId = (string) $pending['user_id'];

        // the member may have lost the permission (or left) since they clicked "Connect"
        $access = $this->permissions->access($workspaceId, $userId);
        if ($access === null || ! $this->registry->allows($access->grants, self::PERMISSION)) {
            throw new InstallFailed('forbidden');
        }

        $route = $this->router->byShop(Platform::Shopify, $shop->value);
        if ($route !== null && $route['workspace_id'] !== $workspaceId) {
            throw new InstallFailed('store-connected-elsewhere');
        }

        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($code === '') {
            throw new InstallFailed('denied'); // the seller pressed Cancel on Shopify's screen
        }

        try {
            $granted = $this->client->exchangeCode($shop, $code);
        } catch (ShopifyCallFailed $e) {
            report($e);

            throw new InstallFailed('shopify-error');
        }

        return $this->tenant->run($workspaceId, function () use ($shop, $granted, $userId): Store {
            try {
                $store = DB::transaction(function () use ($shop, $granted, $userId): Store {
                    /** @var Store $store */
                    $store = Store::query()->firstOrNew(['platform' => Platform::Shopify, 'shop_domain' => $shop->value]);
                    $store->fill([
                        'access_token' => $granted['access_token'],
                        'scopes' => $granted['scope'],
                        'status' => StoreStatus::Active,
                        'installed_by' => $userId,
                        'installed_at' => now(),
                        'uninstalled_at' => null,
                    ])->save();

                    BackfillShopifyOrders::dispatch($store->workspace_id, $store->id)->afterCommit();

                    return $store;
                });
            } catch (UniqueConstraintViolationException) {
                // another workspace connected the same shop a moment ago
                throw new InstallFailed('store-connected-elsewhere');
            }

            return $store;
        });
    }

    /**
     * The app URL Shopify opens (install link, app list): the shop, signed by Shopify.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws InstallFailed
     */
    public function verifyAppLaunch(array $query): ShopDomain
    {
        if (! ShopifySignature::verifyQuery($query, self::secrets())) {
            throw new InstallFailed('invalid-signature');
        }
        $timestamp = is_numeric($query['timestamp'] ?? null) ? (int) $query['timestamp'] : 0;
        if (abs(time() - $timestamp) > (int) config('commerce.shopify.oauth_max_age_seconds', 600)) {
            throw new InstallFailed('expired');
        }

        try {
            return ShopDomain::fromShopify(is_string($query['shop'] ?? null) ? $query['shop'] : '');
        } catch (InvalidArgumentException) {
            throw new InstallFailed('invalid-shop');
        }
    }

    /** @return list<string> */
    public static function secrets(): array
    {
        return array_values(array_filter([
            (string) config('commerce.shopify.client_secret'),
            (string) config('commerce.shopify.previous_client_secret'),
        ]));
    }

    public static function callbackUrl(): string
    {
        return self::publicUrl().'/webhooks/shopify/oauth/callback';
    }

    public static function publicUrl(): string
    {
        return rtrim((string) (config('commerce.public_url') ?: config('app.url')), '/');
    }

    public static function assertConfigured(): void
    {
        if ((string) config('commerce.shopify.client_id') === '' || self::secrets() === []) {
            throw Problem::make(503, 'shopify-not-configured', 'The Shopify app is not configured on this server.');
        }
    }

    private static function stateKey(string $state): string
    {
        return 'commerce:shopify:oauth:'.hash('sha256', $state);
    }
}
