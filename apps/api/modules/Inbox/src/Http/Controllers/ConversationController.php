<?php

declare(strict_types=1);

namespace Modules\Inbox\Http\Controllers;

use App\Support\Http\CursorPage;
use App\Support\Http\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Inbox\Application\ConversationPresenter;
use Modules\Inbox\Application\ManageConversation;
use Modules\Inbox\Domain\ConversationStatus;
use Modules\Inbox\Infrastructure\Models\Conversation;

final class ConversationController
{
    /**
     * The inbox list, newest activity first.
     * Filters: status=open|closed|all (default open), assignee=me|unassigned|{user id}, unread=1.
     */
    public function index(Request $request, ConversationPresenter $presenter): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'closed', 'all'])],
            'assignee' => ['sometimes', 'string'],
            'unread' => ['sometimes', 'boolean'],
        ]);

        $status = $request->string('status', 'open')->toString();
        $assignee = $request->string('assignee')->toString();

        if ($assignee !== '' && ! in_array($assignee, ['me', 'unassigned'], true) && ! Str::isUuid($assignee)) {
            throw Problem::invalid(['assignee' => ['Use "me", "unassigned" or a user id.']]);
        }

        $page = Conversation::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($assignee === 'me', fn ($q) => $q->where('assignee_id', $this->userId($request)))
            ->when($assignee === 'unassigned', fn ($q) => $q->whereNull('assignee_id'))
            ->when(Str::isUuid($assignee), fn ($q) => $q->where('assignee_id', $assignee))
            ->when($request->boolean('unread'), fn ($q) => $q->where('unread_count', '>', 0))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request), cursor: CursorPage::cursor($request, ['last_message_at' => 'datetime', 'id' => 'uuid']));

        /** @var list<Conversation> $items */
        $items = array_values($page->items());
        $shown = [];
        foreach ($presenter->many($items) as $i => $data) {
            $shown[$items[$i]->id] = $data;
        }

        return CursorPage::response($page, fn (Conversation $c): array => $shown[$c->id]);
    }

    public function show(string $conversation, ConversationPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $presenter->one(Conversation::query()->findOrFail($conversation))]);
    }

    /** Close or reopen, assign or unassign. */
    public function update(Request $request, string $conversation, ManageConversation $manage, ConversationPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(ConversationStatus::class)],
            'assignee_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $model = Conversation::query()->findOrFail($conversation);

        if (array_key_exists('assignee_id', $data)) {
            $model = $manage->assign($model, $data['assignee_id'], $this->userId($request), Gate::allows('inbox.conversations.assign'));
        }

        if (isset($data['status'])) {
            $model = $manage->setStatus($model, ConversationStatus::from($data['status']));
        }

        return response()->json(['data' => $presenter->one($model)]);
    }

    public function read(string $conversation, ManageConversation $manage, ConversationPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $presenter->one($manage->markRead(Conversation::query()->findOrFail($conversation)))]);
    }

    private function userId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : throw Problem::make(401, 'unauthenticated');
    }
}
