<?php

declare(strict_types=1);

namespace Modules\Orders\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Addresses\Domain\Address;
use Modules\Orders\Domain\NextAction;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Domain\OrderStatus;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $contact_id
 * @property OrderSource $source
 * @property string|null $external_id
 * @property string $number
 * @property OrderStatus $status
 * @property string $payment_method
 * @property string $currency
 * @property int $subtotal
 * @property int $shipping
 * @property int $discount
 * @property int $total
 * @property int $cod_amount
 * @property string|null $customer_name
 * @property string $customer_phone
 * @property array<string, mixed> $address
 * @property bool $address_needs_fix
 * @property Carbon|null $address_fixed_at
 * @property string|null $courier
 * @property string|null $tracking_number
 * @property string|null $tracking_url
 * @property string|null $note
 * @property Carbon $placed_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $returned_at
 * @property string|null $owner_id
 * @property Carbon|null $needs_call_at
 * @property string|null $needs_call_reason
 * @property int|null $risk_score
 * @property string|null $stage
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Order extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'orders_orders';

    // stage is computed by the database
    protected $guarded = ['id', 'workspace_id', 'stage'];

    /** The database defaults, so a freshly created model has them too. */
    protected $attributes = ['address_needs_fix' => false, 'address' => '{}'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => OrderSource::class,
            'status' => OrderStatus::class,
            'subtotal' => 'integer',
            'shipping' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'cod_amount' => 'integer',
            'address' => 'array',
            'address_needs_fix' => 'boolean',
            'address_fixed_at' => 'datetime',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'needs_call_at' => 'datetime',
            'risk_score' => 'integer',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('position');
    }

    public function deliveryAddress(): Address
    {
        return Address::fromArray($this->address);
    }

    public function nextAction(): ?NextAction
    {
        return NextAction::for($this->status, $this->deliveryAddress(), $this->address_needs_fix);
    }
}
