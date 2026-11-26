<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Events;

/**
 * Conversation messages were written to the log: a batch of inbound messages, or one outbound message.
 *
 * Dispatched INSIDE the transaction that wrote them, so a listener's writes commit or roll back
 * together with the messages (the inbox never misses one, and never shows one that does not exist).
 * Listeners must therefore be synchronous, set-based (a fixed number of queries per batch) and must
 * not call other services; anything slow goes to DB::afterCommit() or the queue.
 * Broadcast sends are not conversation messages and do not appear here.
 */
final readonly class MessagesLogged
{
    /** @param list<LoggedMessage> $messages ordered oldest first */
    public function __construct(public string $workspaceId, public array $messages) {}
}
