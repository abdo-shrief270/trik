<?php

declare(strict_types=1);

namespace Modules\Inbox\Domain;

enum ConversationStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
