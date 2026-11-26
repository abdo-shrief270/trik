<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Jobs;

use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\WhatsApp\Application\WebhookBatchProcessor;
use Modules\WhatsApp\Infrastructure\Models\WebhookEvent;
use Throwable;

/**
 * Drains a workspace's pending webhook deliveries in batches.
 *
 * One job per workspace at a time (unique until it starts), so under load deliveries pile up
 * and get processed 500 at a time; at low load a batch is just one delivery. Rows are claimed
 * with SKIP LOCKED, so several workers never process the same delivery. A delivery that keeps
 * failing is retried on its own and parked after MAX_ATTEMPTS, without blocking the rest.
 */
final class ProcessWebhookEvents implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const BATCH = 500;

    public const MAX_ATTEMPTS = 5;

    public int $tries = 5;

    public int $uniqueFor = 120;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    public function __construct(public readonly string $workspaceId)
    {
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'whatsapp-webhooks:'.$this->workspaceId;
    }

    public function handle(WebhookBatchProcessor $processor, EventPublisher $publisher, TenantContext $context): void
    {
        $context->run($this->workspaceId, function () use ($processor, $publisher): void {
            while ($this->drainOnce($processor, $publisher) === self::BATCH) {
                // keep going while full batches come back
            }
        });
    }

    private function drainOnce(WebhookBatchProcessor $processor, EventPublisher $publisher): int
    {
        $claimed = 0;
        $published = [];

        try {
            DB::transaction(function () use ($processor, &$claimed, &$published): void {
                $events = $this->claim(self::BATCH);
                $claimed = count($events);

                if ($events === []) {
                    return;
                }

                $published = $processor->process($events, $this->workspaceId);
                $this->markProcessed($events);
            });
        } catch (Throwable $e) {
            report($e);
            [$claimed, $published] = $this->oneByOne($processor);
        }

        foreach ($published as $event) {
            $publisher->publish($event);
        }

        return $claimed;
    }

    /**
     * Fallback when a batch fails: isolate the bad delivery so the others still go through.
     *
     * @return array{0: int, 1: list<object>}
     */
    private function oneByOne(WebhookBatchProcessor $processor): array
    {
        $published = [];
        $count = 0;
        $skip = [];

        foreach (range(1, self::BATCH) as $_) {
            $failed = null;

            $done = DB::transaction(function () use ($processor, &$published, &$failed, &$skip): bool {
                $events = $this->claim(1, $skip);

                if ($events === []) {
                    return false;
                }

                try {
                    DB::transaction(function () use ($processor, $events, &$published): void {
                        array_push($published, ...$processor->process($events, $this->workspaceId));
                        $this->markProcessed($events);
                    });
                } catch (Throwable $e) {
                    $failed = $e;
                    $skip[] = $events[0]->id;
                    WebhookEvent::query()->whereKey($events[0]->id)->update([
                        'attempts' => DB::raw('attempts + 1'),
                        'last_error' => Str::limit($e->getMessage(), 1000),
                    ]);
                }

                return true;
            });

            if (! $done) {
                break;
            }

            $count++;

            if ($failed !== null) {
                report($failed);
            }
        }

        if ($skip !== []) {
            // try the failed ones again later, with backoff; each is parked after MAX_ATTEMPTS
            $this->release(min(600, 30 * max(1, $this->attempts())));
        }

        return [$count, $published];
    }

    /**
     * @param  list<string>  $except
     * @return list<WebhookEvent>
     */
    private function claim(int $limit, array $except = []): array
    {
        return array_values(WebhookEvent::query()
            ->whereNull('processed_at')
            ->when($except !== [], fn ($q) => $q->whereNotIn('id', $except))
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('created_at')
            ->limit($limit)
            ->lock('for update skip locked')
            ->get()
            ->all());
    }

    /** @param list<WebhookEvent> $events */
    private function markProcessed(array $events): void
    {
        WebhookEvent::query()
            ->whereIn('id', array_map(fn (WebhookEvent $e): string => $e->id, $events))
            ->update(['processed_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'last_error' => null]);
    }
}
