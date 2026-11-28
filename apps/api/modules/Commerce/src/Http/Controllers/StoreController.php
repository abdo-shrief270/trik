<?php

declare(strict_types=1);

namespace Modules\Commerce\Http\Controllers;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Commerce\Application\Jobs\BackfillShopifyOrders;
use Modules\Commerce\Application\Shopify\ShopifyInstall;
use Modules\Commerce\Application\StorePresenter;
use Modules\Commerce\Domain\Platform;
use Modules\Commerce\Domain\Shopify\ShopDomain;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;

/** Connected stores of the current workspace. */
final class StoreController
{
    public function __construct(private readonly StorePresenter $presenter) {}

    public function index(): JsonResponse
    {
        $stores = Store::query()->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $stores->map(fn (Store $s): array => $this->presenter->present($s))->values()]);
    }

    /** Step 1 of connecting Shopify: where to send the browser. */
    public function installShopify(Request $request, ShopifyInstall $install, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate(['shop' => ['required', 'string', 'max:255']]);

        try {
            $shop = ShopDomain::fromUserInput($data['shop']);
        } catch (InvalidArgumentException) {
            throw Problem::invalid(['shop' => ['Enter the store\'s myshopify.com address, e.g. laila.myshopify.com.']]);
        }

        return response()->json(['data' => [
            'authorize_url' => $install->start($tenant->require(), (string) $request->user()?->getAuthIdentifier(), $shop),
            'shop_domain' => $shop->value,
        ]]);
    }

    /** Drops the token and stops routing the shop's webhooks here. The app stays in the store until removed there. */
    public function destroy(string $store): Response
    {
        $model = $this->find($store);

        if ($model->status === StoreStatus::Active) {
            $model->update(['status' => StoreStatus::Disconnected, 'access_token' => null, 'uninstalled_at' => now()]);
        }

        return response()->noContent();
    }

    /** Runs the order backfill again (after a failure, or once read_all_orders is granted). */
    public function backfill(string $store): JsonResponse
    {
        $model = $this->find($store);

        if ($model->status !== StoreStatus::Active || $model->platform !== Platform::Shopify) {
            throw Problem::conflict('store-not-connected', 'Reconnect the store first.');
        }
        if (in_array($model->backfill['status'] ?? null, ['running', 'importing'], true)) {
            throw Problem::conflict('backfill-running', 'The orders are already being imported.');
        }

        $model->update(['backfill' => ['status' => 'queued']]);
        BackfillShopifyOrders::dispatch($model->workspace_id, $model->id);

        return response()->json(['data' => $this->presenter->present($model)], 202);
    }

    private function find(string $id): Store
    {
        // another workspace's store is invisible here (row-level security), so it is a 404 like any unknown id
        $model = Str::isUuid($id) ? Store::query()->find($id) : null;

        return $model ?? throw Problem::notFound('store-not-found', 'No such store.');
    }
}
