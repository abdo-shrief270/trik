<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Contracts;

use Modules\WhatsApp\Application\Exceptions\ProviderRequestFailed;
use Modules\WhatsApp\Application\Exceptions\UnsupportedCapability;
use Modules\WhatsApp\Domain\Capability;
use Modules\WhatsApp\Domain\MediaFile;
use Modules\WhatsApp\Domain\Messages\OutgoingMessage;
use Modules\WhatsApp\Domain\NumberInfo;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Domain\SendResult;
use Modules\WhatsApp\Domain\Template;

/**
 * Everything Trik does with WhatsApp for one connected number.
 * One adapter per provider (360dialog now, Meta Cloud API later); all of them
 * pass the same contract tests (modules/WhatsApp/tests/Feature/ProviderContractTest.php).
 *
 * Methods for optional features throw UnsupportedCapability when capabilities() lacks them.
 * Remote failures throw ProviderRequestFailed, which says whether a retry can help.
 *
 * @throws ProviderRequestFailed
 * @throws UnsupportedCapability
 */
interface WhatsAppProvider
{
    public function provider(): Provider;

    /** @return list<Capability> */
    public function capabilities(): array;

    public function supports(Capability $capability): bool;

    public function send(PhoneNumber $to, OutgoingMessage $message, ?string $replyToMessageId = null): SendResult;

    public function markRead(string $messageId): void;

    /** @return list<Template> */
    public function templates(): array;

    /**
     * @param  list<array<string, mixed>>  $components
     * @return string the provider's template id (or name when it has no ids)
     */
    public function createTemplate(string $name, string $language, string $category, array $components): string;

    public function deleteTemplate(string $name): void;

    public function downloadMedia(string $mediaId): MediaFile;

    public function numberInfo(): NumberInfo;

    /** Points the provider's webhook for this number at Trik, with a shared secret header. */
    public function configureWebhook(string $url, string $secret): void;
}
