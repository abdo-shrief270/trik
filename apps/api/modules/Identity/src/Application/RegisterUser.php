<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Workspaces\Application\CreateWorkspace;
use Modules\Workspaces\Application\WorkspaceData;

/** Sign-up: creates the user and their first workspace in one transaction. */
final class RegisterUser
{
    public function __construct(private readonly CreateWorkspace $createWorkspace) {}

    /** @return array{0: User, 1: WorkspaceData} */
    public function handle(string $name, string $email, string $password, string $workspaceName): array
    {
        [$user, $workspace] = DB::transaction(function () use ($name, $email, $password, $workspaceName): array {
            $user = User::query()->create(['name' => $name, 'email' => mb_strtolower($email), 'password' => $password]);

            return [$user, $this->createWorkspace->handle($workspaceName, $user->id)];
        });

        event(new Registered($user));

        return [$user, $workspace];
    }
}
