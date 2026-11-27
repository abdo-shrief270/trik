<?php

declare(strict_types=1);

namespace Modules\Compliance\Application;

use App\Support\Privacy\DataSubject;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Compliance\Application\Jobs\ProcessPrivacyRequest;
use Modules\Compliance\Domain\RequestKind;
use Modules\Compliance\Infrastructure\Models\PrivacyRequest;
use Modules\Workspaces\Application\AuditLog;

/**
 * Takes a privacy request in (inside its workspace) and queues the work. The same delivery again is
 * a no-op, so a platform's retries never export or redact twice.
 */
final class RecordPrivacyRequest
{
    public function __construct(private readonly AuditLog $audit) {}

    public function handle(RequestKind $kind, string $origin, ?string $originRef, string $deliveryId, DataSubject $subject): ?PrivacyRequest
    {
        try {
            return DB::transaction(function () use ($kind, $origin, $originRef, $deliveryId, $subject): PrivacyRequest {
                $request = PrivacyRequest::query()->create([
                    'kind' => $kind,
                    'origin' => $origin,
                    'origin_ref' => $originRef,
                    'delivery_id' => mb_substr($deliveryId, 0, 128),
                    'subject' => $subject->toArray(),
                    'status' => 'pending',
                    'result' => [],
                ]);

                $this->audit->workspace($request->workspace_id, 'compliance.request_received', null, null, [
                    'request_id' => $request->id, 'kind' => $kind->value, 'origin' => $origin, 'origin_ref' => $originRef,
                ]);

                ProcessPrivacyRequest::dispatch($request->workspace_id, $request->id)->afterCommit();

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // already received
        }
    }
}
