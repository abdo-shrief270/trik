<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveWorkspace;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Workspaces\Application\CreateWorkspace;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test')->plainTextToken;
});

it('creates more workspaces and lists only the user\'s own', function (): void {
    app(CreateWorkspace::class)->forUser('Someone else', User::factory()->create()->id);

    $this->withToken($this->token)->postJson('/v1/workspaces', ['name' => 'Second Brand'])
        ->assertCreated()
        ->assertJsonPath('data.role', 'owner');

    $this->withToken($this->token)->getJson('/v1/workspaces')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Second Brand');
});

it('activates a workspace from the header for members', function (): void {
    $workspace = app(CreateWorkspace::class)->forUser('Mine', $this->user->id);

    $this->withToken($this->token)
        ->withHeader(ResolveWorkspace::HEADER, $workspace->id)
        ->getJson('/v1/workspace')
        ->assertOk()
        ->assertJsonPath('data.id', $workspace->id);
});

it('refuses a workspace the user is not a member of', function (): void {
    $foreign = app(CreateWorkspace::class)->forUser('Not mine', User::factory()->create()->id);

    $this->withToken($this->token)
        ->withHeader(ResolveWorkspace::HEADER, $foreign->id)
        ->getJson('/v1/workspace')
        ->assertForbidden();
});

it('requires the workspace header', function (): void {
    $this->withToken($this->token)->getJson('/v1/workspace')->assertStatus(400);
});

it('gives each workspace a unique slug', function (): void {
    $a = app(CreateWorkspace::class)->forUser('Nile Shop', $this->user->id);
    $b = app(CreateWorkspace::class)->forUser('Nile Shop', $this->user->id);

    expect($a->slug)->toBe('nile-shop')->and($b->slug)->not->toBe('nile-shop')->toStartWith('nile-shop-');
});
