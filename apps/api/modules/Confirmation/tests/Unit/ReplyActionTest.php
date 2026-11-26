<?php

declare(strict_types=1);

use Modules\Confirmation\Domain\ReplyAction;

it('reads only its own button payloads', function (): void {
    $id = '01a21897-22bb-705e-a14f-fab60fab6398';

    expect(ReplyAction::parse(ReplyAction::Confirm->payload($id)))->toBe([ReplyAction::Confirm, $id])
        ->and(ReplyAction::parse("trik:c1:cancel:{$id}"))->toBe([ReplyAction::Cancel, $id])
        ->and(ReplyAction::parse('confirm'))->toBeNull()
        ->and(ReplyAction::parse("trik:c1:delete:{$id}"))->toBeNull()
        ->and(ReplyAction::parse("trik:c1:confirm:{$id}' or 1=1"))->toBeNull()
        ->and(ReplyAction::parse(null))->toBeNull();
});
