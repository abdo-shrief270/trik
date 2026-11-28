<?php

declare(strict_types=1);

namespace Modules\Risk\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Modules\Risk\Domain\RiskWeights;

/**
 * @property string $workspace_id
 * @property int $threshold orders scoring this or more are flagged
 * @property array<string, int> $weights overrides of RiskWeights::DEFAULTS
 */
final class RiskSettings extends Model
{
    use BelongsToWorkspace;

    public const CREATED_AT = null;

    public const DEFAULT_THRESHOLD = 70;

    public $incrementing = false;

    protected $table = 'risk_settings';

    protected $primaryKey = 'workspace_id';

    protected $keyType = 'string';

    protected $fillable = ['threshold', 'weights'];

    protected $attributes = ['threshold' => self::DEFAULT_THRESHOLD, 'weights' => '{}'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['weights' => 'array', 'threshold' => 'integer'];
    }

    /** The active workspace's settings, or the defaults when none were saved. */
    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }

    public function riskWeights(): RiskWeights
    {
        return new RiskWeights(array_map('intval', $this->weights));
    }
}
