<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Migrations run on the owner connection (the app connection is the restricted role).
     * Keeps the owner pointed at the same database as the app, including parallel runs.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        config(['database.connections.owner.database' => config('database.connections.pgsql.database')]);
        DB::purge('owner');

        return [
            '--database' => 'owner',
            '--drop-views' => true,
            '--drop-types' => true,
            '--seed' => false,
        ];
    }
}
