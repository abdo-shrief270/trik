<?php

declare(strict_types=1);

use App\Support\Authorization\PermissionRegistry;

beforeEach(function (): void {
    $this->registry = new PermissionRegistry;
    $this->registry->register('WhatsApp', ['whatsapp.numbers.view' => 'a', 'whatsapp.numbers.manage' => 'b', 'whatsapp.messages.send' => 'c']);
    $this->registry->register('Billing', ['billing.plan.manage' => 'd']);
});

it('matches exact keys, module wildcards and everything', function (): void {
    expect($this->registry->allows(['whatsapp.numbers.view'], 'whatsapp.numbers.view'))->toBeTrue()
        ->and($this->registry->allows(['whatsapp.numbers.view'], 'whatsapp.numbers.manage'))->toBeFalse()
        ->and($this->registry->allows(['whatsapp.*'], 'whatsapp.messages.send'))->toBeTrue()
        ->and($this->registry->allows(['whatsapp.*'], 'billing.plan.manage'))->toBeFalse()
        ->and($this->registry->allows(['*'], 'billing.plan.manage'))->toBeTrue();
});

it('lets a deny beat any allow', function (): void {
    expect($this->registry->allows(['*', '!billing.*'], 'billing.plan.manage'))->toBeFalse()
        ->and($this->registry->allows(['!whatsapp.numbers.manage', 'whatsapp.*'], 'whatsapp.numbers.manage'))->toBeFalse()
        ->and($this->registry->allows(['*', '!billing.*'], 'whatsapp.numbers.manage'))->toBeTrue();
});

it('expands grants to concrete keys', function (): void {
    expect($this->registry->expand(['whatsapp.*', '!whatsapp.numbers.manage']))->toBe(['whatsapp.messages.send', 'whatsapp.numbers.view']);
});

it('only accepts well-formed keys and known grants', function (): void {
    expect(fn () => $this->registry->register('X', ['bad-key' => 'x']))->toThrow(InvalidArgumentException::class)
        ->and($this->registry->isValidGrant('whatsapp.*'))->toBeTrue()
        ->and($this->registry->isValidGrant('!billing.plan.manage'))->toBeTrue()
        ->and($this->registry->isValidGrant('orders.*'))->toBeFalse();
});
