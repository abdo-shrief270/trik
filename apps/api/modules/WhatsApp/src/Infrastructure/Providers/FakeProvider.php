<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Providers;

use Illuminate\Support\Str;
use Modules\WhatsApp\Application\ConnectedNumber;
use Modules\WhatsApp\Application\Contracts\WhatsAppProvider;
use Modules\WhatsApp\Domain\Capability;
use Modules\WhatsApp\Domain\MediaFile;
use Modules\WhatsApp\Domain\Messages\OutgoingMessage;
use Modules\WhatsApp\Domain\NumberInfo;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Domain\SendResult;
use Modules\WhatsApp\Domain\Template;

/** Local development and tests. Never available in production. */
final class FakeProvider implements WhatsAppProvider
{
    public function __construct(private readonly ConnectedNumber $number, private readonly FakeWhatsApp $state) {}

    public function provider(): Provider
    {
        return Provider::Fake;
    }

    public function capabilities(): array
    {
        return Capability::cases();
    }

    public function supports(Capability $capability): bool
    {
        return true;
    }

    public function send(PhoneNumber $to, OutgoingMessage $message, ?string $replyToMessageId = null): SendResult
    {
        if ($failure = $this->state->takeFailure()) {
            throw $failure;
        }

        $id = 'wamid.fake.'.Str::lower(Str::random(24));
        $this->state->sent[] = ['number' => $this->number->id, 'to' => $to, 'message' => $message, 'id' => $id];

        return new SendResult($id);
    }

    public function markRead(string $messageId): void
    {
        $this->state->read[] = $messageId;
    }

    public function templates(): array
    {
        return array_values($this->state->templates);
    }

    public function createTemplate(string $name, string $language, string $category, array $components): string
    {
        $this->state->templates[$name] = new Template($name, $language, 'pending', strtolower($category), $components, 'fake-'.$name);

        return 'fake-'.$name;
    }

    public function deleteTemplate(string $name): void
    {
        unset($this->state->templates[$name]);
    }

    public function downloadMedia(string $mediaId): MediaFile
    {
        return new MediaFile('image/jpeg', 'fake-bytes-'.$mediaId);
    }

    public function numberInfo(): NumberInfo
    {
        return new NumberInfo('+20 100 000 0000', 'Trik Test Store', 'GREEN', 'TIER_1K');
    }

    public function configureWebhook(string $url, string $secret): void
    {
        $this->state->webhooks[$this->number->id] = ['url' => $url, 'secret' => $secret];
    }
}
