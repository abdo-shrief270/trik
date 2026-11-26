<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\WhatsApp\Domain\Direction;
use Modules\WhatsApp\Domain\Messages\OutgoingMessage;
use Modules\WhatsApp\Domain\MessageStatus;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Infrastructure\Models\Message;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;
use Modules\WhatsApp\Infrastructure\Providers\CloudApiPayload;

/**
 * Queues a message from a connected number. The message is logged as queued first,
 * then a job sends it with retries; status webhooks move it to delivered / read.
 */
final class SendMessage
{
    public function handle(WhatsAppNumber $number, PhoneNumber $to, OutgoingMessage $message, ?string $replyTo = null): Message
    {
        $row = Message::query()->create([
            'number_id' => $number->id,
            'direction' => Direction::Outbound,
            'contact_phone' => $to->digits,
            'type' => $message->type(),
            'body' => CloudApiPayload::message($to, $message, $replyTo),
            'status' => MessageStatus::Queued,
        ]);

        SendOutgoingMessage::dispatch($row->id, $to, $message, $replyTo)->afterCommit();

        return $row;
    }
}
