<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain;

/** Optional features. Features check these instead of the provider name. */
enum Capability: string
{
    case Templates = 'templates';
    case Media = 'media';
    case InteractiveMessages = 'interactive_messages';
    case MarkRead = 'mark_read';
    case NumberInfo = 'number_info';
    case ConfigureWebhook = 'configure_webhook';
}
