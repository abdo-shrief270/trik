<?php

declare(strict_types=1);

use App\Support\Modules\ModuleRegistry;

it('gives every module the standard layout and a README', function (string $module): void {
    $base = dirname(__DIR__, 2).'/modules/'.$module;

    foreach (['src/Domain', 'src/Application', 'src/Infrastructure', 'src/Http', 'database/migrations', 'README.md'] as $path) {
        expect(file_exists("{$base}/{$path}"))->toBeTrue("{$module} is missing {$path}");
    }
})->with(ModuleRegistry::all(dirname(__DIR__, 2).'/modules'));

it('names providers after their module', function (): void {
    expect(ModuleRegistry::nameFromProvider('Modules\\Orders\\OrdersServiceProvider'))->toBe('Orders')
        ->and(fn () => ModuleRegistry::nameFromProvider('Modules\\Orders\\Provider'))->toThrow(InvalidArgumentException::class);
});
