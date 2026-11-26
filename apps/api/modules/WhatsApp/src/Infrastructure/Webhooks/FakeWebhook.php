<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Webhooks;

use Modules\WhatsApp\Domain\Provider;

final class FakeWebhook extends SharedSecretWebhook
{
    public function provider(): Provider
    {
        return Provider::Fake;
    }
}
