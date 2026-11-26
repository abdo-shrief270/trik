<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Providers;

use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use Modules\WhatsApp\Application\Exceptions\ProviderRequestFailed;
use Modules\WhatsApp\Application\Exceptions\UnsupportedCapability;
use Modules\WhatsApp\Domain\Capability;
use Modules\WhatsApp\Domain\MediaFile;
use Modules\WhatsApp\Domain\NumberInfo;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Domain\Template;

/**
 * Meta Cloud API through Trik's own Meta app (after Tech Provider approval, #336).
 * externalId is the phone_number_id; the access token is the business integration token.
 */
final class MetaCloudProvider extends CloudApiProvider
{
    public function provider(): Provider
    {
        return Provider::MetaCloud;
    }

    public function capabilities(): array
    {
        return [
            Capability::Templates,
            Capability::Media,
            Capability::InteractiveMessages,
            Capability::MarkRead,
            Capability::NumberInfo,
        ];
    }

    public function templates(): array
    {
        $this->guard(Capability::Templates);
        $rows = $this->request('get', '/'.$this->waba().'/message_templates', [
            'limit' => 250,
            'fields' => 'id,name,language,status,category,components',
        ])['data'] ?? [];

        return array_values(array_map(fn (array $t): Template => TemplateMapper::fromCloudApi($t), is_array($rows) ? $rows : []));
    }

    public function createTemplate(string $name, string $language, string $category, array $components): string
    {
        $this->guard(Capability::Templates);
        $id = $this->request('post', '/'.$this->waba().'/message_templates', compact('name', 'language', 'category', 'components'))['id'] ?? null;

        if (! is_scalar($id)) {
            throw new ProviderRequestFailed('Template created but no id returned.', retryable: false);
        }

        return (string) $id;
    }

    public function deleteTemplate(string $name): void
    {
        $this->guard(Capability::Templates);
        $this->request('delete', '/'.$this->waba().'/message_templates', ['name' => $name]);
    }

    public function downloadMedia(string $mediaId): MediaFile
    {
        $this->guard(Capability::Media);
        $meta = $this->request('get', '/'.rawurlencode($mediaId));
        $url = $meta['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new ProviderRequestFailed("No download URL for media {$mediaId}.", retryable: false);
        }

        $response = $this->http->withToken($this->number->credential('access_token'))->timeout(60)->get($url);

        if ($response->failed()) {
            throw $this->failure($response);
        }

        $mime = $meta['mime_type'] ?? $response->header('Content-Type');

        return new MediaFile(is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream', $response->body());
    }

    public function numberInfo(): NumberInfo
    {
        $info = $this->request('get', '/'.$this->number->externalId, [
            'fields' => 'display_phone_number,verified_name,quality_rating,messaging_limit_tier',
        ]);

        return new NumberInfo(
            (string) ($info['display_phone_number'] ?? ''),
            isset($info['verified_name']) ? (string) $info['verified_name'] : null,
            isset($info['quality_rating']) ? (string) $info['quality_rating'] : null,
            isset($info['messaging_limit_tier']) ? (string) $info['messaging_limit_tier'] : null,
        );
    }

    public function configureWebhook(string $url, string $secret): void
    {
        // Meta webhooks are set once per app in the Meta dashboard, not per number.
        throw UnsupportedCapability::for($this->provider(), Capability::ConfigureWebhook);
    }

    protected function client(): PendingRequest
    {
        $base = rtrim((string) config('whatsapp.providers.meta_cloud.graph_url', 'https://graph.facebook.com'), '/')
            .'/'.config('whatsapp.providers.meta_cloud.graph_version', 'v24.0');

        return $this->base($this->http->baseUrl($base)->withToken($this->number->credential('access_token')));
    }

    protected function messagesPath(): string
    {
        return '/'.$this->number->externalId.'/messages';
    }

    private function waba(): string
    {
        return $this->number->wabaId ?? throw new InvalidArgumentException('Meta numbers need a WABA id.');
    }
}
