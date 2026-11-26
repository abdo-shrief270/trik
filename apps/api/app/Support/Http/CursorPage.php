<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one list format of the API: cursor pagination (stable under inserts, cheap on big tables).
 *
 *     GET /v1/…?per_page=50&cursor=…  →  { "data": [...], "meta": { "per_page": 50, "next_cursor": "…", "prev_cursor": null } }
 */
final class CursorPage
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public static function perPage(Request $request): int
    {
        return max(1, min(self::MAX_PER_PAGE, $request->integer('per_page', self::DEFAULT_PER_PAGE)));
    }

    /**
     * @template TItem
     *
     * @param  CursorPaginator<array-key, TItem>  $page
     * @param  callable(TItem): array<string, mixed>  $map
     */
    public static function response(CursorPaginator $page, callable $map): JsonResponse
    {
        return response()->json([
            'data' => array_values(array_map($map, $page->items())),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
            ],
        ]);
    }
}
