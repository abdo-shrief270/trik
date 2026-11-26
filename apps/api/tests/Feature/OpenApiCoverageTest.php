<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Keeps packages/contracts/openapi/trik.v1.yaml honest: every /v1 route must be documented,
 * with the same path parameters and the same required permission.
 *
 * @return array<string, array{permission: string|null, planned: bool}> "METHOD /path" => details
 */
function openApiOperations(): array
{
    $file = dirname(__DIR__, 4).'/packages/contracts/openapi/trik.v1.yaml';
    $ops = [];
    $path = null;
    $current = null;
    $inPaths = false;

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === 'paths:') {
            $inPaths = true;

            continue;
        }
        if ($inPaths && preg_match('/^\S/', $line) === 1) {
            break; // end of paths
        }
        if (preg_match("#^  '?(/[^':]*)'?:\s*$#", $line, $m) === 1) {
            $path = $m[1];
        } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m) === 1) {
            $current = strtoupper($m[1]).' '.$path;
            $ops[$current] = ['permission' => null, 'planned' => false];
        } elseif ($current !== null && preg_match('/^      x-permission:\s*(\S+)/', $line, $m) === 1) {
            $ops[$current]['permission'] = $m[1];
        } elseif ($current !== null && preg_match('/^      x-status:\s*planned/', $line) === 1) {
            $ops[$current]['planned'] = true;
        }
    }

    return $ops;
}

it('documents every /v1 route in the OpenAPI contract, with its permission', function (): void {
    $spec = openApiOperations();
    expect($spec)->not->toBeEmpty();

    $missing = [];
    $wrongPermission = [];

    /** @var Route $route */
    foreach (Router::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'v1/') || str_starts_with($route->uri(), 'v1/_')) {
            continue;
        }

        $path = rtrim(substr($route->uri(), 2), '/') ?: '/';
        $permission = null;
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'can:')) {
                $permission = substr($m, 4);
            }
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $key = "{$method} {$path}";

            if (! isset($spec[$key])) {
                $missing[] = $key;
            } elseif ($spec[$key]['permission'] !== $permission) {
                $wrongPermission[] = "{$key}: route needs ".($permission ?? 'none').', contract says '.($spec[$key]['permission'] ?? 'none');
            }
        }
    }

    expect($missing)->toBe([])->and($wrongPermission)->toBe([]);
});

it('only lists unbuilt operations as planned', function (): void {
    $built = [];
    foreach (Router::getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            $built[$method.' '.(rtrim(substr($route->uri(), 2), '/') ?: '/')] = true;
        }
    }

    $stale = array_keys(array_filter(openApiOperations(), fn (array $op, string $key): bool => ! $op['planned'] && ! isset($built[$key]), ARRAY_FILTER_USE_BOTH));

    expect($stale)->toBe([]);
});
