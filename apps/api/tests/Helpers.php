<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveWorkspace;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Workspaces\Application\CreateWorkspace;

/** Creates a user and returns its id. */
function userId(): string
{
    return User::factory()->create()->id;
}

/**
 * A signed-in owner with a fresh workspace.
 *
 * @return array{user: User, workspace: string, headers: array<string, string>}
 */
function ownerWithWorkspace(string $name = 'Test Store'): array
{
    $user = User::factory()->create();
    $workspace = app(CreateWorkspace::class)->handle($name, $user->id);

    return [
        'user' => $user,
        'workspace' => $workspace->id,
        'headers' => [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            ResolveWorkspace::HEADER => $workspace->id,
        ],
    ];
}
