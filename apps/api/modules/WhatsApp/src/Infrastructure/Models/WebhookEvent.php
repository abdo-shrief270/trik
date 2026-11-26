<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\WhatsApp\Domain\Provider;

/**
 * A raw webhook delivery, stored before it is acknowledged so nothing is lost
 * if a worker dies; processed_at marks it done.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $number_id
 * @property Provider $provider
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property int $attempts
 * @property string|null $last_error
 */
final class WebhookEvent extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'whatsapp_webhook_events';

    protected $fillable = ['number_id', 'provider', 'payload', 'processed_at', 'attempts', 'last_error'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
