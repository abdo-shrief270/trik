<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Webhooks;

use Modules\WhatsApp\Domain\Inbound\InboundEvent;
use Modules\WhatsApp\Domain\Inbound\InboundMessage;
use Modules\WhatsApp\Domain\Inbound\StatusUpdate;
use Modules\WhatsApp\Domain\MessageStatus;

/**
 * Parses the Cloud API webhook shape (object → entry[] → changes[] → value), which both
 * Meta and 360dialog deliver. Unknown fields and event types are skipped, never fatal.
 */
final class CloudApiPayloadParser
{
    /**
     * @param  array<mixed>  $payload
     * @return list<InboundEvent>
     */
    public function parse(array $payload): array
    {
        $events = [];

        foreach ($this->list($payload['entry'] ?? null) as $entry) {
            foreach ($this->list($entry['changes'] ?? null) as $change) {
                if (($change['field'] ?? 'messages') !== 'messages' || ! is_array($change['value'] ?? null)) {
                    continue;
                }

                array_push($events, ...$this->value($change['value']));
            }
        }

        return $events;
    }

    /**
     * @param  array<mixed>  $value
     * @return list<InboundEvent>
     */
    private function value(array $value): array
    {
        $numberId = (string) data_get($value, 'metadata.phone_number_id', '');
        $names = [];

        foreach ($this->list($value['contacts'] ?? null) as $contact) {
            $names[(string) ($contact['wa_id'] ?? '')] = data_get($contact, 'profile.name');
        }

        $events = [];

        foreach ($this->list($value['messages'] ?? null) as $m) {
            $type = (string) ($m['type'] ?? 'unsupported');
            $from = (string) ($m['from'] ?? '');
            $body = is_array($m[$type] ?? null) ? $m[$type] : [];
            $name = $names[$from] ?? null;

            $events[] = new InboundMessage(
                businessPhoneNumberId: $numberId,
                messageId: (string) ($m['id'] ?? ''),
                from: $from,
                type: $type,
                timestamp: (int) ($m['timestamp'] ?? 0),
                contactName: is_string($name) ? $name : null,
                text: $this->text($type, $body),
                mediaId: isset($body['id']) && in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true) ? (string) $body['id'] : null,
                mimeType: isset($body['mime_type']) ? (string) $body['mime_type'] : null,
                replyId: $this->replyId($type, $body),
                contextMessageId: is_string(data_get($m, 'context.id')) ? data_get($m, 'context.id') : null,
                raw: $m,
            );
        }

        foreach ($this->list($value['statuses'] ?? null) as $s) {
            $status = MessageStatus::tryFrom((string) ($s['status'] ?? ''));

            if ($status === null || $status === MessageStatus::Queued || $status === MessageStatus::Received) {
                continue;
            }

            $errors = $this->list($s['errors'] ?? null);
            $category = data_get($s, 'pricing.category');

            $events[] = new StatusUpdate(
                businessPhoneNumberId: $numberId,
                messageId: (string) ($s['id'] ?? ''),
                status: $status,
                recipient: (string) ($s['recipient_id'] ?? ''),
                timestamp: (int) ($s['timestamp'] ?? 0),
                errors: $errors,
                pricingCategory: is_string($category) ? $category : null,
            );
        }

        return array_values(array_filter($events, fn (InboundEvent $e): bool => $e instanceof InboundMessage ? $e->messageId !== '' && $e->from !== '' : $e->messageId !== ''));
    }

    /** @param array<mixed> $body */
    private function text(string $type, array $body): ?string
    {
        $text = match ($type) {
            'text' => $body['body'] ?? null,
            'image', 'video', 'document' => $body['caption'] ?? null,
            'button' => $body['text'] ?? null,
            'interactive' => data_get($body, 'button_reply.title') ?? data_get($body, 'list_reply.title'),
            'reaction' => $body['emoji'] ?? null,
            default => null,
        };

        return is_string($text) ? $text : null;
    }

    /** @param array<mixed> $body */
    private function replyId(string $type, array $body): ?string
    {
        $id = match ($type) {
            'interactive' => data_get($body, 'button_reply.id') ?? data_get($body, 'list_reply.id'),
            'button' => $body['payload'] ?? null,
            default => null,
        };

        return is_string($id) ? $id : null;
    }

    /** @return list<array<mixed>> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
