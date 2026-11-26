<?php

declare(strict_types=1);

namespace App\Support\Modules;

use InvalidArgumentException;

/**
 * Finds modules on disk. A folder in modules/ counts as an active module
 * once it has a src/{Name}ServiceProvider.php file.
 */
final class ModuleRegistry
{
    /** @return list<string> module names, sorted */
    public static function all(string $modulesPath): array
    {
        $dirs = glob($modulesPath.'/*', GLOB_ONLYDIR) ?: [];
        $names = array_map('basename', $dirs);
        sort($names);

        return $names;
    }

    /** @return list<class-string<ModuleServiceProvider>> */
    public static function providers(string $modulesPath): array
    {
        $providers = [];

        foreach (self::all($modulesPath) as $name) {
            $class = "Modules\\{$name}\\{$name}ServiceProvider";

            if (is_file("{$modulesPath}/{$name}/src/{$name}ServiceProvider.php") && is_subclass_of($class, ModuleServiceProvider::class)) {
                $providers[] = $class;
            }
        }

        return $providers;
    }

    public static function nameFromProvider(string $class): string
    {
        if (preg_match('/^Modules\\\\([A-Za-z0-9]+)\\\\\1ServiceProvider$/', $class, $m) !== 1) {
            throw new InvalidArgumentException("{$class} does not follow Modules\\{Name}\\{Name}ServiceProvider");
        }

        return $m[1];
    }
}
