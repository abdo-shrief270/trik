<?php

declare(strict_types=1);

namespace Modules\Workspaces\Infrastructure\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Workspaces\Infrastructure\Models\Organization;
use Modules\Workspaces\Infrastructure\Models\Workspace;

/** @extends Factory<Workspace> */
final class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'organization_id' => fn (): string => Organization::query()->create(['name' => $name])->id,
        ];
    }
}
