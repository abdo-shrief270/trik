<?php

declare(strict_types=1);

namespace Modules\Waitlist\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Waitlist\Domain\EntryStatus;
use Modules\Waitlist\Domain\MonthlyOrders;
use Modules\Waitlist\Domain\Platform;

/**
 * @property string $id
 * @property string $email
 * @property string $name
 * @property string $store_name
 * @property string $phone
 * @property Platform $platform
 * @property MonthlyOrders $monthly_orders
 * @property string $locale
 * @property EntryStatus $status
 * @property Carbon $consent_at
 * @property string|null $consent_ip
 * @property Carbon|null $confirmation_sent_at
 * @property int $confirmations_sent
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $unsubscribed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class WaitlistEntry extends Model
{
    use HasUuids;
    use MassPrunable;

    /** Unconfirmed sign-ups are deleted after this many days (data minimisation). */
    public const PRUNE_AFTER_DAYS = 30;

    protected $table = 'waitlist_entries';

    /** Every attribute is set explicitly by the module; nothing comes from a request array. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'monthly_orders' => MonthlyOrders::class,
            'status' => EntryStatus::class,
            'consent_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'confirmations_sent' => 'integer',
        ];
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()
            ->where('status', EntryStatus::Pending->value)
            ->where('updated_at', '<', now()->subDays(self::PRUNE_AFTER_DAYS));
    }
}
