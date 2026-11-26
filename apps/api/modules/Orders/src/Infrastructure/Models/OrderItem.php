<?php

declare(strict_types=1);

namespace Modules\Orders\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property string|null $external_id
 * @property string|null $sku
 * @property string $title
 * @property string|null $variant
 * @property int $quantity
 * @property int $unit_price
 * @property int $total
 * @property int $position
 */
final class OrderItem extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    public $timestamps = false;

    protected $table = 'orders_items';

    protected $guarded = ['id', 'workspace_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer', 'total' => 'integer', 'position' => 'integer'];
    }
}
