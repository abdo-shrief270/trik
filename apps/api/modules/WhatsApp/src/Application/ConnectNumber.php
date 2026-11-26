<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\WhatsApp\Domain\Capability;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;

/**
 * Connects a number in the active workspace with the provider credentials the seller
 * (or Trik ops, during the pilot) got from onboarding. Registers the webhook where the
 * provider allows it, and reads the number's details where it can.
 */
final class ConnectNumber
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    /** @param array<string, string> $credentials */
    public function handle(Provider $provider, string $externalId, array $credentials, ?string $wabaId = null, ?string $displayPhoneNumber = null): WhatsAppNumber
    {
        return DB::transaction(function () use ($provider, $externalId, $credentials, $wabaId, $displayPhoneNumber): WhatsAppNumber {
            $number = WhatsAppNumber::query()->create([
                'provider' => $provider,
                'external_id' => $externalId,
                'waba_id' => $wabaId,
                'display_phone_number' => $displayPhoneNumber,
                'credentials' => [...$credentials, 'webhook_secret' => Str::random(48)],
                'status' => 'active',
            ]);

            $adapter = $this->providers->for($number->toConnected());

            if ($adapter->supports(Capability::NumberInfo)) {
                $info = $adapter->numberInfo();
                $number->forceFill([
                    'display_phone_number' => $info->displayPhoneNumber ?: $number->display_phone_number,
                    'verified_name' => $info->verifiedName,
                ])->save();
            }

            if ($adapter->supports(Capability::ConfigureWebhook)) {
                $adapter->configureWebhook(WebhookUrls::forNumber($number->id, $provider), (string) $number->webhookSecret());
            }

            return $number;
        });
    }
}
