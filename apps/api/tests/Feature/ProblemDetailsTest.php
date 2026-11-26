<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveWorkspace;
use App\Support\Http\RequestId;
use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Models\User;

it('answers validation errors as problem+json with field errors', function (): void {
    $response = $this->postJson('/v1/auth/login', ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://trik.chat/problems/validation-failed')
        ->assertJsonPath('code', 'validation-failed')
        ->assertJsonPath('status', 422)
        ->assertJsonPath('instance', '/v1/auth/login')
        ->assertJsonValidationErrors(['email', 'password']);

    expect($response->json('request_id'))->toBe($response->headers->get(RequestId::HEADER));
});

it('answers auth, permission and not-found errors in the same shape', function (): void {
    $this->getJson('/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

    $token = User::factory()->create()->createToken('t')->plainTextToken;
    $this->withToken($token)->withHeader(ResolveWorkspace::HEADER, (string) str()->uuid())
        ->getJson('/v1/workspace')->assertForbidden()->assertJsonPath('code', 'not-a-member');

    $this->getJson('/v1/nothing-here')->assertNotFound()->assertJsonPath('code', 'not-found');
});

it('hides internal error details outside debug mode', function (): void {
    config(['app.debug' => false]);
    Route::get('/v1/_boom', fn () => throw new RuntimeException('secret stack detail'));

    $this->getJson('/v1/_boom')
        ->assertStatus(500)
        ->assertJsonPath('code', 'internal-error')
        ->assertJsonMissing(['detail' => 'secret stack detail']);
});

it('keeps an incoming request id and makes one otherwise', function (): void {
    $this->withHeader(RequestId::HEADER, 'edge-abc-12345')->getJson('/healthz')->assertHeader(RequestId::HEADER, 'edge-abc-12345');
    expect($this->flushHeaders()->getJson('/healthz')->headers->get(RequestId::HEADER))->toMatch('/^[0-9a-f-]{36}$/');
});
