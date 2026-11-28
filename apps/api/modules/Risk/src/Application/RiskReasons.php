<?php

declare(strict_types=1);

namespace Modules\Risk\Application;

/**
 * Plain-language reasons, in Arabic and English, so every client (web, mobile, a CSV) shows the same words.
 * Placeholders come from the reason's params; amounts are in minor units and shown in pounds.
 */
final class RiskReasons
{
    private const TEXT = [
        'first_order' => ['ar' => 'أول طلب للعميل ده عندك', 'en' => "This customer's first order with you"],
        'refused_before' => ['ar' => 'رفض استلام :count طلب قبل كده', 'en' => 'Refused :count earlier order(s) at the door'],
        'cancelled_before' => ['ar' => 'لغى :count طلب قبل كده', 'en' => 'Cancelled :count earlier order(s)'],
        'unreachable_before' => ['ar' => 'مردش على التأكيد في :count طلب قبل كده', 'en' => "Didn't answer the confirmation on :count earlier order(s)"],
        'delivered_before' => ['ar' => 'استلم :count طلب قبل كده', 'en' => 'Received :count earlier order(s)'],
        'high_value' => ['ar' => 'المبلغ :amount ج.م أعلى من المعتاد (:usual ج.م)', 'en' => 'EGP :amount, above your usual EGP :usual'],
        'address_incomplete' => ['ar' => 'العنوان ناقص', 'en' => 'The address is incomplete'],
        'address_flagged' => ['ar' => 'العنوان متعلّم إنه محتاج يتصلّح', 'en' => 'The address is flagged to fix'],
        'late_night' => ['ar' => 'اتطلب بالليل (الساعة :hour)', 'en' => 'Placed late at night (:hour:00)'],
        'prepaid' => ['ar' => 'مدفوع مقدمًا', 'en' => 'Paid in advance'],
        'baseline' => ['ar' => 'نقطة البداية', 'en' => 'Starting point'],
    ];

    /**
     * @param  array{code: string, points: int, params: array<string, int|float>}  $reason
     * @return array{code: string, points: int, params: array<string, int|float>, text: array{ar: string, en: string}}
     */
    public static function describe(array $reason): array
    {
        $params = $reason['params'];
        $replace = [];
        foreach ($params as $k => $v) {
            $replace[':'.$k] = in_array($k, ['amount', 'usual'], true) ? number_format($v / 100) : (string) $v;
        }
        $text = self::TEXT[$reason['code']] ?? ['ar' => $reason['code'], 'en' => $reason['code']];

        return [...$reason, 'text' => ['ar' => strtr($text['ar'], $replace), 'en' => strtr($text['en'], $replace)]];
    }
}
