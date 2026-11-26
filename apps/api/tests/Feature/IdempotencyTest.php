<?php

declare(strict_types=1);

use App\Support\Http\Idempotency;
use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Models\User;

beforeEach(function (): void {
    $this->calls = 0;
    Route::middleware('api')->post('/v1/_probe', function () {
        $this->calls++;

        return response()->json(['call' => $this->calls], 201);
    });
    Route::middleware(['api', 'idempotency:required'])->post('/v1/_strict', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->post('/v1/_fails', function () {
        $this->calls++;
        abort(503);
    });
    $this->token = User::factory()->create()->createToken('t')->plainTextToken;
});

it('replays the first response for a retried key', function (): void {
    $first = $this->withToken($this->token)->withHeader(Idempotency::HEADER, 'k-1')->postJson('/v1/_probe', ['a' => 1])->assertCreated();
    $again = $this->withToken($this->token)->withHeader(Idempotency::HEADER, 'k-1')->postJson('/v1/_probe', ['a' => 1])->assertCreated();

    expect($this->calls)->toBe(1)
        ->and($again->json())->toBe($first->json())
        ->and($again->headers->get(Idempotency::REPLAYED_HEADER))->toBe('true');
});

it('runs again for a new key and without a key', function (): void {
    $this->withToken($this->token)->withHeader(Idempotency::HEADER, 'k-1')->postJson('/v1/_probe');
    $this->withToken($this->token)->withHeader(Idempotency::HEADER, 'k-2')->postJson('/v1/_probe');
    $this->flushHeaders()->withToken($this->token)->postJson('/v1/_probe');

    expect($this->calls)->toBe(3);
});

it('refuses the same key with a different body', function (): void {
    $this->withToken($this->token)->withHeader(Idempotency::HEADER, 'k-1')->postJson('/v1/_probe', ['a' => 1]);
    $this->withToken($this->token)->withHeader(Idempotency::HEADER, 'k-1')->postJson('/v1/_probe', ['a' => 2])
        ->assertUnprocessable()->assertJsonPath('code', 'idempotency-key-reused');
});

it('scopes keys to the caller', function (): void {
    $other = User::factory()->create()->createToken('t')->plainTextToken;
    $this->withToken($this->token)->withHeader(Idempotency::HEADER, 'shared')->postJson('/v1/_probe');
    app('auth')->forgetGuards();
    $this->withToken($other)->withHeader(Idempotency::HEADER, 'shared')->postJson('/v1/_probe');

    expect($this->calls)->toBe(2);
});

it('does not store server errors, so they can be retried', function (): void {
    $this->withToken($this->token)->withHeader(Idempotency::HEADER, 'k-1')->postJson('/v1/_fails')->assertStatus(503);
    $this->withToken($this->token)->withHeader(Idempotency::HEADER, 'k-1')->postJson('/v1/_fails')->assertStatus(503);

    expect($this->calls)->toBe(2);
});

it('refuses a retry while the first request is still running', function (): void {
    $slot = 'idempotency:'.hash('sha256', implode('|', ['user:'.User::query()->first()->id, '-', 'POST', 'v1/_probe', 'k-busy']));
    cache()->lock($slot.':lock', 60)->get();

    $this->withToken($this->token)->withHeader(Idempotency::HEADER, 'k-busy')->postJson('/v1/_probe')
        ->assertConflict()->assertJsonPath('code', 'idempotency-in-progress');
});

it('requires a key where the route says so', function (): void {
    $this->withToken($this->token)->postJson('/v1/_strict')->assertStatus(400)->assertJsonPath('code', 'idempotency-key-required');
    $this->withToken($this->token)->withHeader(Idempotency::HEADER, 'k-1')->postJson('/v1/_strict')->assertOk();
});
