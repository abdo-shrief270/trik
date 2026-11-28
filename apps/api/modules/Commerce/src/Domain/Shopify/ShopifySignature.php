<?php

declare(strict_types=1);

namespace Modules\Commerce\Domain\Shopify;

/**
 * Shopify's two HMAC schemes, both keyed with the app's client secret. Several secrets may be given
 * (current first, then the previous one while it's being rotated); any match is accepted.
 */
final class ShopifySignature
{
    /**
     * Webhooks: X-Shopify-Hmac-Sha256 is base64(HMAC-SHA256(raw body)).
     *
     * @param  list<string>  $secrets
     */
    public static function verifyWebhook(string $rawBody, string $header, array $secrets): bool
    {
        if ($header === '') {
            return false;
        }

        foreach ($secrets as $secret) {
            if ($secret !== '' && hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * OAuth redirects and the app URL: `hmac` is hex HMAC-SHA256 of the other query parameters,
     * sorted by name and joined as "a=1&b=2" (array values as ["x","y"]).
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $secrets
     */
    public static function verifyQuery(array $query, array $secrets): bool
    {
        $given = $query['hmac'] ?? null;
        if (! is_string($given) || $given === '') {
            return false;
        }

        $message = self::queryMessage($query);

        foreach ($secrets as $secret) {
            if ($secret !== '' && hash_equals(hash_hmac('sha256', $message, $secret), strtolower($given))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $query */
    public static function queryMessage(array $query): string
    {
        unset($query['hmac'], $query['signature']);
        ksort($query, SORT_STRING);

        $parts = [];
        foreach ($query as $key => $value) {
            $value = is_array($value)
                ? '['.implode(', ', array_map(fn (mixed $v): string => '"'.(is_scalar($v) ? (string) $v : '').'"', $value)).']'
                : (is_scalar($value) ? (string) $value : '');
            $parts[] = $key.'='.$value;
        }

        return implode('&', $parts);
    }
}
