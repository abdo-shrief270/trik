<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders from every source (Shopify, EasyOrders, created in chat), one shape:
 *  - orders_orders     the order; money in minor units (piastres); address raw + structured (#344)
 *  - orders_items      line items
 *  - orders_events     the order timeline (append-only): created, status changes, address fixes…
 * An order from a store is unique per (source, external_id), so webhook redeliveries update, never duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table orders_orders (
                id uuid primary key,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                contact_id uuid not null references contacts_contacts (id) on delete restrict,
                source varchar(20) not null,
                external_id varchar(100),
                number varchar(40) not null,
                status varchar(20) not null,
                payment_method varchar(10) not null default 'cod' check (payment_method in ('cod', 'prepaid')),
                currency char(3) not null default 'EGP',
                subtotal bigint not null default 0 check (subtotal >= 0),
                shipping bigint not null default 0 check (shipping >= 0),
                discount bigint not null default 0 check (discount >= 0),
                total bigint not null check (total >= 0),
                cod_amount bigint not null default 0 check (cod_amount >= 0),
                customer_name varchar(255),
                customer_phone varchar(20) not null,
                address jsonb not null default '{}',
                address_needs_fix boolean not null default false,
                address_fixed_at timestamptz,
                courier varchar(60),
                tracking_number varchar(100),
                tracking_url varchar(500),
                note text,
                placed_at timestamptz not null,
                confirmed_at timestamptz,
                cancelled_at timestamptz,
                shipped_at timestamptz,
                delivered_at timestamptz,
                returned_at timestamptz,
                created_at timestamptz,
                updated_at timestamptz,
                unique (workspace_id, source, external_id)
            )
            SQL);
        DB::statement('create index orders_orders_contact_idx on orders_orders (workspace_id, contact_id, placed_at desc)');
        DB::statement('create index orders_orders_list_idx on orders_orders (workspace_id, status, placed_at desc, id desc)');
        DB::statement('create index orders_orders_address_fix_idx on orders_orders (workspace_id, placed_at desc) where address_needs_fix');
        RowLevelSecurity::enable('orders_orders');

        DB::statement(<<<'SQL'
            create table orders_items (
                id uuid primary key,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                order_id uuid not null references orders_orders (id) on delete cascade,
                external_id varchar(100),
                sku varchar(100),
                title varchar(255) not null,
                variant varchar(255),
                quantity integer not null check (quantity > 0),
                unit_price bigint not null check (unit_price >= 0),
                total bigint not null check (total >= 0),
                position smallint not null default 0
            )
            SQL);
        DB::statement('create index orders_items_order_idx on orders_items (order_id, position)');
        RowLevelSecurity::enable('orders_items');

        DB::statement(<<<'SQL'
            create table orders_events (
                id uuid primary key,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                order_id uuid not null references orders_orders (id) on delete cascade,
                type varchar(40) not null,
                actor_id uuid references users (id) on delete set null,
                data jsonb,
                created_at timestamptz not null default now()
            )
            SQL);
        DB::statement('create index orders_events_order_idx on orders_events (order_id, created_at)');
        RowLevelSecurity::enable('orders_events');

        $role = (string) config('database.connections.pgsql.role', 'trik_app');
        if ($role !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $role) === 1) {
            DB::statement(sprintf('revoke update, delete on table orders_events from "%s"', $role));
        }
    }

    public function down(): void
    {
        foreach (['orders_events', 'orders_items', 'orders_orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
