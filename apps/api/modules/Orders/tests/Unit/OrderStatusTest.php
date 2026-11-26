<?php

declare(strict_types=1);

use Modules\Addresses\Domain\Address;
use Modules\Addresses\Domain\Governorate;
use Modules\Orders\Domain\NextAction;
use Modules\Orders\Domain\OrderStatus;

it('only moves forward and never out of a final state', function (): void {
    expect(OrderStatus::New->canMoveTo(OrderStatus::Confirmed))->toBeTrue()
        ->and(OrderStatus::Shipped->canMoveTo(OrderStatus::Confirmed))->toBeFalse()
        ->and(OrderStatus::Shipped->canMoveTo(OrderStatus::Cancelled))->toBeFalse()
        ->and(OrderStatus::Cancelled->isFinal())->toBeTrue()
        ->and(OrderStatus::Returned->isFinal())->toBeTrue();

    foreach (OrderStatus::cases() as $s) {
        expect($s->canMoveTo($s))->toBeFalse();
    }
});

it('picks the next action for the order bar', function (): void {
    $deliverable = new Address(governorate: Governorate::Giza, area: 'الدقي', street: 'التحرير');

    expect(NextAction::for(OrderStatus::New, $deliverable, false))->toBe(NextAction::Confirm)
        ->and(NextAction::for(OrderStatus::New, new Address(raw: 'x'), false))->toBe(NextAction::Confirm)
        ->and(NextAction::for(OrderStatus::New, new Address, false))->toBe(NextAction::FixAddress)
        ->and(NextAction::for(OrderStatus::New, $deliverable, true))->toBe(NextAction::FixAddress)
        ->and(NextAction::for(OrderStatus::AwaitingDeposit, $deliverable, false))->toBe(NextAction::ResendDepositLink)
        ->and(NextAction::for(OrderStatus::Confirmed, $deliverable, true))->toBeNull();
});
