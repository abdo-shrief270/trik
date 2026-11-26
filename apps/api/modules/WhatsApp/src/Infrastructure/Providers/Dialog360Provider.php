<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Providers;

use Illuminate\Http\Client\PendingRequest;
use Modules\WhatsApp\Application\Exceptions\ProviderRequestFailed;
use Modules\WhatsApp\Application\Exceptions\UnsupportedCapability;
use Modules\WhatsApp\Domain\Capability;
use Modules\WhatsApp\Domain\MediaFile;
use Modules\WhatsApp\Domain\NumberInfo;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Domain\Template;

/**
 * 360dialog Cloud API (waba-v2). Each connected number (channel) has its own API key.
 * https://docs.360dialog.com/docs/messaging-api
 */
final class Dialog360Provider extends CloudApiProvider
{
    public const WEBHOOK_SECRET_HEADER = 'X-Trik-Webhook-Secret';

    public function provider(): Provider
    {
        return Provider::Dialog360;
    }

    public function capabilities(): array
    {
        return [
            Capability::Templates,
            Capability::Media,
            Capability::InteractiveMessages,
            Capability::MarkRead,
            Capability::ConfigureWebhook,
        ];
    }

    public function templates(): array
    {
        $this->guard(Capability::Templates);
        $rows = $this->request('get', '/v1/configs/templates', ['limit' => 1000])['waba_templates'] ?? [];

        return array_values(array_map(fn (array $t): Template => TemplateMapper::fromCloudApi($t), is_array($rows) ? $rows : []));
    }

    public function createTemplate(string $name, string $language, string $category, array $components): string
    {
        $this->guard(Capability::Templates);
        $response = $this->request('post', '/v1/configs/templates', compact('name', 'language', 'category', 'components'));
        $id = $response['id'] ?? $name;

        return is_scalar($id) ? (string) $id : $name;
    }

    public function deleteTemplate(string $name): void
    {
        $this->guard(Capability::Templates);
        $this->request('delete', '/v1/configs/templates/'.rawurlencode($name));
    }

    public function downloadMedia(string $mediaId): MediaFile
    {
        $this->guard(Capability::Media);
        $meta = $this->request('get', '/'.rawurlencode($mediaId));
        $url = $meta['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new ProviderRequestFailed("No download URL for media {$mediaId}.", retryable: false);
        }

        // 360dialog proxies Meta's media host: same path and query, on the 360dialog host, with the API key.
        $parts = parse_url($url);
        $proxied = rtrim($this->baseUrl(), '/').($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $response = $this->http->withHeaders($this->authHeaders())->timeout(60)->get($proxied);

        if ($response->failed()) {
            throw $this->failure($response);
        }

        $mime = $meta['mime_type'] ?? $response->header('Content-Type');

        return new MediaFile(is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream', $response->body());
    }

    public function numberInfo(): NumberInfo
    {
        throw UnsupportedCapability::for($this->provider(), Capability::NumberInfo);
    }

    public function configureWebhook(string $url, string $secret): void
    {
        $this->guard(Capability::ConfigureWebhook);
        $this->request('post', '/v1/configs/webhook', ['url' => $url, 'headers' => [self::WEBHOOK_SECRET_HEADER => $secret]]);
    }

    protected function client(): PendingRequest
    {
        return $this->base($this->http->baseUrl($this->baseUrl())->withHeaders($this->authHeaders()));
    }

    protected function messagesPath(): string
    {
        return '/messages';
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        return ['D360-API-KEY' => $this->number->credential('api_key')];
    }

    private function baseUrl(): string
    {
        return (string) config('whatsapp.providers.360dialog.base_url', 'https://waba-v2.360dialog.io');
    }
}
