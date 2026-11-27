<?php

declare(strict_types=1);

namespace Modules\Waitlist\Infrastructure\Notifications;

/** Email copy in the brand voice (Egyptian Arabic first). Keep both languages in step. */
final class WaitlistCopy
{
    /** @return array<string, string> */
    public static function confirm(string $locale): array
    {
        return $locale === 'en' ? self::EN : self::AR;
    }

    private const EN = [
        'dir' => 'ltr',
        'subject' => 'Confirm your place on the Trik waitlist',
        'preheader' => 'One tap and you are on the list for the pilot.',
        'greeting' => 'Hi :name,',
        'intro' => 'Thanks for asking to join the Trik pilot for :store. Tap the button to confirm it was you, and we will save your place.',
        'button' => 'Confirm my place',
        'next' => 'We are opening the pilot to 10–20 sellers first and setting it up with each of them. We will write to you on WhatsApp or by email when your turn comes.',
        'expiry' => 'The link works for 7 days.',
        'notYou' => 'Did not sign up? Ignore this email: without your confirmation we will not write to you again.',
        'fallback' => 'If the button does not open, copy this link into your browser:',
        'unsubscribe' => 'Leave the waitlist',
        'footer' => 'You get this email because this address was entered on trik.chat.',
    ];

    private const AR = [
        'dir' => 'rtl',
        'subject' => 'أكّد مكانك في قائمة انتظار تريك',
        'preheader' => 'ضغطة واحدة وتبقى في قائمة التجربة.',
        'greeting' => 'أهلًا :name،',
        'intro' => 'شكرًا إنك طلبت تنضم لتجربة تريك لـ:store. اضغط على الزرار عشان تأكد إنه انت، وهنحجزلك مكانك.',
        'button' => 'أكّد مكاني',
        'next' => 'هنفتح التجربة الأول لـ١٠–٢٠ بيّاع، وهنجهزها مع كل واحد فيهم. هنكلمك على واتساب أو بالإيميل لما ييجي دورك.',
        'expiry' => 'اللينك شغال لمدة ٧ أيام.',
        'notYou' => 'مسجلتش؟ تجاهل الإيميل ده: من غير تأكيدك مش هنبعتلك تاني.',
        'fallback' => 'لو الزرار مفتحش، انسخ اللينك ده في المتصفح:',
        'unsubscribe' => 'اخرج من قائمة الانتظار',
        'footer' => 'وصلك الإيميل ده لأن العنوان ده اتكتب على trik.chat.',
    ];
}
