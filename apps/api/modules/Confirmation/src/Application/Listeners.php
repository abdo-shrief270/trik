<?php

declare(strict_types=1);

namespace Modules\Confirmation\Application;

use Modules\Confirmation\Application\Jobs\HandleReply;
use Modules\Confirmation\Domain\ReplyAction;
use Modules\Orders\Application\Events\OrderChanged;
use Modules\WhatsApp\Application\Events\MessageReceived;

/** Wires the engine to orders and to WhatsApp replies (both events arrive inside the workspace). */
final class Listeners
{
    public function __construct(private readonly ConfirmationEngine $engine) {}

    public function orderChanged(OrderChanged $event): void
    {
        if ($event->change === 'created') {
            $this->engine->start($event->orderId);
        } elseif ($event->change === 'status_changed' && $event->to !== null && ! $event->to->isPendingConfirmation()) {
            $this->engine->settled($event->orderId);
        }
    }

    public function messageReceived(MessageReceived $event): void
    {
        $parsed = ReplyAction::parse($event->message->replyId);

        if ($parsed !== null) {
            HandleReply::dispatch($parsed[1], $parsed[0], $event->message->from);
        }
    }
}
