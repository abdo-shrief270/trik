<?php

declare(strict_types=1);

namespace Modules\Inbox\Domain;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * WhatsApp's rules for what the business may send right now (#60):
 *  - customer service window: free-form messages only within 24 hours of the customer's last
 *    message; after that, templates only;
 *  - free entry point: when the customer came from a Click-to-WhatsApp ad or a Facebook Page
 *    button and the business answered within 24 hours, everything sent for the next 72 hours is
 *    free. It makes messages free, it does NOT extend the 24-hour window for free-form messages.
 */
final readonly class MessagingWindow
{
    public const HOURS = 24;

    public const FREE_ENTRY_HOURS = 72;

    private function __construct(
        public bool $open,
        public ?DateTimeImmutable $closesAt,
        public ?DateTimeImmutable $freeUntil,
        public bool $fromAd,
    ) {}

    public static function at(DateTimeInterface $now, ?DateTimeInterface $lastInboundAt, ?DateTimeInterface $adReferralAt = null, ?DateTimeInterface $freeUntil = null): self
    {
        $now = DateTimeImmutable::createFromInterface($now);
        $closesAt = $lastInboundAt === null ? null : DateTimeImmutable::createFromInterface($lastInboundAt)->modify('+'.self::HOURS.' hours');
        $free = $freeUntil !== null && $freeUntil > $now ? DateTimeImmutable::createFromInterface($freeUntil) : null;

        // the ad entry is relevant while its window (24 h to answer, then 72 h free) can still apply
        $fromAd = $adReferralAt !== null
            && ($free !== null || DateTimeImmutable::createFromInterface($adReferralAt)->modify('+'.self::HOURS.' hours') > $now);

        return new self($closesAt !== null && $closesAt > $now, $closesAt, $free, $fromAd);
    }

    /** Free-form messages (text, media, buttons) are allowed; templates always are. */
    public function allowsFreeForm(): bool
    {
        return $this->open;
    }

    /** @return array{open: bool, closes_at: string|null, free_until: string|null, entry: 'ad'|null} */
    public function toArray(): array
    {
        return [
            'open' => $this->open,
            'closes_at' => $this->closesAt?->format(DATE_ATOM),
            'free_until' => $this->freeUntil?->format(DATE_ATOM),
            'entry' => $this->fromAd ? 'ad' : null,
        ];
    }
}
