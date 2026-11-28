<?php

declare(strict_types=1);

namespace Modules\Risk\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $order_id
 * @property string $workspace_id
 * @property int $score
 * @property list<array{code: string, points: int, params: array<string, int|float>}> $reasons
 * @property array<string, int|bool|null> $inputs
 * @property string $model
 * @property Carbon|null $flagged_at when the score first reached the threshold
 * @property Carbon $computed_at
 */
final class RiskAssessment extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'risk_assessments';

    protected $primaryKey = 'order_id';

    protected $keyType = 'string';

    protected $fillable = ['order_id', 'score', 'reasons', 'inputs', 'model', 'flagged_at', 'computed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reasons' => 'array', 'inputs' => 'array', 'flagged_at' => 'datetime', 'computed_at' => 'datetime'];
    }
}
