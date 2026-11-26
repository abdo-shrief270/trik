<?php

declare(strict_types=1);

use Modules\WhatsApp\Domain\Inbound\InboundMessage;
use Modules\WhatsApp\Domain\Inbound\StatusUpdate;
use Modules\WhatsApp\Domain\MessageStatus;
use Modules\WhatsApp\Infrastructure\Webhooks\CloudApiPayloadParser;
use Modules\WhatsApp\Tests\Fixtures\CloudApi;

it('parses a text message with the contact name', function (): void {
    [$m] = (new CloudApiPayloadParser)->parse(CloudApi::inboundText());

    expect($m)->toBeInstanceOf(InboundMessage::class)
        ->and($m->businessPhoneNumberId)->toBe('PNID-1')
        ->and($m->messageId)->toBe('wamid.IN1')
        ->and($m->from)->toBe('201012345678')
        ->and($m->contactName)->toBe('Mona')
        ->and($m->text)->toBe('عايز أأكد الأوردر')
        ->and($m->timestamp)->toBe(1764150000);
});

it('parses a button reply with its id and the message it answers', function (): void {
    [$m] = (new CloudApiPayloadParser)->parse(CloudApi::buttonReply(replyId: 'confirm'));

    expect($m->type)->toBe('interactive')
        ->and($m->replyId)->toBe('confirm')
        ->and($m->text)->toBe('تأكيد')
        ->and($m->contextMessageId)->toBe('wamid.OUT1');
});

it('parses media with its id, type and caption', function (): void {
    [$m] = (new CloudApiPayloadParser)->parse(CloudApi::image());

    expect($m->mediaId)->toBe('media-1')->and($m->mimeType)->toBe('image/jpeg')->and($m->text)->toBe('العنوان');
});

it('parses status updates with errors and pricing', function (): void {
    [$s] = (new CloudApiPayloadParser)->parse(CloudApi::status('wamid.OUT1', 'failed'));

    expect($s)->toBeInstanceOf(StatusUpdate::class)
        ->and($s->status)->toBe(MessageStatus::Failed)
        ->and($s->errors[0]['code'])->toBe(131026)
        ->and($s->pricingCategory)->toBe('utility');
});

it('skips what it does not understand instead of failing', function (): void {
    $parser = new CloudApiPayloadParser;

    expect($parser->parse([]))->toBe([])
        ->and($parser->parse(['entry' => [['changes' => [['field' => 'account_update', 'value' => ['x' => 1]]]]]]))->toBe([])
        ->and($parser->parse(CloudApi::wrap('PNID-1', ['statuses' => [['id' => 'wamid.X', 'status' => 'weird']]])))->toBe([])
        ->and($parser->parse(CloudApi::wrap('PNID-1', ['messages' => [['type' => 'text']]])))->toBe([]);
});
