<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use App\Support\Http\Problem;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Workspaces\Application\CreateOrganization;
use Modules\Workspaces\Application\CreateWorkspace;
use Modules\Workspaces\Application\Invitations;
use Modules\Workspaces\Application\WorkspaceData;
use Modules\Workspaces\Application\WorkspaceDirectory;

/**
 * Sign-up, in one transaction. Either it creates the user's own organization and first workspace, or, with a team
 * invitation (#36), it joins the workspace the invitation is for and creates nothing else. The invitation link was
 * sent to the address, so that account starts verified.
 */
final class RegisterUser
{
    public function __construct(
        private readonly CreateOrganization $createOrganization,
        private readonly CreateWorkspace $createWorkspace,
        private readonly Invitations $invitations,
        private readonly WorkspaceDirectory $directory,
    ) {}

    /** @return array{0: User, 1: WorkspaceData} */
    public function handle(string $name, string $email, string $password, ?string $workspaceName, ?string $invitation = null): array
    {
        $email = mb_strtolower($email);

        if ($invitation !== null && ! $this->invitations->isOpenFor($invitation, $email)) {
            throw Problem::invalid(['invitation' => ['This invitation is not valid for this email address, or it has expired.']]);
        }

        [$user, $workspace] = DB::transaction(function () use ($name, $email, $password, $workspaceName, $invitation): array {
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);

            if ($invitation !== null) {
                $workspaceId = $this->invitations->accept($invitation, $user->id);

                return [$user, $this->directory->find($workspaceId, $user->id) ?? throw new \LogicException('joined workspace not found')];
            }

            // Sign-up creates the organization and its first workspace (ADR 0012); a one-brand
            // seller never needs to know the organization exists.
            $organization = $this->createOrganization->handle((string) $workspaceName, $user->id);

            return [$user, $this->createWorkspace->handle((string) $workspaceName, $user->id, $organization->id)];
        });

        // refreshed: an invitation verified the address, and then no verification email is due
        $user->refresh();
        event(new Registered($user));

        return [$user, $workspace];
    }
}
