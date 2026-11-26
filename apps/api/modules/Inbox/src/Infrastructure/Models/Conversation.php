<?php

declare(strict_types=1);

namespace Modules\Inbox\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Inbox\Domain\ConversationStatus;
use Modules\Inbox\Domain\MessagingWindow;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $contact_id
 * @property ConversationStatus $status
 * @property string|null $assignee_id
 * @property string|null $number_id
 * @property int $unread_count
 * @property Carbon $last_message_at
 * @property string $last_message_direction
 * @property string $last_message_type
 * @property string|null $last_message_preview
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $ad_referral_at
 * @property Carbon|null $free_until
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 */
final class Conversation extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'inbox_conversations';

    protected $guarded = ['id', 'workspace_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'ad_referral_at' => 'datetime',
            'free_until' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function window(): MessagingWindow
    {
        return MessagingWindow::at(now(), $this->last_inbound_at, $this->ad_referral_at, $this->free_until);
    }
}
