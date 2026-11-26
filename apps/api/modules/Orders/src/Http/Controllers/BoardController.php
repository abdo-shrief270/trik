<?php

declare(strict_types=1);

namespace Modules\Orders\Http\Controllers;

use App\Support\Http\CursorPage;
use App\Support\Http\Problem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Addresses\Domain\Governorate;
use Modules\Orders\Application\OrderPresenter;
use Modules\Orders\Domain\BoardStage;
use Modules\Orders\Infrastructure\Models\Order;

/**
 * The confirmation board (#107): one column at a time (paged, oldest waiting first), with the
 * counts of every column under the same filters in one grouped query.
 */
final class BoardController
{
    public function index(Request $request, OrderPresenter $presenter): JsonResponse
    {
        $request->validate([
            'stage' => ['sometimes', Rule::enum(BoardStage::class)],
            'owner' => ['sometimes', 'string'],
            'governorate' => ['sometimes', Rule::enum(Governorate::class)],
            'min_total' => ['sometimes', 'integer', 'min:0'],
            'max_total' => ['sometimes', 'integer', 'min:0'],
            'min_risk' => ['sometimes', 'integer', 'between:0,100'],
            'placed_from' => ['sometimes', 'date'],
            'placed_to' => ['sometimes', 'date'],
        ]);

        $owner = $request->string('owner')->toString();
        if ($owner !== '' && ! in_array($owner, ['me', 'unassigned'], true) && ! Str::isUuid($owner)) {
            throw Problem::invalid(['owner' => ['Use "me", "unassigned" or a user id.']]);
        }

        $filtered = fn (): Builder => $this->filters(Order::query(), $request, $owner);
        $stage = BoardStage::tryFrom($request->string('stage', BoardStage::New->value)->toString()) ?? BoardStage::New;

        $counts = array_fill_keys(array_map(fn (BoardStage $s): string => $s->value, BoardStage::cases()), 0);
        foreach ($filtered()->toBase()->selectRaw('stage, count(*) as n')->groupBy('stage')->get() as $row) {
            $counts[(string) $row->stage] = (int) $row->n;
        }

        // the ones waiting longest first; done columns newest first
        $done = in_array($stage, [BoardStage::Confirmed, BoardStage::Cancelled], true);
        $page = $filtered()
            ->where('stage', $stage->value)
            ->orderBy('placed_at', $done ? 'desc' : 'asc')
            ->orderBy('id', $done ? 'desc' : 'asc')
            ->cursorPaginate(CursorPage::perPage($request), cursor: CursorPage::cursor($request, ['placed_at' => 'datetime', 'id' => 'uuid']));

        /** @var list<Order> $orders */
        $orders = array_values($page->items());
        $shown = array_combine(array_map(fn (Order $o): string => $o->id, $orders), $presenter->many($orders));
        $now = now();

        $response = CursorPage::response($page, fn (Order $o): array => [
            ...$shown[$o->id],
            // the ageing timer: how long it has waited in this column
            'waiting_seconds' => (int) ($o->needs_call_at ?? $o->placed_at)->diffInSeconds($now, true),
        ]);

        return $response->setData([...(array) $response->getData(true), 'counts' => $counts, 'stage' => $stage->value]);
    }

    /**
     * @param  Builder<Order>  $q
     * @return Builder<Order>
     */
    private function filters(Builder $q, Request $request, string $owner): Builder
    {
        $id = $request->user()?->getAuthIdentifier();

        return $q
            ->when($owner === 'me', fn ($q) => $q->where('owner_id', is_string($id) ? $id : ''))
            ->when($owner === 'unassigned', fn ($q) => $q->whereNull('owner_id'))
            ->when(Str::isUuid($owner), fn ($q) => $q->where('owner_id', $owner))
            ->when($request->filled('governorate'), fn ($q) => $q->whereRaw("address->>'governorate' = ?", [$request->string('governorate')->toString()]))
            ->when($request->filled('min_total'), fn ($q) => $q->where('total', '>=', $request->integer('min_total')))
            ->when($request->filled('max_total'), fn ($q) => $q->where('total', '<=', $request->integer('max_total')))
            ->when($request->filled('min_risk'), fn ($q) => $q->where('risk_score', '>=', $request->integer('min_risk')))
            ->when($request->filled('placed_from'), fn ($q) => $q->where('placed_at', '>=', $request->date('placed_from')))
            ->when($request->filled('placed_to'), fn ($q) => $q->where('placed_at', '<=', $request->date('placed_to')));
    }
}
