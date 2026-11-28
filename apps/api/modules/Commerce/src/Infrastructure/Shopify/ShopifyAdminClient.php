<?php

declare(strict_types=1);

namespace Modules\Commerce\Infrastructure\Shopify;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Commerce\Application\Shopify\ShopifyCallFailed;
use Modules\Commerce\Domain\Shopify\ShopDomain;

/**
 * The GraphQL Admin API (no REST), plus the OAuth code exchange. Every call goes to a validated
 * *.myshopify.com host (ShopDomain), with a timeout, through the per-shop cost throttle.
 */
final class ShopifyAdminClient
{
    public function __construct(private readonly CostThrottle $throttle) {}

    /**
     * Runs a query or mutation and returns its `data`.
     *
     * @param  array<string, mixed>  $variables
     * @param  int  $expectedCost  a guess at the query's cost, for the throttle (Shopify reports the real one)
     * @return array<string, mixed>
     */
    public function graphql(ShopDomain $shop, string $token, string $query, array $variables = [], int $expectedCost = 50): array
    {
        $this->throttle->reserve($shop->value, $expectedCost);

        $version = (string) config('commerce.shopify.api_version');
        $response = $this->send(fn () => Http::withHeaders(['X-Shopify-Access-Token' => $token])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('commerce.shopify.timeout', 15))
            ->post("https://{$shop->value}/admin/api/{$version}/graphql.json", $variables === [] ? ['query' => $query] : ['query' => $query, 'variables' => $variables]));

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $status = data_get($body, 'extensions.cost.throttleStatus');
        if (is_array($status)) {
            $this->throttle->record($shop->value, $status);
        }

        foreach ((array) ($body['errors'] ?? []) as $error) {
            if (is_array($error) && data_get($error, 'extensions.code') === 'THROTTLED') {
                throw ShopifyCallFailed::throttled($this->throttle->secondsUntil($shop->value, $expectedCost));
            }
        }

        if (! empty($body['errors'])) {
            $messages = array_map(fn (mixed $e): string => is_array($e) ? (string) ($e['message'] ?? 'error') : (string) $e, (array) $body['errors']);

            throw new ShopifyCallFailed('Shopify GraphQL error: '.Str::limit(implode('; ', $messages), 500));
        }

        $data = $body['data'] ?? null;
        if (! is_array($data)) {
            throw new ShopifyCallFailed('Shopify returned no data.');
        }

        return $data;
    }

    /**
     * Trades the OAuth code for an offline access token.
     *
     * @return array{access_token: string, scope: string}
     */
    public function exchangeCode(ShopDomain $shop, string $code): array
    {
        $response = $this->send(fn () => Http::acceptJson()
            ->asJson()
            ->timeout((int) config('commerce.shopify.timeout', 15))
            ->post("https://{$shop->value}/admin/oauth/access_token", [
                'client_id' => (string) config('commerce.shopify.client_id'),
                'client_secret' => (string) config('commerce.shopify.client_secret'),
                'code' => $code,
            ]));

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new ShopifyCallFailed('Shopify did not return an access token.');
        }

        return ['access_token' => $token, 'scope' => (string) $response->json('scope', '')];
    }

    /** @param callable(): Response $call */
    private function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new ShopifyCallFailed('Shopify unreachable: '.$e->getMessage(), 30);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new ShopifyCallFailed('Shopify refused the token (HTTP '.$response->status().').', null, true);
        }
        if ($response->status() === 429 || $response->serverError()) {
            $after = (int) ($response->header('Retry-After') ?: 0);

            throw new ShopifyCallFailed('Shopify unavailable (HTTP '.$response->status().').', $after > 0 ? min(600, $after) : 30);
        }
        if ($response->failed()) {
            throw new ShopifyCallFailed('Shopify request failed (HTTP '.$response->status().'): '.Str::limit($response->body(), 300));
        }

        return $response;
    }
}
