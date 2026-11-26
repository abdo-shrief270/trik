<?php

declare(strict_types=1);

use Modules\Inbox\Domain\MessagingWindow;

$now = new DateTimeImmutable('2026-11-27 12:00:00+00:00');

it('is open for 24 hours after the customer last wrote', function () use ($now): void {
    $w = MessagingWindow::at($now, $now->modify('-1439 minutes'));
    expect($w->allowsFreeForm())->toBeTrue()
        ->and($w->toArray()['closes_at'])->toBe('2026-11-27T12:01:00+00:00');

    expect(MessagingWindow::at($now, $now->modify('-24 hours'))->allowsFreeForm())->toBeFalse()
        ->and(MessagingWindow::at($now, null)->toArray())->toBe(['open' => false, 'closes_at' => null, 'free_until' => null, 'entry' => null]);
});

it('labels ad entries while the free window can still apply, without extending free-form time', function () use ($now): void {
    // came from an ad an hour ago, not answered yet: answering now would open the 72 free hours
    expect(MessagingWindow::at($now, $now->modify('-1 hour'), $now->modify('-1 hour'))->toArray()['entry'])->toBe('ad');

    // answered: free for 72 hours, but free-form still closes 24 hours after the customer's message
    $w = MessagingWindow::at($now, $now->modify('-30 hours'), $now->modify('-30 hours'), $now->modify('+40 hours'));
    expect($w->allowsFreeForm())->toBeFalse()
        ->and($w->toArray())->toMatchArray(['entry' => 'ad', 'free_until' => '2026-11-29T04:00:00+00:00']);

    // never answered within 24 hours: the ad entry no longer matters
    expect(MessagingWindow::at($now, $now->modify('-30 hours'), $now->modify('-30 hours'))->toArray()['entry'])->toBeNull();
});
