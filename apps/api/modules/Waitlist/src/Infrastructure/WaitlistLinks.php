<?php

declare(strict_types=1);

namespace Modules\Waitlist\Infrastructure;

use Illuminate\Support\Facades\URL;
use Modules\Waitlist\Infrastructure\Models\WaitlistEntry;

/** The signed links in waitlist emails, and the landing pages they lead to. */
final class WaitlistLinks
{
    public const CONFIRM_VALID_DAYS = 7;

    public function confirm(WaitlistEntry $entry): string
    {
        return URL::temporarySignedRoute('waitlist.confirm', now()->addDays(self::CONFIRM_VALID_DAYS), ['entry' => $entry->id]);
    }

    /** Never expires: it sits in every email, and leaving must always work. */
    public function unsubscribe(WaitlistEntry $entry): string
    {
        return URL::signedRoute('waitlist.unsubscribe', ['entry' => $entry->id]);
    }

    /**
     * A page on trik.chat, in the entry's language (Arabic at /, English under /en).
     *
     * @param  array<string, string>  $query
     */
    public function landing(string $path, string $locale = 'ar', array $query = []): string
    {
        $base = rtrim((string) config('trik.landing_url'), '/');
        $prefix = $locale === 'en' ? '/en' : '';

        return $base.$prefix.$path.($query === [] ? '' : '?'.http_build_query($query));
    }
}
