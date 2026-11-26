<?php

declare(strict_types=1);

namespace Modules\Orders\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One entry on an order's timeline. Append-only (the app role cannot update or delete).
 *
 * @property string $id
 * @property string $order_id
 * @property string $type
 * @property string|null $actor_id
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 */
final class OrderEvent extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'orders_events';

    protected $guarded = ['id', 'workspace_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
