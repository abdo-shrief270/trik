<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Centrifugo's server API: one HTTP call per batch (POST /api/batch). */
final class CentrifugoRealtime implements Realtime
{
    // The Http facade, not an injected factory: this is a singleton, and under Octane (and in tests)
    // the factory behind the facade is per request.
    public function __construct(private readonly string $url, private readonly string $apiKey) {}

    public function publish(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        try {
            Http::withHeaders(['X-API-Key' => $this->apiKey])
                ->timeout(2)
                ->connectTimeout(1)
                ->post(rtrim($this->url, '/').'/api/batch', [
                    'commands' => array_map(fn (array $m): array => ['publish' => $m], $messages),
                ])
                ->throw();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
