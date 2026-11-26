<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Http\Controllers;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Modules\WhatsApp\Application\AcceptWebhook;
use Modules\WhatsApp\Application\ProviderRegistry;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;
use Modules\WhatsApp\Infrastructure\WebhookRouter;

/**
 * Webhook intake. Stores the raw delivery and answers at once; processing happens on the queue.
 *  - per-number providers (360dialog, fake): POST /webhooks/whatsapp/{provider}/{number}
 *  - Meta Cloud API, one app-level URL:     GET|POST /webhooks/whatsapp/meta_cloud
 */
final class WebhookController
{
    public const FAILURES_PER_MINUTE = 60;

    public function __construct(
        private readonly WebhookRouter $router,
        private readonly ProviderRegistry $providers,
        private readonly TenantContext $context,
        private readonly AcceptWebhook $accept,
    ) {}

    public function perNumber(Request $request, string $provider, string $number): JsonResponse
    {
        $this->refuseIfFlooding($request);
        $route = $this->router->byNumberId($number);

        if ($route === null || $route['provider']->value !== $provider || $route['provider'] === Provider::MetaCloud) {
            $this->failed($request);

            throw Problem::notFound('unknown-number', 'Unknown number.');
        }

        $this->refuseIfFlooding($request, 'number:'.$number);

        return $this->context->run($route['workspace_id'], function () use ($request, $route, $number): JsonResponse {
            $record = WhatsAppNumber::query()->findOrFail($number);
            $adapter = $this->providers->webhooks($route['provider']);

            if (! $adapter->verify($this->headers($request), $request->getContent(), $record->webhookSecret())) {
                // a known number with a wrong secret counts against that number only, never the
                // provider's shared IP (one misconfigured number must not block everyone's webhooks)
                $this->failed($request, 'number:'.$record->id);

                throw Problem::make(401, 'invalid-signature', 'Invalid signature.');
            }

            $this->accept->handle($record->id, $route['provider'], $this->payload($request));

            return response()->json(['ok' => true]);
        });
    }

    /** Meta's subscription handshake. */
    public function metaVerify(Request $request): Response
    {
        $token = (string) config('whatsapp.providers.meta_cloud.webhook_verify_token', '');

        if ($request->query('hub_mode') === 'subscribe' && $token !== '' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        return response('Forbidden', 403);
    }

    public function meta(Request $request): JsonResponse
    {
        $this->refuseIfFlooding($request);
        $adapter = $this->providers->webhooks(Provider::MetaCloud);

        if (! $adapter->verify($this->headers($request), $request->getContent(), null)) {
            $this->failed($request);

            throw Problem::make(401, 'invalid-signature', 'Invalid signature.');
        }

        $payload = $this->payload($request);

        // One delivery can carry several numbers: store one event per number, in its own workspace.
        foreach ($this->splitByNumber($payload) as $phoneNumberId => $part) {
            $route = $this->router->byExternalId(Provider::MetaCloud, (string) $phoneNumberId);

            if ($route !== null) {
                $this->context->run($route['workspace_id'], fn () => $this->accept->handle($route['number_id'], Provider::MetaCloud, $part));
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Only invalid deliveries are counted, never valid ones (providers send thousands per second):
     * more than FAILURES_PER_MINUTE from one IP (unknown numbers, unsigned Meta calls), or for one
     * number (wrong secret), and that IP or number is refused for a minute.
     */
    private function refuseIfFlooding(Request $request, ?string $scope = null): void
    {
        $key = $this->failureKey($request, $scope);

        if (RateLimiter::tooManyAttempts($key, self::FAILURES_PER_MINUTE)) {
            throw new Problem(429, 'rate-limited', 'Too Many Requests', 'Too many invalid webhook deliveries.', [], [
                'Retry-After' => (string) RateLimiter::availableIn($key),
            ]);
        }
    }

    private function failed(Request $request, ?string $scope = null): void
    {
        RateLimiter::hit($this->failureKey($request, $scope), 60);
    }

    /** Unknown numbers and unsigned Meta calls count per IP; a known number's bad secret counts per number. */
    private function failureKey(Request $request, ?string $scope): string
    {
        return 'webhook-failures:'.($scope ?? 'ip:'.$request->ip());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    private function splitByNumber(array $payload): array
    {
        $parts = [];

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) (is_array($entry) ? ($entry['changes'] ?? []) : []) as $change) {
                $id = is_array($change) ? data_get($change, 'value.metadata.phone_number_id') : null;

                if (is_string($id) && $id !== '') {
                    $parts[$id] ??= ['object' => $payload['object'] ?? 'whatsapp_business_account', 'entry' => []];
                    $parts[$id]['entry'][] = ['id' => is_array($entry) ? ($entry['id'] ?? null) : null, 'changes' => [$change]];
                }
            }
        }

        return $parts;
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
