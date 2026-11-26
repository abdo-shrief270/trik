<?php

declare(strict_types=1);

namespace Modules\Inbox;

use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Inbox\Application\RecordConversationActivity;
use Modules\WhatsApp\Application\Events\MessagesLogged;

/** The team inbox: one conversation per customer, the messaging window, assignment. */
final class InboxServiceProvider extends ModuleServiceProvider
{
    protected function permissions(): array
    {
        return [
            'inbox.conversations.view' => 'See conversations and their messages',
            'inbox.conversations.reply' => 'Reply to customers, take conversations, close and reopen them',
            'inbox.conversations.assign' => 'Assign conversations to anyone on the team',
        ];
    }

    protected function bootModule(): void
    {
        // Synchronous, inside the transaction that logs the messages (see MessagesLogged).
        Event::listen(MessagesLogged::class, RecordConversationActivity::class);
    }
}
