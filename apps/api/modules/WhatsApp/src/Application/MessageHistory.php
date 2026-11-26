<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Modules\WhatsApp\Infrastructure\Models\Message;

/** A customer's conversation messages across the workspace's numbers, newest first (for the inbox). */
final class MessageHistory
{
    /** Cursor columns and their types, for CursorPage::cursor(). */
    public const CURSOR = ['created_at' => 'datetime', 'id' => 'uuid'];

    /** @return CursorPaginator<int, array<string, mixed>> */
    public function forContact(string $contactPhone, int $perPage, ?Cursor $cursor = null): CursorPaginator
    {
        $page = Message::query()
            ->where('contact_phone', $contactPhone)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, cursor: $cursor);

        /** @var CursorPaginator<int, array<string, mixed>> */
        return $page->through(fn (Message $m): array => $m->toApi());
    }
}
