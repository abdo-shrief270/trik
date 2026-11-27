<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Modules\Waitlist\Application\JoinWaitlist;
use Modules\Waitlist\Domain\EntryStatus;
use Modules\Waitlist\Infrastructure\Models\WaitlistEntry;
use Modules\Waitlist\Infrastructure\Notifications\WaitlistConfirmation;
use Symfony\Component\Mime\Email;

/** @param array<string, mixed> $overrides */
function waitlistJoin(array $overrides = [], string $ip = '203.0.113.7'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/v1/waitlist', [
        'name' => 'Mona Adel',
        'store_name' => 'Mona Store',
        'email' => ' Mona@Example.com ',
        'phone' => '+201012345678',
        'platform' => 'shopify',
        'monthly_orders' => 'm',
        'locale' => 'ar',
        'consent' => true,
        ...$overrides,
    ]);
}

function waitlistEntry(string $email = 'mona@example.com'): WaitlistEntry
{
    return WaitlistEntry::query()->where('email', $email)->firstOrFail();
}

function waitlistMail(WaitlistEntry $entry): MailMessage
{
    return (new WaitlistConfirmation($entry->id))->toMail(new AnonymousNotifiable);
}

it('adds a pending entry and queues the confirmation email', function (): void {
    Notification::fake();

    waitlistJoin()->assertStatus(202)->assertExactJson(['message' => 'Check your email for a link to confirm your place.']);

    $entry = waitlistEntry();
    expect($entry->status)->toBe(EntryStatus::Pending)
        ->and($entry->name)->toBe('Mona Adel')
        ->and($entry->phone)->toBe('+201012345678')
        ->and($entry->locale)->toBe('ar')
        ->and($entry->consent_ip)->toBe('203.0.113.7')
        ->and($entry->confirmations_sent)->toBe(1);

    Notification::assertSentOnDemand(
        WaitlistConfirmation::class,
        fn (WaitlistConfirmation $n, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'mona@example.com' && $n->entryId === $entry->id,
    );
});

it('sends the email only after the sign-up commits, from the queue', function (): void {
    $n = new WaitlistConfirmation('x');

    expect($n)->toBeInstanceOf(ShouldQueue::class)
        ->and($n->afterCommit)->toBeTrue();
});

it('answers the same for known emails and never reveals or overwrites them', function (): void {
    Notification::fake();
    waitlistJoin();
    $first = waitlistJoin(['name' => 'Someone Else', 'phone' => '+201099999999'])->assertStatus(202)->json();

    expect($first)->toBe(['message' => 'Check your email for a link to confirm your place.'])
        ->and(waitlistEntry()->name)->toBe('Mona Adel')
        ->and(waitlistEntry()->phone)->toBe('+201012345678')
        ->and(WaitlistEntry::query()->count())->toBe(1);

    // Inside the cooldown: no second email.
    Notification::assertSentOnDemandTimes(WaitlistConfirmation::class, 1);
});

it('resends to a pending email once the cooldown has passed', function (): void {
    Notification::fake();
    waitlistJoin();

    $this->travel(JoinWaitlist::RESEND_COOLDOWN_MINUTES - 1)->minutes();
    waitlistJoin(ip: '198.51.100.1')->assertStatus(202);
    Notification::assertSentOnDemandTimes(WaitlistConfirmation::class, 1);

    $this->travel(2)->minutes();
    waitlistJoin(ip: '198.51.100.2')->assertStatus(202);
    Notification::assertSentOnDemandTimes(WaitlistConfirmation::class, 2);
    expect(waitlistEntry()->confirmations_sent)->toBe(2);
});

it('limits sign-ups per email, whatever the IP', function (): void {
    Notification::fake();

    $codes = collect(range(1, 6))->map(fn (int $i) => waitlistJoin(ip: "198.51.100.{$i}")->status());

    expect($codes->all())->toBe([202, 202, 202, 202, 202, 429]);
});

it('caps confirmation emails per opt-in cycle', function (): void {
    Notification::fake();
    waitlistJoin();

    for ($i = 0; $i < JoinWaitlist::MAX_SENDS + 3; $i++) {
        $this->travel(2)->hours(); // past the hourly email limit and the cooldown
        waitlistJoin(ip: "198.51.100.{$i}")->assertStatus(202);
    }

    expect(waitlistEntry()->confirmations_sent)->toBe(JoinWaitlist::MAX_SENDS);
    Notification::assertSentOnDemandTimes(WaitlistConfirmation::class, JoinWaitlist::MAX_SENDS);
});

it('leaves a confirmed entry alone', function (): void {
    Notification::fake();
    waitlistJoin();
    waitlistEntry()->forceFill(['status' => EntryStatus::Confirmed, 'confirmed_at' => now()])->save();
    $this->travel(1)->hours();

    waitlistJoin(['store_name' => 'Overwritten'])->assertStatus(202);

    expect(waitlistEntry()->status)->toBe(EntryStatus::Confirmed)->and(waitlistEntry()->store_name)->toBe('Mona Store');
    Notification::assertSentOnDemandTimes(WaitlistConfirmation::class, 1);
});

it('starts a new opt-in cycle for someone who left the list', function (): void {
    Notification::fake();
    waitlistJoin();
    waitlistEntry()->forceFill(['status' => EntryStatus::Unsubscribed, 'unsubscribed_at' => now(), 'confirmations_sent' => JoinWaitlist::MAX_SENDS])->save();
    $this->travel(1)->hours();

    waitlistJoin(['store_name' => 'Mona Shop', 'locale' => 'en'])->assertStatus(202);

    $entry = waitlistEntry();
    expect($entry->status)->toBe(EntryStatus::Pending)
        ->and($entry->unsubscribed_at)->toBeNull()
        ->and($entry->store_name)->toBe('Mona Shop')
        ->and($entry->locale)->toBe('en')
        ->and($entry->confirmations_sent)->toBe(1);
    Notification::assertSentOnDemandTimes(WaitlistConfirmation::class, 2);
});

it('validates the form', function (array $body, string $field): void {
    Notification::fake();

    $r = waitlistJoin($body)->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');

    expect($r->json('code'))->toBe('validation-failed')->and($r->json('errors'))->toHaveKey($field);
    Notification::assertNothingSent();
})->with([
    'no name' => [['name' => '  '], 'name'],
    'long name' => [['name' => str_repeat('x', 121)], 'name'],
    'no store' => [['store_name' => null], 'store_name'],
    'bad email' => [['email' => 'mona@'], 'email'],
    'local phone' => [['phone' => '01012345678'], 'phone'],
    'phone with text' => [['phone' => '+20 10 1234 5678'], 'phone'],
    'unknown platform' => [['platform' => 'amazon'], 'platform'],
    'unknown band' => [['monthly_orders' => 'xxl'], 'monthly_orders'],
    'unknown locale' => [['locale' => 'fr'], 'locale'],
    'no consent' => [['consent' => false], 'consent'],
    'consent missing' => [['consent' => null], 'consent'],
]);

it('ignores fields the form does not have', function (): void {
    Notification::fake();

    waitlistJoin(['status' => 'confirmed', 'confirmed_at' => '2020-01-01', 'id' => '00000000-0000-0000-0000-000000000001', 'confirmations_sent' => 99])->assertStatus(202);

    $entry = waitlistEntry();
    expect($entry->status)->toBe(EntryStatus::Pending)
        ->and($entry->confirmed_at)->toBeNull()
        ->and($entry->id)->not->toBe('00000000-0000-0000-0000-000000000001')
        ->and($entry->confirmations_sent)->toBe(1);
});

it('keeps names to one line of plain text', function (): void {
    Notification::fake();

    waitlistJoin(['name' => "  Mona\r\nBcc: x@evil.test\t Adel ", 'store_name' => "Store\u{0000}\u{202E}name"])->assertStatus(202);

    expect(waitlistEntry()->name)->toBe('Mona Bcc: x@evil.test Adel')
        ->and(waitlistEntry()->store_name)->toBe('Store name');
});

it('limits sign-ups per IP', function (): void {
    Notification::fake();

    $codes = collect(range(1, 6))->map(fn (int $i) => waitlistJoin(['email' => "seller{$i}@example.com"])->status());

    expect($codes->all())->toBe([202, 202, 202, 202, 202, 429]);
});

it('can be closed with the feature flag', function (): void {
    config(['trik.features.waitlist' => 'none']);

    waitlistJoin()->assertNotFound();
});

it('confirms from the signed link and lands on the confirmed page in the entry language', function (string $locale, string $page): void {
    Notification::fake();
    waitlistJoin(['locale' => $locale]);
    $url = waitlistMail(waitlistEntry())->viewData['confirmUrl'];

    $this->get($url)->assertRedirect($page);

    expect(waitlistEntry()->status)->toBe(EntryStatus::Confirmed)->and(waitlistEntry()->confirmed_at)->not->toBeNull();
    // Opening it again changes nothing.
    $this->get($url)->assertRedirect($page);
})->with([
    'arabic' => ['ar', 'http://localhost:3100/waitlist/confirmed'],
    'english' => ['en', 'http://localhost:3100/en/waitlist/confirmed'],
]);

it('answers JSON clients without redirecting', function (): void {
    Notification::fake();
    waitlistJoin();

    $this->getJson(waitlistMail(waitlistEntry())->viewData['confirmUrl'])->assertOk()->assertJson(['message' => 'You are on the waitlist.']);
});

it('sends a bad or expired link back to the form, and refuses it for API clients', function (): void {
    Notification::fake();
    waitlistJoin();
    $entry = waitlistEntry();
    $url = waitlistMail($entry)->viewData['confirmUrl'];

    $this->get($url.'x')->assertRedirect('http://localhost:3100/waitlist?link=expired');
    $this->getJson($url.'x')->assertForbidden()->assertJson(['code' => 'invalid-signature']);
    $this->getJson("/v1/waitlist/{$entry->id}/confirm")->assertForbidden();

    $this->travel(8)->days();
    $this->get($url)->assertRedirect('http://localhost:3100/waitlist?link=expired');
    expect(waitlistEntry()->status)->toBe(EntryStatus::Pending);
});

it('does not let one signed link open another entry', function (): void {
    Notification::fake();
    waitlistJoin();
    waitlistJoin(['email' => 'other@example.com'], '198.51.100.1');
    $url = waitlistMail(waitlistEntry())->viewData['confirmUrl'];
    $other = waitlistEntry('other@example.com');

    $forged = preg_replace('#/waitlist/[0-9a-f-]{36}/#', "/waitlist/{$other->id}/", $url);

    $this->getJson((string) $forged)->assertForbidden();
    expect($other->fresh()?->status)->toBe(EntryStatus::Pending);
});

it('does not put someone back on the list from an old confirm link', function (): void {
    Notification::fake();
    waitlistJoin();
    $url = waitlistMail(waitlistEntry())->viewData['confirmUrl'];
    waitlistEntry()->forceFill(['status' => EntryStatus::Unsubscribed, 'unsubscribed_at' => now()])->save();

    $this->get($url)->assertRedirect('http://localhost:3100/waitlist/unsubscribed');
    expect(waitlistEntry()->status)->toBe(EntryStatus::Unsubscribed);
});

it('unsubscribes from the email link and from the one-click POST', function (): void {
    Notification::fake();
    waitlistJoin(['locale' => 'en']);
    $url = waitlistMail(waitlistEntry())->viewData['unsubscribeUrl'];

    $this->get($url)->assertRedirect('http://localhost:3100/en/waitlist/unsubscribed');
    expect(waitlistEntry()->status)->toBe(EntryStatus::Unsubscribed);

    // RFC 8058: the mail provider POSTs to the same URL; the link never expires.
    $this->travel(400)->days();
    $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertJson(['message' => 'You left the waitlist.']);
    $this->post($url.'tampered')->assertForbidden();
});

it('writes the email in the sign-up language, with the links and unsubscribe headers', function (): void {
    Notification::fake();
    waitlistJoin(['name' => 'Mona <b>Adel</b>', 'store_name' => 'Mona & Co']);
    $entry = waitlistEntry();
    $mail = waitlistMail($entry);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('أكّد مكانك في قائمة انتظار تريك')
        ->and($html)->toContain('dir="rtl"')
        ->and($html)->toContain('أهلًا Mona &lt;b&gt;Adel&lt;/b&gt;')
        ->and($html)->not->toContain('<b>Adel</b>')
        ->and($html)->toContain(e($mail->viewData['confirmUrl']))
        ->and($html)->toContain(e($mail->viewData['unsubscribeUrl']));

    $text = view('waitlist::confirm-text', $mail->viewData)->render();
    expect($text)->toContain('Mona & Co')->and($text)->toContain($mail->viewData['confirmUrl']);

    $headers = new Email;
    foreach ($mail->callbacks as $callback) {
        $callback($headers);
    }
    expect($headers->getHeaders()->get('List-Unsubscribe')?->getBodyAsString())->toBe('<'.$mail->viewData['unsubscribeUrl'].'>')
        ->and($headers->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click');
});

it('writes English emails left to right', function (): void {
    Notification::fake();
    waitlistJoin(['locale' => 'en']);
    $mail = waitlistMail(waitlistEntry());

    expect($mail->subject)->toBe('Confirm your place on the Trik waitlist')->and((string) $mail->render())->toContain('dir="ltr"');
});

it('skips a queued email if the person confirmed or left in the meantime', function (): void {
    Notification::fake();
    waitlistJoin();
    $entry = waitlistEntry();
    $n = new WaitlistConfirmation($entry->id);

    expect($n->shouldSend(new AnonymousNotifiable, 'mail'))->toBeTrue();
    $entry->forceFill(['status' => EntryStatus::Confirmed])->save();
    expect($n->shouldSend(new AnonymousNotifiable, 'mail'))->toBeFalse();
    $entry->delete();
    expect($n->shouldSend(new AnonymousNotifiable, 'mail'))->toBeFalse();
});

it('prunes unconfirmed sign-ups after 30 days and keeps the rest', function (): void {
    Notification::fake();
    waitlistJoin(['email' => 'old@example.com']);
    waitlistJoin(['email' => 'kept@example.com'], '198.51.100.2');
    waitlistEntry('kept@example.com')->forceFill(['status' => EntryStatus::Confirmed])->save();
    $this->travel(WaitlistEntry::PRUNE_AFTER_DAYS + 1)->days();
    waitlistJoin(['email' => 'new@example.com'], '198.51.100.3');

    $this->artisan('model:prune', ['--model' => [WaitlistEntry::class]])->assertSuccessful();

    expect(WaitlistEntry::query()->orderBy('email')->pluck('email')->all())->toBe(['kept@example.com', 'new@example.com']);
});

it('exports the confirmed list as CSV, safe to open in a spreadsheet', function (): void {
    Notification::fake();
    waitlistJoin(['name' => '=HYPERLINK("http://evil.test")']);
    waitlistJoin(['email' => 'pending@example.com'], '198.51.100.4');
    waitlistEntry()->forceFill(['status' => EntryStatus::Confirmed, 'confirmed_at' => now()])->save();

    $this->artisan('waitlist:export')
        ->expectsOutputToContain('email,name,store_name,phone,platform,monthly_orders,locale,status,confirmed_at,created_at')
        ->expectsOutputToContain('mona@example.com,"\'=HYPERLINK(""http://evil.test"")","Mona Store",+201012345678,shopify,m,ar,confirmed')
        ->doesntExpectOutputToContain('pending@example.com')
        ->assertSuccessful();

    $this->artisan('waitlist:export', ['--status' => 'nope'])->assertExitCode(2);
});

it('schedules the daily prune', function (): void {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command);

    expect($events->contains(fn (string $c) => str_contains($c, 'model:prune') && str_contains($c, 'WaitlistEntry')))->toBeTrue();
});

it('signs links against the API, not the landing site', function (): void {
    Notification::fake();
    waitlistJoin();

    expect(waitlistMail(waitlistEntry())->viewData['confirmUrl'])->toStartWith(URL::to('/v1/waitlist/'));
});
