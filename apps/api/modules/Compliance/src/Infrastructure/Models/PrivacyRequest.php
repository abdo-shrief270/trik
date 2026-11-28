<?php

declare(strict_types=1);

namespace Modules\Compliance\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Compliance\Domain\RequestKind;

/**
 * @property string $id
 * @property string $workspace_id
 * @property RequestKind $kind
 * @property string $origin e.g. shopify
 * @property string|null $origin_ref e.g. the shop domain
 * @property string $delivery_id
 * @property array<string, mixed> $subject encrypted at rest
 * @property string $status pending, done, failed
 * @property array<string, int> $result per source: records exported or redacted
 * @property array<string, mixed>|null $export encrypted at rest, dropped after export_expires_at
 * @property Carbon|null $export_expires_at
 * @property int $attempts
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon|null $completed_at
 */
final class PrivacyRequest extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'compliance_requests';

    protected $fillable = ['kind', 'origin', 'origin_ref', 'delivery_id', 'subject', 'status', 'result', 'export', 'export_expires_at', 'attempts', 'error', 'completed_at'];

    protected $hidden = ['subject', 'export'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => RequestKind::class,
            'subject' => 'encrypted:array',
            'result' => 'array',
            'export' => 'encrypted:array',
            'export_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
