<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\WorkspaceMembership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activates the workspace named in the X-Workspace-Id header for the rest of the request,
 * after checking that the signed-in user is a member. Always clears it afterwards,
 * which matters under Octane where the worker and its DB connection are reused.
 */
final class ResolveWorkspace
{
    public const HEADER = 'X-Workspace-Id';

    public function __construct(
        private readonly TenantContext $context,
        private readonly WorkspaceMembership $membership,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = (string) $request->header(self::HEADER, '');
        $user = $request->user();

        if (! Str::isUuid($workspaceId)) {
            throw Problem::badRequest('workspace-header-missing', 'Send the workspace id in the '.self::HEADER.' header.');
        }

        if ($user === null || ! $this->membership->isMember($workspaceId, (string) $user->getAuthIdentifier())) {
            throw Problem::forbidden('not-a-member', 'You are not a member of this workspace.');
        }

        return $this->context->run($workspaceId, fn (): Response => $next($request));
    }
}
