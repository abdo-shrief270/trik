<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Http\Controllers;

use App\Support\Http\CursorPage;
use App\Support\Http\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\WhatsApp\Application\OutgoingMessages;
use Modules\WhatsApp\Application\SendMessage;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Infrastructure\Models\Message;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;

final class MessageController
{
    public function index(Request $request, string $number): JsonResponse
    {
        try {
            $contact = $request->filled('contact') ? PhoneNumber::fromString($request->string('contact')->toString(), $this->country())->digits : null;
        } catch (InvalidArgumentException $e) {
            throw Problem::invalid(['contact' => [$e->getMessage()]]);
        }

        $page = Message::query()
            ->where('number_id', WhatsAppNumber::query()->findOrFail($number)->id)
            ->when($contact !== null, fn ($q) => $q->where('contact_phone', $contact))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request), cursor: CursorPage::cursor($request, ['created_at' => 'datetime', 'id' => 'uuid']));

        return CursorPage::response($page, fn (Message $m): array => $m->toApi());
    }

    public function store(Request $request, string $number, SendMessage $send): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:32'], ...OutgoingMessages::rules()]);

        try {
            $to = PhoneNumber::fromString($data['to'], $this->country());
            $message = OutgoingMessages::fromArray($data);
        } catch (InvalidArgumentException $e) {
            throw Problem::invalid([str_contains($e->getMessage(), 'phone') ? 'to' : 'message' => [$e->getMessage()]]);
        }

        $row = $send->handle(WhatsAppNumber::query()->findOrFail($number), $to, $message, $data['reply_to'] ?? null, self::userId($request));

        return response()->json(['data' => $row->refresh()->toApi()], 202);
    }

    private static function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }

    private function country(): string
    {
        return (string) config('whatsapp.default_country_code', '20');
    }
}
