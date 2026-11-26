<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "feature:inbox": the route exists only where the feature is rolled out (config trik.features).
 * Values: 'all', 'none', or a comma-separated list of workspace ids. Runs after "workspace".
 */
final class EnsureFeatureEnabled
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! self::enabled($feature, $this->tenant->id())) {
            throw Problem::notFound();
        }

        return $next($request);
    }

    public static function enabled(string $feature, ?string $workspaceId): bool
    {
        $setting = trim((string) config("trik.features.{$feature}", 'none'));

        return match ($setting) {
            'all' => true,
            'none', '' => false,
            default => $workspaceId !== null && in_array($workspaceId, array_map('trim', explode(',', $setting)), true),
        };
    }
}
