<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Laravel writes timestamps without an offset ("2026-11-27 10:00:00"). PostgreSQL reads those in the
 * session's time zone, which defaults to the server's (Africa/Cairo on a Cairo box). Both connections
 * pin the session to UTC, so a timestamp always round-trips whatever the server is set to.
 */
it('keeps both database sessions in UTC, like the app', function (string $connection): void {
    DB::connection($connection)->statement("set time zone 'Africa/Cairo'"); // a server with another default…
    DB::purge($connection);                                                // …and a fresh connection

    expect(DB::connection($connection)->selectOne('show timezone')->TimeZone)->toBe('UTC');
})->with(['pgsql', 'owner']);

it('round-trips a timestamp written by the app', function (): void {
    $at = Carbon::parse('2026-11-27 10:00:00', 'UTC');

    $back = DB::selectOne('select ?::timestamptz as t', [$at->format('Y-m-d H:i:s')])->t;

    expect(Carbon::parse($back)->equalTo($at))->toBeTrue();
});
