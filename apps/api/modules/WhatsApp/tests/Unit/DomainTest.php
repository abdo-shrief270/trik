<?php

declare(strict_types=1);

use Modules\WhatsApp\Domain\Messages\ButtonsMessage;
use Modules\WhatsApp\Domain\Messages\MediaMessage;
use Modules\WhatsApp\Domain\MessageStatus;
use Modules\WhatsApp\Domain\PhoneNumber;

it('normalises phone numbers to WhatsApp ids', function (string $raw, string $digits): void {
    expect(PhoneNumber::fromString($raw)->digits)->toBe($digits);
})->with([
    'local Egyptian' => ['01012345678', '201012345678'],
    'with plus' => ['+20 101 234 5678', '201012345678'],
    'with 00' => ['00201012345678', '201012345678'],
    'already international' => ['201012345678', '201012345678'],
    'Saudi with plus' => ['+966 50 123 4567', '966501234567'],
]);

it('rejects things that are not phone numbers', function (string $raw): void {
    PhoneNumber::fromString($raw);
})->with(['', 'abc', '123', '+0123456789'])->throws(InvalidArgumentException::class);

it('only moves message status forward', function (MessageStatus $from, MessageStatus $to, bool $allowed): void {
    expect($from->canMoveTo($to))->toBe($allowed);
})->with([
    [MessageStatus::Queued, MessageStatus::Sent, true],
    [MessageStatus::Sent, MessageStatus::Delivered, true],
    [MessageStatus::Sent, MessageStatus::Read, true],
    [MessageStatus::Read, MessageStatus::Delivered, false],
    [MessageStatus::Delivered, MessageStatus::Sent, false],
    [MessageStatus::Delivered, MessageStatus::Failed, true],
    [MessageStatus::Read, MessageStatus::Failed, false],
    [MessageStatus::Failed, MessageStatus::Delivered, false],
]);

it('limits reply buttons to three short titles', function (): void {
    new ButtonsMessage('?', ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4']);
})->throws(InvalidArgumentException::class);

it('needs exactly one media source', function (): void {
    new MediaMessage('image', link: 'https://x.test/a.jpg', mediaId: '123');
})->throws(InvalidArgumentException::class);
