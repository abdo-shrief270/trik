<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Identity\Infrastructure\Notifications\QueuedResetPassword;

it('emails a reset link to the web app without revealing whether the account exists', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'mona@example.com']);

    $this->postJson('/v1/auth/forgot-password', ['email' => 'mona@example.com'])->assertOk();
    $this->postJson('/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

    Notification::assertSentTo($user, QueuedResetPassword::class, function (QueuedResetPassword $n) use ($user): bool {
        $url = $n->toMail($user)->actionUrl;

        return str_starts_with($url, 'http://localhost:3000/reset-password?token=');
    });
});

it('resets the password and signs out every device', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'mona@example.com']);
    $user->createToken('old phone');

    $this->postJson('/v1/auth/forgot-password', ['email' => 'mona@example.com']);

    $token = null;
    Notification::assertSentTo($user, QueuedResetPassword::class, function (QueuedResetPassword $n) use (&$token): bool {
        $token = $n->token;

        return true;
    });

    $this->postJson('/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'mona@example.com',
        'password' => 'brand-new-pass-1',
        'password_confirmation' => 'brand-new-pass-1',
    ])->assertOk();

    expect(Hash::check('brand-new-pass-1', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

it('rejects an invalid reset token', function (): void {
    User::factory()->create(['email' => 'mona@example.com']);

    $this->postJson('/v1/auth/reset-password', [
        'token' => 'bogus', 'email' => 'mona@example.com',
        'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
    ])->assertJsonValidationErrors('email');
});
