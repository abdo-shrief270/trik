<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use InvalidArgumentException;

/**
 * Every permission in the product, declared by the module that owns it
 * (ModuleServiceProvider::permissions()). Keys look like `module.thing.action`.
 *
 * Grants are lists of keys, with two shorthands:
 *   `*` or `whatsapp.*`   everything / everything in a module
 *   `!billing.*`          deny; a deny always beats an allow
 */
final class PermissionRegistry
{
    /** @var array<string, array{module: string, description: string}> */
    private array $permissions = [];

    /** @param array<string, string> $permissions key => description */
    public function register(string $module, array $permissions): void
    {
        foreach ($permissions as $key => $description) {
            if (preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*){2}$/', $key) !== 1) {
                throw new InvalidArgumentException("Permission keys look like module.thing.action, got {$key}");
            }

            $this->permissions[$key] = ['module' => $module, 'description' => $description];
        }

        ksort($this->permissions);
    }

    public function has(string $key): bool
    {
        return isset($this->permissions[$key]);
    }

    /** @return array<string, array{module: string, description: string}> */
    public function all(): array
    {
        return $this->permissions;
    }

    /** @param list<string> $grants */
    public function allows(array $grants, string $key): bool
    {
        $allowed = false;

        foreach ($grants as $grant) {
            $deny = str_starts_with($grant, '!');
            $pattern = $deny ? substr($grant, 1) : $grant;

            if (self::matches($pattern, $key)) {
                if ($deny) {
                    return false;
                }
                $allowed = true;
            }
        }

        return $allowed;
    }

    /**
     * The registered keys a set of grants adds up to (what clients use to show or hide UI).
     *
     * @param  list<string>  $grants
     * @return list<string>
     */
    public function expand(array $grants): array
    {
        return array_values(array_filter(array_keys($this->permissions), fn (string $key): bool => $this->allows($grants, $key)));
    }

    /** True if every grant names a registered permission or a module wildcard. */
    public function isValidGrant(string $grant): bool
    {
        $pattern = ltrim($grant, '!');

        if ($pattern === '*') {
            return true;
        }

        foreach (array_keys($this->permissions) as $key) {
            if (self::matches($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $pattern, string $key): bool
    {
        if ($pattern === '*' || $pattern === $key) {
            return true;
        }

        return str_ends_with($pattern, '.*') && str_starts_with($key, substr($pattern, 0, -1));
    }
}
