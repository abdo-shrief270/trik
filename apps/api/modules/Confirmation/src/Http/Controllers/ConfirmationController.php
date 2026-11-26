<?php

declare(strict_types=1);

namespace Modules\Confirmation\Http\Controllers;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Confirmation\Infrastructure\Models\ConfirmationRun;
use Modules\Confirmation\Infrastructure\Models\ConfirmationSettings;
use Modules\Orders\Application\OrderReader;
use Modules\WhatsApp\Application\NumberDirectory;

final class ConfirmationController
{
    public function settings(): JsonResponse
    {
        return response()->json(['data' => ConfirmationSettings::current()->toApi()]);
    }

    public function updateSettings(Request $request, TenantContext $tenant, NumberDirectory $numbers): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'number_id' => ['sometimes', 'nullable', 'uuid'],
            'template_name' => ['sometimes', 'nullable', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'template_language' => ['sometimes', 'string', 'max:16', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
            'reminder_template_name' => ['sometimes', 'nullable', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'reminder_after_minutes' => ['sometimes', 'integer', 'between:5,10080'],
            'max_reminders' => ['sometimes', 'integer', 'between:0,5'],
            'escalate_after_minutes' => ['sometimes', 'integer', 'between:5,10080'],
            'confirmed_reply' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'cancelled_reply' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'change_reply' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ]);

        if (($data['number_id'] ?? null) !== null && ! $numbers->isActive($data['number_id'])) {
            throw Problem::invalid(['number_id' => ['No such connected number in this workspace.']]);
        }

        $settings = ConfirmationSettings::current();
        $settings->fill($data);

        if ($settings->enabled && $settings->template_name === null) {
            throw Problem::invalid(['template_name' => ['Choose the approved confirmation template before turning confirmation on.']]);
        }

        $settings->workspace_id = $tenant->require();
        $settings->save();

        return response()->json(['data' => $settings->toApi()]);
    }

    /** Where the order's confirmation stands. */
    public function forOrder(string $order, OrderReader $orders): JsonResponse
    {
        if ($orders->find($order) === null) {
            throw Problem::notFound();
        }

        $run = ConfirmationRun::query()->where('order_id', $order)->first();

        return response()->json(['data' => $run?->toApi()]);
    }
}
