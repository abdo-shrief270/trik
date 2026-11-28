<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

use Illuminate\Support\Carbon;
use Modules\Analytics\Domain\PilotRates;
use Modules\Analytics\Infrastructure\Models\PilotSettings;
use Modules\Confirmation\Application\ConfirmationStats;
use Modules\Orders\Application\OrderStats;

/**
 * Before vs after Trik for one seller (inside the active workspace): the baseline is the orders placed in the
 * days before go-live (90 by default: what the store backfill imports), "after" every order placed since.
 * Days are Cairo days. Every number comes from the orders' statuses, which follow the store.
 */
final class PilotReport
{
    public const TIMEZONE = 'Africa/Cairo';

    public function __construct(
        private readonly OrderStats $orders,
        private readonly ConfirmationStats $confirmation,
    ) {}

    /** @return array{at: Carbon|null, source: 'set'|'first_confirmation'|null, baseline_days: int} */
    public function goLive(): array
    {
        $settings = PilotSettings::current();
        if ($settings->live_at !== null) {
            return ['at' => $settings->live_at, 'source' => 'set', 'baseline_days' => $settings->baseline_days];
        }
        $first = $this->confirmation->firstRunAt();

        return ['at' => $first, 'source' => $first === null ? null : 'first_confirmation', 'baseline_days' => $settings->baseline_days];
    }

    /** @return array<string, mixed> */
    public function summary(int $days = 60): array
    {
        $live = $this->goLive();
        $now = now();

        $periods = null;
        if ($live['at'] !== null) {
            $baselineFrom = $live['at']->copy()->subDays($live['baseline_days']);
            $before = $this->orders->period($baselineFrom, $live['at']);
            $after = $this->orders->period($live['at'], $now);
            $beforeRates = PilotRates::from($before);
            $afterRates = PilotRates::from($after);

            $periods = [
                'baseline' => ['from' => $baselineFrom->toIso8601String(), 'to' => $live['at']->toIso8601String(), 'counts' => $before, 'rates' => $beforeRates->toArray()],
                'after' => ['from' => $live['at']->toIso8601String(), 'to' => $now->toIso8601String(), 'counts' => $after, 'rates' => $afterRates->toArray()],
                'change' => PilotRates::change($beforeRates, $afterRates),
            ];
        }

        $from = $now->copy()->setTimezone(self::TIMEZONE)->subDays($days - 1)->startOfDay()->utc();

        return [
            'live_at' => $live['at']?->toIso8601String(),
            'live_at_source' => $live['source'],
            'baseline_days' => $live['baseline_days'],
            'timezone' => self::TIMEZONE,
            ...($periods ?? ['baseline' => null, 'after' => null, 'change' => null]),
            'daily' => array_map(fn (array $d): array => [...$d, 'rates' => PilotRates::from($d)->toArray()], $this->orders->series($from, $now, self::TIMEZONE)),
        ];
    }

    /**
     * One row per week (Monday, Cairo) from the start of the baseline: for the pilot review.
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function weekly(): array
    {
        $live = $this->goLive();
        $from = ($live['at'] ?? now())->copy()->subDays($live['baseline_days']);
        $liveDay = $live['at']?->copy()->setTimezone(self::TIMEZONE)->startOfWeek()->toDateString();

        return array_map(function (array $w) use ($liveDay): array {
            $rates = PilotRates::from($w);

            return [
                'week' => (string) $w['date'],
                'period' => $liveDay === null ? 'before' : ((string) $w['date'] < $liveDay ? 'before' : ((string) $w['date'] === $liveDay ? 'go-live week' : 'after')),
                'orders' => $w['placed'], 'confirmed' => $w['confirmed'], 'cancelled' => $w['cancelled'], 'shipped' => $w['shipped'],
                'delivered' => $w['delivered'], 'refused' => $w['refused'], 'open' => $w['open'],
                'confirmation_rate' => $rates->confirmation, 'cancellation_rate' => $rates->cancellation, 'refusal_rate' => $rates->refusal,
                'median_minutes_to_confirm' => $w['median_confirm_minutes'],
            ];
        }, $this->orders->series($from, now(), self::TIMEZONE, 'week'));
    }
}
