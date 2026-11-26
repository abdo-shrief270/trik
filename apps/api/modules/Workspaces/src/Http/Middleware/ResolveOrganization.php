<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Middleware;

use App\Support\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Workspaces\Application\ActiveOrganization;
use Modules\Workspaces\Infrastructure\Models\OrganizationMember;
use Symfony\Component\HttpFoundation\Response;

/** Activates the organization in X-Organization-Id after checking the caller belongs to it. */
final class ResolveOrganization
{
    public const HEADER = 'X-Organization-Id';

    public function __construct(private readonly ActiveOrganization $active) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->header(self::HEADER, '');

        if (! Str::isUuid($id)) {
            throw Problem::badRequest('organization-header-missing', 'Send the organization id in the '.self::HEADER.' header.');
        }

        $member = OrganizationMember::query()
            ->where('organization_id', $id)
            ->where('user_id', (string) $request->user()?->getAuthIdentifier())
            ->first();

        if ($member === null) {
            throw Problem::forbidden('not-in-organization', 'You are not a member of this organization.');
        }

        $this->active->set($id, $member->role);

        try {
            return $next($request);
        } finally {
            $this->active->forget();
        }
    }
}
