<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The production login (trik_api) can only act as trik_app: even if an attacker with SQL access
 * drops the role (SET ROLE NONE), the bare login has no access to any table (infra/postgres/roles.sql).
 */
it('leaves a NOINHERIT login with no access once it drops the app role', function (): void {
    $owner = DB::connection('owner');
    $owner->statement("do $$ begin if not exists (select 1 from pg_roles where rolname = 'trik_probe_login') then create role trik_probe_login login password 'probe' noinherit nobypassrls; end if; end $$");
    $owner->statement('grant trik_app to trik_probe_login');

    config(['database.connections.probe' => [...config('database.connections.pgsql'), 'username' => 'trik_probe_login', 'password' => 'probe']]);
    $probe = DB::connection('probe');

    expect($probe->scalar('select current_user'))->toBe('trik_app')
        ->and((int) $probe->scalar('select count(*) from users'))->toBeGreaterThanOrEqual(0);

    // RESET ROLE goes back to the startup role, which is still trik_app…
    $probe->statement('reset role');
    expect($probe->scalar('select current_user'))->toBe('trik_app');

    // …and SET ROLE NONE reaches the bare login, which can read nothing
    $probe->statement('set role none');
    expect($probe->scalar('select current_user'))->toBe('trik_probe_login')
        ->and(fn () => $probe->select('select count(*) from users'))->toThrow(QueryException::class, 'permission denied');

    DB::purge('probe');
});
