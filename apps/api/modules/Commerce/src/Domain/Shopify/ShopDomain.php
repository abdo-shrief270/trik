<?php

declare(strict_types=1);

namespace Modules\Commerce\Domain\Shopify;

use InvalidArgumentException;

/**
 * A shop's permanent domain, always "<handle>.myshopify.com".
 *
 * This is a security boundary, not formatting: the OAuth callback sends the app's client secret and the
 * authorization code to this host. Anything that isn't exactly a myshopify.com subdomain is refused
 * (no custom domains, ports, paths, userinfo or look-alikes such as "x.myshopify.com.evil.io").
 */
final readonly class ShopDomain
{
    private const PATTERN = '/\A[a-z0-9][a-z0-9-]{0,61}\.myshopify\.com\z/';

    private function __construct(public string $value) {}

    /** Strict: exactly what Shopify sends in `shop` (OAuth, app URL, webhook header). */
    public static function fromShopify(string $shop): self
    {
        // No trimming here: a value that needs cleaning didn't come from Shopify.
        $shop = strtolower($shop);

        if (preg_match(self::PATTERN, $shop) !== 1) {
            throw new InvalidArgumentException('Not a myshopify.com shop domain.');
        }

        return new self($shop);
    }

    /** Lenient, for what a seller types: "laila", "laila.myshopify.com", "https://laila.myshopify.com/admin". */
    public static function fromUserInput(string $input): self
    {
        $s = strtolower(trim($input));
        $s = (string) preg_replace('#^https?://#', '', $s);
        $s = trim(explode('/', $s, 2)[0]);

        if ($s !== '' && ! str_contains($s, '.')) {
            $s .= '.myshopify.com';
        }

        return self::fromShopify($s);
    }

    /** The store's handle, e.g. "laila" for laila.myshopify.com. */
    public function handle(): string
    {
        return substr($this->value, 0, -strlen('.myshopify.com'));
    }
}
