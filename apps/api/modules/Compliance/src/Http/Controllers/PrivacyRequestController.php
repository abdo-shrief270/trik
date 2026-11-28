<?php

declare(strict_types=1);

namespace Modules\Compliance\Http\Controllers;

use App\Support\Http\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Compliance\Infrastructure\Models\PrivacyRequest;
use Modules\Workspaces\Application\AuditLog;

/** Privacy requests received for the workspace, and the data exports the store owner must hand over. */
final class PrivacyRequestController
{
    public function index(): JsonResponse
    {
        $requests = PrivacyRequest::query()->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $requests->map(fn (PrivacyRequest $r): array => $this->present($r))->values()]);
    }

    public function export(Request $request, string $privacyRequest, AuditLog $audit): JsonResponse
    {
        $model = Str::isUuid($privacyRequest) ? PrivacyRequest::query()->find($privacyRequest) : null;
        if ($model === null) {
            throw Problem::notFound('request-not-found', 'No such privacy request.');
        }
        if ($model->export === null || ($model->export_expires_at !== null && $model->export_expires_at->isPast())) {
            throw Problem::notFound('export-not-available', 'This request has no data export, or it has expired.');
        }

        $audit->workspace($model->workspace_id, 'compliance.export_downloaded', (string) $request->user()?->getAuthIdentifier(), null, ['request_id' => $model->id]);

        return response()->json(['data' => $model->export], 200, [
            'Content-Disposition' => 'attachment; filename="trik-data-request-'.$model->id.'.json"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(PrivacyRequest $r): array
    {
        return [
            'id' => $r->id,
            'kind' => $r->kind->value,
            'origin' => $r->origin,
            'origin_ref' => $r->origin_ref,
            'status' => $r->status,
            'result' => (object) $r->result,
            'export_available' => $r->export !== null && ($r->export_expires_at === null || $r->export_expires_at->isFuture()),
            'export_expires_at' => $r->export_expires_at?->toIso8601String(),
            'created_at' => $r->created_at->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
        ];
    }
}
