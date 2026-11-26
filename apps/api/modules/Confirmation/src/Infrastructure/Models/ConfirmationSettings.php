<?php

declare(strict_types=1);

namespace Modules\Confirmation\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $workspace_id
 * @property bool $enabled
 * @property string|null $number_id
 * @property string|null $template_name
 * @property string $template_language
 * @property string|null $reminder_template_name
 * @property int $reminder_after_minutes
 * @property int $max_reminders
 * @property int $escalate_after_minutes
 * @property string|null $confirmed_reply
 * @property string|null $cancelled_reply
 * @property string|null $change_reply
 */
final class ConfirmationSettings extends Model
{
    use BelongsToWorkspace;

    public const CREATED_AT = null;

    public const DEFAULT_REPLIES = [
        'confirmed_reply' => 'تم تأكيد طلبك ✅ هنبلغك أول ما يتشحن.',
        'cancelled_reply' => 'تم إلغاء الطلب. لو حابب تطلب تاني إحنا موجودين.',
        'change_reply' => 'تمام، حد من فريقنا هيكلمك دلوقتي يعدّل الطلب.',
    ];

    public $incrementing = false;

    protected $primaryKey = 'workspace_id';

    protected $keyType = 'string';

    protected $table = 'confirmation_settings';

    protected $guarded = ['workspace_id'];

    protected $attributes = [
        'enabled' => false,
        'template_language' => 'ar',
        'reminder_after_minutes' => 120,
        'max_reminders' => 1,
        'escalate_after_minutes' => 120,
        ...self::DEFAULT_REPLIES,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'reminder_after_minutes' => 'integer', 'max_reminders' => 'integer', 'escalate_after_minutes' => 'integer'];
    }

    /** The workspace's settings, or the defaults (not saved) when none were set. */
    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }

    public function isActive(): bool
    {
        return $this->enabled && $this->template_name !== null;
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'enabled' => $this->enabled,
            'number_id' => $this->number_id,
            'template_name' => $this->template_name,
            'template_language' => $this->template_language,
            'reminder_template_name' => $this->reminder_template_name,
            'reminder_after_minutes' => $this->reminder_after_minutes,
            'max_reminders' => $this->max_reminders,
            'escalate_after_minutes' => $this->escalate_after_minutes,
            'confirmed_reply' => $this->confirmed_reply,
            'cancelled_reply' => $this->cancelled_reply,
            'change_reply' => $this->change_reply,
        ];
    }
}
