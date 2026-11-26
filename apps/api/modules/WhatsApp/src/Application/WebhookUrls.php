<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Modules\WhatsApp\Domain\Provider;

final class WebhookUrls
{
    public static function forNumber(string $numberId, Provider $provider): string
    {
        $base = rtrim((string) (config('whatsapp.webhook_base_url') ?: config('app.url')), '/');

        return $provider === Provider::MetaCloud
            ? "{$base}/webhooks/whatsapp/meta_cloud"
            : "{$base}/webhooks/whatsapp/{$provider->value}/{$numberId}";
    }
}
