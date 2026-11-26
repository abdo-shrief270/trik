<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Modules\WhatsApp\Application\Contracts\WebhookAdapter;
use Modules\WhatsApp\Application\Contracts\WhatsAppProvider;
use Modules\WhatsApp\Domain\Provider;

/**
 * Builds the adapter for a number from config('whatsapp.adapters'). Moving a number
 * to another provider is a data change (provider + credentials), never a code change.
 */
final class ProviderRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(ConnectedNumber $number): WhatsAppProvider
    {
        $provider = $this->container->make($this->classFor($number->provider, 'provider'), ['number' => $number]);

        if (! $provider instanceof WhatsAppProvider) {
            throw new InvalidArgumentException("Adapter for {$number->provider->value} must implement WhatsAppProvider.");
        }

        return $provider;
    }

    public function webhooks(Provider $provider): WebhookAdapter
    {
        $adapter = $this->container->make($this->classFor($provider, 'webhook'));

        if (! $adapter instanceof WebhookAdapter) {
            throw new InvalidArgumentException("Webhook adapter for {$provider->value} must implement WebhookAdapter.");
        }

        return $adapter;
    }

    /** @return list<Provider> providers that can be connected in this environment */
    public function available(): array
    {
        /** @var array<string, array<string, mixed>> $adapters */
        $adapters = config('whatsapp.adapters', []);

        return array_values(array_filter(
            Provider::cases(),
            fn (Provider $p): bool => isset($adapters[$p->value]) && ($p !== Provider::Fake || ! app()->isProduction()),
        ));
    }

    private function classFor(Provider $provider, string $kind): string
    {
        $class = config("whatsapp.adapters.{$provider->value}.{$kind}");

        if (! is_string($class) || $class === '' || ! in_array($provider, $this->available(), true)) {
            throw new InvalidArgumentException("No {$kind} adapter for {$provider->value}.");
        }

        return $class;
    }
}
