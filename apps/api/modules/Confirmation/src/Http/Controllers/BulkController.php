<?php

declare(strict_types=1);

namespace Modules\Confirmation\Http\Controllers;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Confirmation\Application\ConfirmationEngine;
use Modules\Orders\Application\ManageOrder;
use Modules\Orders\Application\OrderReader;
use Modules\Orders\Domain\OrderStatus;
use Modules\Workspaces\Application\AuditLog;

/**
 * Bulk actions on the board (#107): confirm, cancel or remind up to 100 orders at once.
 * Each order is handled on its own (one failure never stops the rest) and reported back;
 * the action is written to the workspace audit log and to each order's timeline.
 */
final class BulkController
{
    public const MAX = 100;

    public function store(Request $request, OrderReader $orders, ManageOrder $manage, ConfirmationEngine $engine, AuditLog $audit, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:confirm,cancel,remind'],
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'order_ids.*' => ['uuid', 'distinct'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $id = $request->user()?->getAuthIdentifier();
        $actor = is_string($id) ? $id : null;
        $results = [];

        foreach ($data['order_ids'] as $orderId) {
            if ($orders->find($orderId) === null) {
                $results[] = ['order_id' => $orderId, 'result' => 'not_found'];

                continue;
            }

            try {
                $result = match ($data['action']) {
                    'confirm' => $this->move($manage, $orderId, OrderStatus::Confirmed, $actor, null),
                    'cancel' => $this->move($manage, $orderId, OrderStatus::Cancelled, $actor, $data['reason'] ?? null),
                    default => $engine->remindNow($orderId),
                };
                $results[] = ['order_id' => $orderId, 'result' => $result];
            } catch (Problem $e) {
                $results[] = ['order_id' => $orderId, 'result' => 'error', 'code' => $e->problemCode];
            }
        }

        $done = array_values(array_filter($results, fn (array $r): bool => in_array($r['result'], ['ok', 'sent', 'started'], true)));
        $audit->workspace($tenant->require(), 'orders.bulk_'.$data['action'], $actor, null, [
            'requested' => count($data['order_ids']),
            'succeeded' => count($done),
            'order_ids' => array_column($done, 'order_id'),
        ]);

        return response()->json(['data' => $results]);
    }

    private function move(ManageOrder $manage, string $orderId, OrderStatus $to, ?string $actor, ?string $reason): string
    {
        $manage->moveById($orderId, $to, $actor, 'agent', $reason);

        return 'ok';
    }
}
