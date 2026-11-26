<?php

declare(strict_types=1);

namespace Modules\Inbox\Application;

use Modules\Contacts\Application\ContactDirectory;
use Modules\Inbox\Infrastructure\Models\Conversation;

/** The API shape of conversations, with their contacts loaded in one query. */
final class ConversationPresenter
{
    public function __construct(private readonly ContactDirectory $contacts) {}

    /**
     * @param  list<Conversation>  $conversations
     * @return list<array<string, mixed>>
     */
    public function many(array $conversations): array
    {
        $contacts = $this->contacts->find(array_map(fn (Conversation $c): string => $c->contact_id, $conversations));

        return array_map(fn (Conversation $c): array => [
            'id' => $c->id,
            'status' => $c->status->value,
            'contact' => $contacts[$c->contact_id] ?? ['id' => $c->contact_id, 'name' => null, 'phone' => null],
            'assignee_id' => $c->assignee_id,
            'number_id' => $c->number_id,
            'unread_count' => $c->unread_count,
            'last_message' => [
                'at' => $c->last_message_at->toIso8601String(),
                'direction' => $c->last_message_direction,
                'type' => $c->last_message_type,
                'preview' => $c->last_message_preview,
            ],
            'last_inbound_at' => $c->last_inbound_at?->toIso8601String(),
            'window' => $c->window()->toArray(),
            'closed_at' => $c->closed_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ], $conversations);
    }

    /** @return array<string, mixed> */
    public function one(Conversation $conversation): array
    {
        return $this->many([$conversation])[0];
    }
}
