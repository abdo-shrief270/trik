<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Tests\Fixtures;

use Illuminate\Support\Facades\Http;
use Modules\WhatsApp\Application\ConnectedNumber;
use Modules\WhatsApp\Application\Contracts\WhatsAppProvider;
use Modules\WhatsApp\Application\Exceptions\ProviderRequestFailed;
use Modules\WhatsApp\Application\ProviderRegistry;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Domain\Template;
use Modules\WhatsApp\Infrastructure\Providers\FakeWhatsApp;

/**
 * Sets up each provider's remote API (faked) so the same contract tests can run
 * against every adapter. Responses follow each provider's documented shapes.
 */
final class ProviderFixture
{
    public const TEMPLATE = ['name' => 'order_confirm', 'language' => 'ar', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'أهلاً {{1}}، أكد أوردرك']]];

    private function __construct(public readonly Provider $provider) {}

    public static function for(string $provider): self
    {
        return new self(Provider::from($provider));
    }

    public function adapter(): WhatsAppProvider
    {
        $this->arrange();

        return app(ProviderRegistry::class)->for(new ConnectedNumber(
            'number-1',
            $this->provider,
            'PNID-1',
            'WABA-1',
            ['api_key' => 'd360-key', 'access_token' => 'meta-token'],
        ));
    }

    /** Makes the next send fail with this HTTP status and provider error code. Call before adapter(). */
    public function failNextSend(int $status, string $code): void
    {
        if ($this->provider === Provider::Fake) {
            app(FakeWhatsApp::class)->failNext(new ProviderRequestFailed('fake failure', $status === 429 || $status >= 500, $status, $code));

            return;
        }

        Http::fake([$this->base().'/*messages' => Http::response(['error' => ['message' => 'Failure '.$code, 'code' => (int) $code]], $status)]);
    }

    private function arrange(): void
    {
        $message = ['messaging_product' => 'whatsapp', 'contacts' => [['input' => '201012345678', 'wa_id' => '201012345678']], 'messages' => [['id' => 'wamid.TEST1']]];
        $media = ['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1&ext=2', 'mime_type' => 'image/jpeg', 'id' => 'media-1'];

        match ($this->provider) {
            Provider::Dialog360 => Http::fake([
                'waba-v2.360dialog.io/messages' => Http::response($message),
                'waba-v2.360dialog.io/v1/configs/templates/*' => Http::response([], 200),
                'waba-v2.360dialog.io/v1/configs/templates*' => fn ($request) => $request->method() === 'POST'
                    ? Http::response(['id' => 'tpl-1', 'name' => 'new_one', 'status' => 'submitted'], 201)
                    : Http::response(['count' => 1, 'filters' => [], 'limit' => 1000, 'offset' => 0, 'sort' => ['id'], 'total' => 1, 'waba_templates' => [self::TEMPLATE]]),
                'waba-v2.360dialog.io/v1/configs/webhook' => Http::response(['url' => 'set']),
                'waba-v2.360dialog.io/whatsapp_business/attachments/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
                'waba-v2.360dialog.io/media-1' => Http::response($media),
            ]),
            Provider::MetaCloud => Http::fake([
                'graph.facebook.com/v24.0/PNID-1/messages' => Http::response($message),
                'graph.facebook.com/v24.0/WABA-1/message_templates*' => fn ($request) => match ($request->method()) {
                    'POST' => Http::response(['id' => 'tpl-1', 'status' => 'PENDING', 'category' => 'UTILITY']),
                    'DELETE' => Http::response(['success' => true]),
                    default => Http::response(['data' => [[...self::TEMPLATE, 'id' => '987']], 'paging' => []]),
                },
                'graph.facebook.com/v24.0/media-1' => Http::response($media),
                'graph.facebook.com/v24.0/PNID-1*' => Http::response(['display_phone_number' => '+20 100 000 0000', 'verified_name' => 'Trik Test Store', 'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K', 'id' => 'PNID-1']),
                'lookaside.fbsbx.com/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
            ]),
            Provider::Fake => app(FakeWhatsApp::class)->templates = [
                'order_confirm' => new Template('order_confirm', 'ar', 'approved', 'utility', self::TEMPLATE['components']),
            ],
        };
    }

    private function base(): string
    {
        return $this->provider === Provider::Dialog360 ? 'waba-v2.360dialog.io' : 'graph.facebook.com/v24.0';
    }
}
