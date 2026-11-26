<?php

declare(strict_types=1);

namespace Modules\Orders\Http\Controllers;

use App\Support\Authorization\PermissionRegistry;
use App\Support\Authorization\WorkspacePermissions;
use App\Support\Http\CursorPage;
use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Addresses\Application\AddressRules;
use Modules\Addresses\Domain\Address;
use Modules\Contacts\Application\ContactDirectory;
use Modules\Orders\Application\ManageOrder;
use Modules\Orders\Application\OrderInput;
use Modules\Orders\Application\OrderItemInput;
use Modules\Orders\Application\OrderPresenter;
use Modules\Orders\Application\RecordOrder;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Domain\OrderStatus;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\Orders\Infrastructure\Models\OrderEvent;

final class OrderController
{
    /** Filters: status, contact_id, needs_address_fix=1. Newest first. */
    public function index(Request $request, OrderPresenter $presenter): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'contact_id' => ['sometimes', 'uuid'],
            'needs_address_fix' => ['sometimes', 'boolean'],
        ]);

        $page = Order::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->string('contact_id')->toString()))
            ->when($request->boolean('needs_address_fix'), fn ($q) => $q->where('address_needs_fix', true))
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request), cursor: CursorPage::cursor($request, ['placed_at' => 'datetime', 'id' => 'uuid']));

        /** @var list<Order> $orders */
        $orders = array_values($page->items());
        $shown = array_combine(array_map(fn (Order $o): string => $o->id, $orders), $presenter->many($orders));

        return CursorPage::response($page, fn (Order $o): array => $shown[$o->id]);
    }

    /** Create an order from chat (#105): for a contact, or a phone number. */
    public function store(Request $request, RecordOrder $record, ContactDirectory $contacts, OrderPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'contact_id' => ['nullable', 'required_without:customer_phone', 'uuid'],
            'customer_phone' => ['nullable', 'required_without:contact_id', 'string', 'max:32'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.variant' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'shipping' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'discount' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'payment_method' => ['sometimes', 'in:cod,prepaid'],
            'note' => ['nullable', 'string', 'max:2000'],
            ...AddressRules::for('address'),
        ]);

        $phone = $data['customer_phone'] ?? null;
        if ($phone === null) {
            $phone = $contacts->find([$data['contact_id']])[$data['contact_id']]['phone'] ?? throw Problem::invalid(['contact_id' => ['No such contact in this workspace.']]);
        }

        try {
            $order = $record->handle(new OrderInput(
                source: OrderSource::Chat,
                externalId: null,
                number: null,
                customerPhone: $phone,
                customerName: $data['customer_name'] ?? null,
                address: Address::fromArray($data['address'] ?? null),
                items: array_values(array_map(fn (array $i): OrderItemInput => new OrderItemInput(
                    (string) $i['title'], (int) $i['quantity'], (int) $i['unit_price'], $i['sku'] ?? null, $i['variant'] ?? null,
                ), $data['items'])),
                placedAt: now(),
                shipping: (int) ($data['shipping'] ?? 0),
                discount: (int) ($data['discount'] ?? 0),
                paymentMethod: $data['payment_method'] ?? 'cod',
                note: $data['note'] ?? null,
            ), $this->actor($request));
        } catch (InvalidArgumentException $e) {
            throw Problem::invalid(['order' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $presenter->one($order)], 201);
    }

    public function show(string $order, OrderPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $presenter->one(Order::query()->findOrFail($order))]);
    }

    /** Fix the address (structured, #344), flag it as needing a fix, or change the note. */
    public function update(Request $request, string $order, ManageOrder $manage, OrderPresenter $presenter, WorkspacePermissions $permissions, PermissionRegistry $registry, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate([
            'owner_id' => ['sometimes', 'nullable', 'uuid'],
            'address_needs_fix' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...AddressRules::for('address'),
        ]);
        $model = Order::query()->findOrFail($order);
        $actor = $this->actor($request);

        if (isset($data['address'])) {
            $model = $manage->fixAddress($model, Address::fromArray($data['address']), $actor);
        } elseif (array_key_exists('address_needs_fix', $data)) {
            $model = $manage->flagAddress($model, (bool) $data['address_needs_fix'], $actor);
        }

        if (array_key_exists('note', $data)) {
            $model = $manage->note($model, $data['note'], $actor);
        }

        if (array_key_exists('owner_id', $data)) {
            $owner = $data['owner_id'];
            if ($owner !== null) {
                $access = $permissions->access($tenant->require(), $owner);
                if ($access === null || ! $registry->allows($access->grants, 'orders.orders.view')) {
                    throw Problem::unprocessable('owner-cannot-view', 'That person is not on this workspace\'s team or cannot see orders.');
                }
            }
            $model = $manage->assignOwner($model, $owner, $actor);
        }

        return response()->json(['data' => $presenter->one($model)]);
    }

    public function confirm(Request $request, string $order, ManageOrder $manage, OrderPresenter $presenter): JsonResponse
    {
        $model = $manage->moveTo(Order::query()->findOrFail($order), OrderStatus::Confirmed, $this->actor($request));

        return response()->json(['data' => $presenter->one($model)]);
    }

    public function cancel(Request $request, string $order, ManageOrder $manage, OrderPresenter $presenter): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $model = $manage->moveTo(Order::query()->findOrFail($order), OrderStatus::Cancelled, $this->actor($request), reason: $data['reason'] ?? null);

        return response()->json(['data' => $presenter->one($model)]);
    }

    /** Everything that happened to the order, oldest first. */
    public function timeline(string $order): JsonResponse
    {
        $model = Order::query()->findOrFail($order);

        return response()->json(['data' => OrderEvent::query()->where('order_id', $model->id)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (OrderEvent $e): array => [
                'type' => $e->type,
                'actor_id' => $e->actor_id,
                'data' => $e->data,
                'at' => $e->created_at->toIso8601String(),
            ])->values()->all()]);
    }

    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
