<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\WhatsApp\Domain\MessageStatus;

/**
 * One campaign message to one contact. Kept apart from conversation messages: this is
 * the bulk of the volume, and only counts per broadcast matter (ADR 0010).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $number_id
 * @property string $broadcast_id
 * @property string $contact_phone
 * @property string $template
 * @property string|null $provider_message_id
 * @property MessageStatus $status
 * @property array<string, mixed>|null $error
 * @property int $attempts
 * @property Carbon|null $sent_at
 */
final class BroadcastSend extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'whatsapp_broadcast_sends';

    protected $guarded = ['id', 'workspace_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MessageStatus::class,
            'error' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
