<?php

declare(strict_types=1);

namespace Modules\Confirmation\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Confirmation\Domain\RunStatus;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $order_id
 * @property RunStatus $status
 * @property string|null $number_id
 * @property int $messages_sent
 * @property int $reminders_sent
 * @property string|null $last_message_id
 * @property Carbon|null $next_action_at
 * @property Carbon|null $resolved_at
 * @property string|null $outcome
 * @property Carbon|null $created_at
 */
final class ConfirmationRun extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'confirmation_runs';

    protected $guarded = ['id', 'workspace_id'];

    protected $attributes = ['messages_sent' => 0, 'reminders_sent' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'messages_sent' => 'integer',
            'reminders_sent' => 'integer',
            'next_action_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'status' => $this->status->value,
            'outcome' => $this->outcome,
            'messages_sent' => $this->messages_sent,
            'reminders_sent' => $this->reminders_sent,
            'next_action_at' => $this->next_action_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
