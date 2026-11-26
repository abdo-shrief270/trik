<?php

declare(strict_types=1);

namespace Modules\Inbox\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Inbox\Infrastructure\Models\Conversation;
use Modules\Orders\Application\OrderPresenter;

final class ConversationOrderController
{
    /** The order card (#105): the customer's latest orders with items, totals, address, delivery and next action. */
    public function index(string $conversation, OrderPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $presenter->forContact(Conversation::query()->findOrFail($conversation)->contact_id)]);
    }
}
