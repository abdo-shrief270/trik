<?php

declare(strict_types=1);

namespace Modules\Commerce\Application;

use App\Support\Privacy\DataSubject;
use App\Support\Privacy\PersonalDataSource;
use Illuminate\Support\Facades\DB;

/**
 * Store connections in privacy requests. Trik keeps raw copies of store deliveries for 30 days; they
 * hold the customer's details, so redaction deletes the ones about the subject. For a whole store
 * (Shopify's shop/redact) the store itself goes, with its deliveries and order versions.
 */
final class CommercePersonalData implements PersonalDataSource
{
    public function name(): string
    {
        return 'stores';
    }

    /** A whole store reaches every order it ever sent (Orders then finds their phones). */
    public function expand(DataSubject $subject): DataSubject
    {
        if (! $subject->wholeStore || $subject->storeId === null) {
            return $subject;
        }

        $ids = DB::table('commerce_order_versions')->where('store_id', $subject->storeId)->pluck('external_id')
            ->map(fn (mixed $id): string => (string) $id)->all();

        return $subject->withOrderIds(array_values($ids));
    }

    /** Raw deliveries are Shopify's own copies of the order; the orders export already covers them. */
    public function export(DataSubject $subject): array
    {
        return [];
    }

    public function redact(DataSubject $subject): int
    {
        if ($subject->storeId === null) {
            return 0;
        }

        if ($subject->wholeStore) {
            $events = DB::table('commerce_webhook_events')->where('store_id', $subject->storeId)->count();

            return $events + DB::table('commerce_stores')->where('id', $subject->storeId)->delete();
        }

        $count = 0;
        foreach (array_chunk($subject->orderIds, 500) as $ids) {
            $count += DB::table('commerce_webhook_events')->where('store_id', $subject->storeId)
                ->where(fn ($q) => $q->whereIn(DB::raw("payload->>'id'"), $ids)->orWhereIn(DB::raw("payload->>'order_id'"), $ids))
                ->delete();
        }
        // the phone in any form the store wrote it (+20 10…, 010…): match on the national digits
        foreach ($subject->phones as $phone) {
            $national = substr($phone, -10);
            if (strlen($national) === 10) {
                $count += DB::table('commerce_webhook_events')->where('store_id', $subject->storeId)
                    ->whereRaw("regexp_replace(payload::text, '[^0-9]', '', 'g') like ?", ['%'.$national.'%'])
                    ->delete();
            }
        }

        return $count;
    }
}
