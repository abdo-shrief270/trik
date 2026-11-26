<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;

/**
 * The one list format of the API: cursor pagination (stable under inserts, cheap on big tables).
 *
 *     GET /v1/…?per_page=50&cursor=…  →  { "data": [...], "meta": { "per_page": 50, "next_cursor": "…", "prev_cursor": null } }
 */
final class CursorPage
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * The validated cursor of the request. Cursors are client input: every value is checked
     * against the type of the column it pages on, so a crafted cursor answers 400, not 500.
     *
     * @param  array<string, 'datetime'|'uuid'|'int'>  $columns  ordering columns and their types
     */
    public static function cursor(Request $request, array $columns): ?Cursor
    {
        if (! $request->filled('cursor')) {
            return null;
        }

        $cursor = Cursor::fromEncoded($request->string('cursor')->toString());

        if ($cursor === null) {
            throw Problem::badRequest('invalid-cursor', 'The cursor is not valid. Start again from the first page.');
        }

        foreach ($columns as $column => $type) {
            $value = $cursor->parameter($column);
            $text = is_scalar($value) ? (string) $value : '';
            $ok = match ($type) {
                'uuid' => Str::isUuid($text),
                'int' => ctype_digit($text),
                'datetime' => $text !== '' && strtotime($text) !== false,
            };

            if (! $ok) {
                throw Problem::badRequest('invalid-cursor', 'The cursor is not valid. Start again from the first page.');
            }
        }

        return $cursor;
    }

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
