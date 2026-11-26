<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Identity\Infrastructure\Models\User;

function signUp(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/auth/register', [
        'name' => 'Mona Adel',
        'email' => 'Mona@Example.com',
        'password' => 'secret-pass-123',
        'password_confirmation' => 'secret-pass-123',
        'workspace_name' => 'Mona Store',
        ...$overrides,
    ]);
}

it('creates the user and their first workspace, and returns a token for mobile', function (): void {
    Notification::fake();

    $response = signUp(['device_name' => 'Pixel 9'])->assertCreated();

    $response->assertJsonPath('user.email', 'mona@example.com')
        ->assertJsonPath('user.email_verified', false)
        ->assertJsonPath('workspace.name', 'Mona Store')
        ->assertJsonPath('workspace.slug', 'mona-store')
        ->assertJsonPath('workspace.role', 'owner');

    expect($response->json('token'))->toBeString();

    $user = User::query()->where('email', 'mona@example.com')->firstOrFail();
    Notification::assertSentTo($user, VerifyEmail::class);

    $this->withToken($response->json('token'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonCount(1, 'workspaces');
});

it('starts a cookie session for the web app', function (): void {
    signUp()
        ->assertCreated()
        ->assertJsonMissingPath('token');

    $this->assertAuthenticated('web');
})->with([fn () => test()->withHeader('Origin', 'http://localhost:3000')]);

it('asks non-browser clients for a device name', function (): void {
    signUp()->assertUnprocessable()->assertJsonValidationErrors('device_name');
});

it('rejects a duplicate email and weak passwords', function (): void {
    User::factory()->create(['email' => 'mona@example.com']);

    signUp(['device_name' => 'x'])->assertJsonValidationErrors('email');
    signUp(['email' => 'new@example.com', 'password' => 'short', 'password_confirmation' => 'short', 'device_name' => 'x'])
        ->assertJsonValidationErrors('password');
});
