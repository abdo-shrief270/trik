<?php

declare(strict_types=1);

use Modules\Identity\Infrastructure\Models\User;

it('lists where the user is signed in and marks the current device', function (): void {
    $user = User::factory()->create();
    $user->createToken('Pixel 9');
    $current = $user->createToken('iPhone')->plainTextToken;

    $this->withToken($current)
        ->getJson('/v1/auth/sessions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['name' => 'iPhone', 'current' => true])
        ->assertJsonFragment(['name' => 'Pixel 9', 'current' => false]);
});

it('signs out another device', function (): void {
    $user = User::factory()->create();
    $other = $user->createToken('Pixel 9')->accessToken;
    $current = $user->createToken('iPhone')->plainTextToken;

    $this->withToken($current)->deleteJson("/v1/auth/sessions/token/{$other->getKey()}")->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['iPhone']);
});

it('cannot sign out someone else\'s device', function (): void {
    $victim = User::factory()->create()->createToken('theirs')->accessToken;
    $token = User::factory()->create()->createToken('mine')->plainTextToken;

    $this->withToken($token)->deleteJson("/v1/auth/sessions/token/{$victim->getKey()}")->assertNotFound();
});
