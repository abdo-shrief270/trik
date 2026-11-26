<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\WhatsApp\Domain\Direction;
use Modules\WhatsApp\Domain\MessageStatus;

/**
 * Message log per number, both directions. Inbound rows are unique per provider message id,
 * which makes webhook redelivery harmless.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $number_id
 * @property Direction $direction
 * @property string|null $provider_message_id
 * @property string $contact_phone
 * @property string $type
 * @property array<string, mixed> $body
 * @property MessageStatus $status
 * @property array<string, mixed>|null $error
 * @property int $attempts
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $received_at
 * @property Carbon|null $created_at
 * @property string|null $sent_by
 */
final class Message extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'whatsapp_messages';

    protected $guarded = ['id', 'workspace_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'status' => MessageStatus::class,
            'body' => 'array',
            'error' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'number_id' => $this->number_id,
            'direction' => $this->direction->value,
            'provider_message_id' => $this->provider_message_id,
            'contact_phone' => $this->contact_phone,
            'type' => $this->type,
            'status' => $this->status->value,
            'error' => $this->error,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'sent_by' => $this->sent_by,
            'content' => $this->content(),
        ];
    }

    /**
     * What the message says, without transport details: for inbound messages the parsed fields
     * (text, media id, reply id…, never the raw provider payload); for outbound ones the Cloud API
     * content object (text, template, interactive or media).
     *
     * @return array<string, mixed>
     */
    public function content(): array
    {
        $body = $this->body;

        if ($this->direction === Direction::Inbound) {
            unset($body['raw']);

            return $body;
        }

        return array_diff_key($body, array_flip(['messaging_product', 'recipient_type', 'to']));
    }
}
