<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Modules\WhatsApp\Application\Events\LoggedMessage;
use Modules\WhatsApp\Application\Events\MessagesLogged;
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
    public function __construct(private readonly Dispatcher $events) {}

    public function handle(WhatsAppNumber $number, PhoneNumber $to, OutgoingMessage $message, ?string $replyTo = null, ?string $sentBy = null): Message
    {
        return DB::transaction(function () use ($number, $to, $message, $replyTo, $sentBy): Message {
            $row = Message::query()->create([
                'number_id' => $number->id,
                'direction' => Direction::Outbound,
                'contact_phone' => $to->digits,
                'type' => $message->type(),
                'body' => CloudApiPayload::message($to, $message, $replyTo),
                'status' => MessageStatus::Queued,
                'sent_by' => $sentBy,
            ]);

            $this->events->dispatch(new MessagesLogged($row->workspace_id, [new LoggedMessage(
                messageId: $row->id,
                numberId: $number->id,
                direction: Direction::Outbound,
                contactPhone: $to->digits,
                type: $message->type(),
                at: $row->created_at ?? now(),
                preview: OutgoingMessages::preview($message),
                sentBy: $sentBy,
            )]));

            SendOutgoingMessage::dispatch($row->id, $to, $message, $replyTo)->afterCommit();

            return $row;
        });
    }

    /** Same, for callers outside this module that only hold the number's id. */
    public function fromNumber(string $numberId, PhoneNumber $to, OutgoingMessage $message, ?string $replyTo = null, ?string $sentBy = null): Message
    {
        return $this->handle(WhatsAppNumber::query()->findOrFail($numberId), $to, $message, $replyTo, $sentBy);
    }
}
