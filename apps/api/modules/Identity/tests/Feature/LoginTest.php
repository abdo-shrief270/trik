<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use Modules\Identity\Infrastructure\Models\User;

beforeEach(function (): void {
    RateLimiter::clear('mona@example.com|127.0.0.1');
    $this->user = User::factory()->create(['email' => 'mona@example.com']);
});

it('issues a token for valid credentials', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'MONA@example.com', 'password' => 'password', 'device_name' => 'iPhone',
    ])->assertOk();

    expect($response->json('token'))->toBeString()
        ->and($this->user->tokens()->count())->toBe(1);
});

it('signs the web app in with a session cookie', function (): void {
    $this->withHeader('Origin', 'http://localhost:3000')
        ->postJson('/api/v1/auth/login', ['email' => 'mona@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonMissingPath('token');

    $this->assertAuthenticatedAs($this->user, 'web');
});

it('rejects a wrong password', function (): void {
    $this->postJson('/api/v1/auth/login', ['email' => 'mona@example.com', 'password' => 'nope', 'device_name' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('locks the account out after five failed attempts', function (): void {
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'mona@example.com', 'password' => 'nope', 'device_name' => 'x'])
            ->assertUnprocessable();
    }

    // even the right password is refused while locked
    $this->postJson('/api/v1/auth/login', ['email' => 'mona@example.com', 'password' => 'password', 'device_name' => 'x'])
        ->assertStatus(429);
});

it('signs out the current token only', function (): void {
    $keep = $this->user->createToken('tablet')->plainTextToken;
    $drop = $this->user->createToken('phone')->plainTextToken;

    $this->withToken($drop)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($this->user->tokens()->pluck('name')->all())->toBe(['tablet']);
    app('auth')->forgetGuards();
    $this->withToken($keep)->getJson('/api/v1/auth/me')->assertOk();
});

it('requires sign-in for protected routes', function (): void {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});
