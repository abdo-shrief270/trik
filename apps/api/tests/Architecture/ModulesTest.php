<?php

declare(strict_types=1);

use App\Support\Modules\ModuleRegistry;

/*
 * Module boundaries (see apps/api/README.md):
 *  - a module may use another module's Application and Domain layers only;
 *  - Domain code is plain PHP with no framework dependencies;
 *  - the shared kernel in app/ never depends on a module.
 */

$modules = ModuleRegistry::all(dirname(__DIR__, 2).'/modules');

arch('app/ does not depend on any module')
    ->expect('App')
    ->not->toUse('Modules');

it('declares strict types in every PHP file under app/ and modules/', function (): void {
    $root = dirname(__DIR__, 2);
    $missing = [];

    foreach ([$root.'/app', $root.'/modules'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && ! str_contains((string) file_get_contents($file->getPathname()), 'declare(strict_types=1);')) {
                $missing[] = str_replace($root.'/', '', $file->getPathname());
            }
        }
    }

    expect($missing)->toBe([]);
});

foreach ($modules as $module) {
    arch("{$module}: Domain has no framework dependencies")
        ->expect("Modules\\{$module}\\Domain")
        ->not->toUse(['Illuminate', 'Laravel', 'App', 'Symfony']);

    foreach ($modules as $other) {
        if ($other === $module) {
            continue;
        }

        arch("{$module} does not reach into {$other} internals")
            ->expect("Modules\\{$module}")
            ->not->toUse(["Modules\\{$other}\\Infrastructure", "Modules\\{$other}\\Http"]);
    }
}

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
