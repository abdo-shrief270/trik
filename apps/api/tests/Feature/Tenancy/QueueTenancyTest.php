<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;

final class RecordWorkspaceJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @var list<string|null> */
    public static array $seen = [];

    public function handle(TenantContext $context): void
    {
        self::$seen[] = $context->id();
    }
}

beforeEach(fn () => RecordWorkspaceJob::$seen = []);

it('carries the workspace into queued jobs and restores it on the worker', function (): void {
    config(['queue.default' => 'database']);
    $ctx = app(TenantContext::class);
    $id = (string) Str::uuid();

    // braces matter: a PendingDispatch is pushed when it is destroyed
    $ctx->run($id, function (): void {
        RecordWorkspaceJob::dispatch();
    });
    expect($ctx->id())->toBeNull();

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    expect(RecordWorkspaceJob::$seen)->toBe([$id])
        ->and($ctx->id())->toBeNull(); // cleared after the job
});

it('does not clear the caller context when a job runs synchronously', function (): void {
    config(['queue.default' => 'sync']);
    $ctx = app(TenantContext::class);
    $id = (string) Str::uuid();

    $ctx->run($id, function () use ($ctx, $id): void {
        RecordWorkspaceJob::dispatch();
        expect($ctx->id())->toBe($id);
    });

    expect(RecordWorkspaceJob::$seen)->toBe([$id]);
});

it('runs jobs without a workspace when none was active', function (): void {
    config(['queue.default' => 'sync']);
    RecordWorkspaceJob::dispatch();

    expect(RecordWorkspaceJob::$seen)->toBe([null]);
});
