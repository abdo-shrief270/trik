<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\WhatsApp\Application\Exceptions\ProviderRequestFailed;
use Modules\WhatsApp\Application\Exceptions\UnsupportedCapability;
use Modules\WhatsApp\Domain\Capability;
use Modules\WhatsApp\Domain\Messages\ButtonsMessage;
use Modules\WhatsApp\Domain\Messages\MediaMessage;
use Modules\WhatsApp\Domain\Messages\TemplateMessage;
use Modules\WhatsApp\Domain\Messages\TextMessage;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Tests\Fixtures\ProviderFixture;

/*
 * The WhatsApp provider contract. Every adapter must pass every test here;
 * add the new provider to the dataset when you add an adapter.
 */
dataset('providers', ['360dialog', 'meta_cloud', 'fake']);

beforeEach(fn () => Http::preventStrayRequests());

it('sends every message type and returns the provider message id', function (string $provider): void {
    $adapter = ProviderFixture::for($provider)->adapter();
    $to = PhoneNumber::fromString('01012345678');

    foreach ([
        new TextMessage('أهلاً يا منى'),
        new TemplateMessage('order_confirm', 'ar', [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'منى']]]]),
        new ButtonsMessage('أكد الأوردر؟', ['confirm' => 'تأكيد', 'cancel' => 'إلغاء']),
        new MediaMessage('image', link: 'https://example.com/p.jpg', caption: 'المنتج'),
    ] as $message) {
        expect($adapter->send($to, $message)->messageId)->toStartWith('wamid.');
    }
})->with('providers');

it('lists templates in one shape', function (string $provider): void {
    $templates = ProviderFixture::for($provider)->adapter()->templates();

    expect($templates)->toHaveCount(1)
        ->and($templates[0]->name)->toBe('order_confirm')
        ->and($templates[0]->language)->toBe('ar')
        ->and($templates[0]->status)->toBe('approved')
        ->and($templates[0]->category)->toBe('utility')
        ->and($templates[0]->components)->not->toBeEmpty();
})->with('providers');

it('creates and deletes templates', function (string $provider): void {
    $adapter = ProviderFixture::for($provider)->adapter();

    expect($adapter->createTemplate('new_one', 'ar', 'UTILITY', [['type' => 'BODY', 'text' => 'مرحبا']]))->not->toBe('');
    $adapter->deleteTemplate('order_confirm');
})->with('providers');

it('downloads media', function (string $provider): void {
    $file = ProviderFixture::for($provider)->adapter()->downloadMedia('media-1');

    expect($file->mimeType)->toBe('image/jpeg')->and($file->contents)->not->toBe('');
})->with('providers');

it('marks messages as read', function (string $provider): void {
    ProviderFixture::for($provider)->adapter()->markRead('wamid.IN1');
})->with('providers')->throwsNoExceptions();

it('keeps capabilities honest: unsupported features throw, supported ones work', function (string $provider): void {
    $adapter = ProviderFixture::for($provider)->adapter();

    $calls = [
        Capability::NumberInfo->value => fn () => $adapter->numberInfo(),
        Capability::ConfigureWebhook->value => fn () => $adapter->configureWebhook('https://api.trik.chat/webhooks/x', 'secret'),
    ];

    foreach ($calls as $capability => $call) {
        $supported = $adapter->supports(Capability::from($capability));

        if ($supported) {
            $call();
        } else {
            expect($call)->toThrow(UnsupportedCapability::class);
        }

        expect(in_array(Capability::from($capability), $adapter->capabilities(), true))->toBe($supported);
    }
})->with('providers');

it('reports throttling as retryable', function (string $provider): void {
    $fixture = ProviderFixture::for($provider);
    $fixture->failNextSend(429, '130429');
    $adapter = $fixture->adapter();

    try {
        $adapter->send(PhoneNumber::fromString('01012345678'), new TextMessage('hi'));
        $this->fail('expected a failure');
    } catch (ProviderRequestFailed $e) {
        expect($e->retryable)->toBeTrue()->and($e->providerCode)->toBe('130429');
    }
})->with('providers');

it('reports permanent errors as not retryable', function (string $provider): void {
    $fixture = ProviderFixture::for($provider);
    $fixture->failNextSend(400, '131026');
    $adapter = $fixture->adapter();

    try {
        $adapter->send(PhoneNumber::fromString('01012345678'), new TextMessage('hi'));
        $this->fail('expected a failure');
    } catch (ProviderRequestFailed $e) {
        expect($e->retryable)->toBeFalse()->and($e->httpStatus)->toBe(400);
    }
})->with('providers');
