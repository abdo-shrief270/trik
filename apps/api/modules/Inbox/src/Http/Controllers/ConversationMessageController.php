<?php

declare(strict_types=1);

namespace Modules\Inbox\Http\Controllers;

use App\Support\Http\CursorPage;
use App\Support\Http\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Contacts\Application\ContactDirectory;
use Modules\Inbox\Infrastructure\Models\Conversation;
use Modules\WhatsApp\Application\MessageHistory;
use Modules\WhatsApp\Application\OutgoingMessages;
use Modules\WhatsApp\Application\SendMessage;
use Modules\WhatsApp\Domain\PhoneNumber;

final class ConversationMessageController
{
    /** The customer's messages across the workspace's numbers, newest first. */
    public function index(Request $request, string $conversation, ContactDirectory $contacts, MessageHistory $history): JsonResponse
    {
        $phone = $this->phone(Conversation::query()->findOrFail($conversation), $contacts);
        $page = $history->forContact($phone, CursorPage::perPage($request), CursorPage::cursor($request, MessageHistory::CURSOR));

        return CursorPage::response($page, fn (array $m): array => $m);
    }

    /**
     * Reply from the number the customer last wrote to. Free-form messages need the 24-hour window
     * to be open; after that only templates go out (WhatsApp would reject anything else).
     */
    public function store(Request $request, string $conversation, ContactDirectory $contacts, SendMessage $send): JsonResponse
    {
        $data = $request->validate(OutgoingMessages::rules());
        $model = Conversation::query()->findOrFail($conversation);

        if ($data['type'] !== 'template' && ! $model->window()->allowsFreeForm()) {
            throw Problem::conflict('window-closed', 'The customer last wrote more than 24 hours ago. Only a template can be sent until they write again.');
        }

        if ($model->number_id === null) {
            throw Problem::conflict('no-number', 'The number this customer wrote to is no longer connected. Send from a connected number instead.');
        }

        try {
            $message = OutgoingMessages::fromArray($data);
            $to = PhoneNumber::fromString($this->phone($model, $contacts));
        } catch (InvalidArgumentException $e) {
            throw Problem::invalid(['message' => [$e->getMessage()]]);
        }

        $id = $request->user()?->getAuthIdentifier();
        $row = $send->fromNumber($model->number_id, $to, $message, $data['reply_to'] ?? null, is_string($id) ? $id : null);

        return response()->json(['data' => $row->refresh()->toApi()], 202);
    }

    private function phone(Conversation $conversation, ContactDirectory $contacts): string
    {
        return $contacts->find([$conversation->contact_id])[$conversation->contact_id]['phone']
            ?? throw Problem::notFound();
    }
}
