<?php

declare(strict_types=1);

namespace Modules\Orders;

use App\Support\Modules\ModuleServiceProvider;
use App\Support\Privacy\PersonalDataRegistry;
use Modules\Orders\Application\OrdersPersonalData;

/** Orders from every source in one shape, the order card in chat, confirm / cancel, address fixes. */
final class OrdersServiceProvider extends ModuleServiceProvider
{
    protected function permissions(): array
    {
        return [
            'orders.orders.view' => 'See orders and their timeline',
            'orders.orders.create' => 'Create orders from chat',
            'orders.orders.update' => 'Fix addresses and edit notes on orders',
            'orders.orders.confirm' => 'Confirm and cancel orders',
        ];
    }

    protected function bootModule(): void
    {
        app(PersonalDataRegistry::class)->register(OrdersPersonalData::class);
    }
}
