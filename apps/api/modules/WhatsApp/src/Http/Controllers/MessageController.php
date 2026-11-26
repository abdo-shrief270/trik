<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Http\Controllers;

use App\Support\Http\CursorPage;
use App\Support\Http\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\WhatsApp\Application\SendMessage;
use Modules\WhatsApp\Domain\Messages\ButtonsMessage;
use Modules\WhatsApp\Domain\Messages\MediaMessage;
use Modules\WhatsApp\Domain\Messages\OutgoingMessage;
use Modules\WhatsApp\Domain\Messages\TemplateMessage;
use Modules\WhatsApp\Domain\Messages\TextMessage;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Infrastructure\Models\Message;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;

final class MessageController
{
    public function index(Request $request, string $number): JsonResponse
    {
        $page = Message::query()
            ->where('number_id', WhatsAppNumber::query()->findOrFail($number)->id)
            ->when($request->filled('contact'), fn ($q) => $q->where('contact_phone', PhoneNumber::fromString($request->string('contact')->toString(), $this->country())->digits))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::response($page, fn (Message $m): array => $m->toApi());
    }

    public function store(Request $request, string $number, SendMessage $send): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:32'],
            'type' => ['required', 'in:text,template,buttons,media'],
            'reply_to' => ['nullable', 'string', 'max:128'],
            'text' => ['required_if:type,text', 'string'],
            'template' => ['required_if:type,template', 'array'],
            'template.name' => ['required_if:type,template', 'string'],
            'template.language' => ['required_if:type,template', 'string'],
            'template.components' => ['sometimes', 'array'],
            'buttons' => ['required_if:type,buttons', 'array'],
            'body' => ['required_if:type,buttons', 'string'],
            'media' => ['required_if:type,media', 'array'],
        ]);

        try {
            $to = PhoneNumber::fromString($data['to'], $this->country());
            $message = $this->message($data);
        } catch (InvalidArgumentException $e) {
            throw Problem::invalid([str_contains($e->getMessage(), 'phone') ? 'to' : 'message' => [$e->getMessage()]]);
        }

        $row = $send->handle(WhatsAppNumber::query()->findOrFail($number), $to, $message, $data['reply_to'] ?? null);

        return response()->json(['data' => $row->refresh()->toApi()], 202);
    }

    /** @param array<string, mixed> $data */
    private function message(array $data): OutgoingMessage
    {
        return match ($data['type']) {
            'text' => new TextMessage((string) $data['text']),
            'template' => new TemplateMessage(
                (string) data_get($data, 'template.name'),
                (string) data_get($data, 'template.language'),
                array_values((array) data_get($data, 'template.components', [])),
            ),
            'buttons' => new ButtonsMessage((string) $data['body'], array_map('strval', (array) $data['buttons'])),
            default => new MediaMessage(
                (string) data_get($data, 'media.kind'),
                data_get($data, 'media.link'),
                data_get($data, 'media.id'),
                data_get($data, 'media.caption'),
                data_get($data, 'media.filename'),
            ),
        };
    }

    private function country(): string
    {
        return (string) config('whatsapp.default_country_code', '20');
    }
}
