<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $workspace_id
 * @property Carbon|null $live_at set by the seller; null means "the first WhatsApp confirmation"
 * @property int $baseline_days
 */
final class PilotSettings extends Model
{
    use BelongsToWorkspace;

    public const CREATED_AT = null;

    public $incrementing = false;

    protected $table = 'analytics_pilots';

    protected $primaryKey = 'workspace_id';

    protected $keyType = 'string';

    protected $fillable = ['live_at', 'baseline_days'];

    protected $attributes = ['baseline_days' => 90];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['live_at' => 'datetime', 'baseline_days' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }
}
