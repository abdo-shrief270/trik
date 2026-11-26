<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Modules\Identity\Infrastructure\Models\User;

it('verifies the email from the signed link and redirects to the web app', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    $url = null;
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $n) use ($user, &$url): bool {
        $url = $n->toMail($user)->actionUrl;

        return true;
    });

    $this->get($url)->assertRedirect('http://localhost:3000/verified');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects a tampered link', function (): void {
    $user = User::factory()->unverified()->create();

    $this->getJson("/v1/auth/email/verify/{$user->id}/".sha1($user->email).'?signature=forged')->assertForbidden();
});

it('resends the verification email', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->withToken($user->createToken('x')->plainTextToken)
        ->postJson('/v1/auth/email/verification-notification')
        ->assertAccepted();

    Notification::assertSentTo($user, VerifyEmail::class);
});
