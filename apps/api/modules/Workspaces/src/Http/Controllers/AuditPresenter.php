<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use App\Support\Http\CursorPage;
use App\Support\Identity\UserLookup;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;

final class AuditPresenter
{
    /** @param CursorPaginator<array-key, object> $page */
    public static function page(CursorPaginator $page, UserLookup $users): JsonResponse
    {
        $ids = [];
        foreach ($page->items() as $row) {
            foreach (['actor_id', 'subject_user_id'] as $col) {
                if (isset($row->{$col}) && is_string($row->{$col})) {
                    $ids[] = $row->{$col};
                }
            }
        }
        $people = $users->find(array_values(array_unique($ids)));
        $person = fn (mixed $id): ?array => is_string($id) && isset($people[$id]) ? ['id' => $id, 'name' => $people[$id]['name']] : null;

        return CursorPage::response($page, function (object $row) use ($person): array {
            $r = (array) $row;

            return [
                'id' => $r['id'] ?? null,
                'action' => $r['action'] ?? null,
                'actor' => $person($r['actor_id'] ?? null),
                'subject' => $person($r['subject_user_id'] ?? null),
                'workspace_id' => $r['subject_workspace_id'] ?? $r['workspace_id'] ?? null,
                'data' => is_string($r['data'] ?? null) ? json_decode($r['data'], true) : null,
                'ip' => $r['ip'] ?? null,
                'at' => $r['created_at'] ?? null,
            ];
        });
    }
}
