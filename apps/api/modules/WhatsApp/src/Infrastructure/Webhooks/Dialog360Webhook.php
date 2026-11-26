<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Webhooks;

use Modules\WhatsApp\Domain\Provider;

final class Dialog360Webhook extends SharedSecretWebhook
{
    public function provider(): Provider
    {
        return Provider::Dialog360;
    }
}
