<?php

declare(strict_types=1);

namespace Modules\Compliance\Application\Jobs;

use App\Support\Privacy\DataSubject;
use App\Support\Privacy\PersonalDataRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Compliance\Infrastructure\Models\PrivacyRequest;
use Modules\Workspaces\Application\AuditLog;
use Throwable;

/**
 * Carries out a privacy request in its workspace: widens the subject through every module that holds
 * personal data, then either exports what they hold (kept encrypted for 30 days) or redacts it, in one
 * transaction, and writes the outcome to the workspace audit log.
 */
final class ProcessPrivacyRequest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const EXPORT_DAYS = 30;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 3600];

    public function __construct(public readonly string $workspaceId, public readonly string $requestId) {}

    public function handle(TenantContext $context, PersonalDataRegistry $registry, AuditLog $audit): void
    {
        $context->run($this->workspaceId, function () use ($registry, $audit): void {
            try {
                DB::transaction(function () use ($registry, $audit): void {
                    /** @var PrivacyRequest|null $request */
                    $request = PrivacyRequest::query()->lockForUpdate()->find($this->requestId);
                    if ($request === null || $request->status === 'done') {
                        return;
                    }

                    $sources = $registry->sources();
                    $subject = DataSubject::fromArray($request->subject);
                    // a source may widen what another one needs (store → order ids → phones): until stable
                    for ($pass = 0; $pass < 3; $pass++) {
                        $before = $subject->toArray();
                        foreach ($sources as $source) {
                            $subject = $source->expand($subject);
                        }
                        if ($subject->toArray() == $before) {
                            break;
                        }
                    }

                    $result = [];
                    $export = null;

                    if ($request->kind->isRedaction()) {
                        foreach ($sources as $source) {
                            $result[$source->name()] = $source->redact($subject);
                        }
                    } else {
                        $export = ['requested_at' => $request->created_at->toIso8601String(), 'origin' => $request->origin, 'origin_ref' => $request->origin_ref];
                        foreach ($sources as $source) {
                            $data = $source->export($subject);
                            $export[$source->name()] = $data;
                            $result[$source->name()] = count($data);
                        }
                    }

                    $request->update([
                        'status' => 'done',
                        'result' => $result,
                        'export' => $export,
                        'export_expires_at' => $export === null ? null : now()->addDays(self::EXPORT_DAYS),
                        // done: the request itself no longer needs to name anyone (the export, if any, says the rest)
                        'subject' => ['done' => true],
                        'attempts' => $request->attempts + 1,
                        'error' => null,
                        'completed_at' => now(),
                    ]);

                    $audit->workspace($this->workspaceId, $request->kind->isRedaction() ? 'compliance.data_redacted' : 'compliance.data_exported', null, null, [
                        'request_id' => $request->id, 'kind' => $request->kind->value, 'origin' => $request->origin,
                        'origin_ref' => $request->origin_ref, 'result' => $result,
                    ]);
                });
            } catch (Throwable $e) {
                PrivacyRequest::query()->whereKey($this->requestId)->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'error' => Str::limit($e->getMessage(), 1000),
                    'status' => $this->attempts() >= $this->tries ? 'failed' : 'pending',
                ]);

                throw $e;
            }
        });
    }
}
