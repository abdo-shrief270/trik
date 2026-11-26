<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Support\Tenancy\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Monthly range partitions on a timestamptz column (created_at).
 *
 * High-volume tables are partitioned from day one so old data is dropped a month at a
 * time (cheap, no bloat) instead of deleted row by row. A default partition catches rows
 * if maintenance ever falls behind; ensure() keeps it empty by creating months ahead.
 * Partitions get row-level security too, so they can't be read around the parent's policy.
 */
final class MonthlyPartitions
{
    /**
     * Creates the parent's default partition and monthly partitions from $from through $monthsAhead.
     *
     * @return list<string> created partitions
     */
    public static function ensure(string $table, CarbonInterface $from, int $monthsAhead = 3, ?string $connection = null, int $fillfactor = 100): array
    {
        self::assertIdentifier($table);
        $db = DB::connection($connection);
        $created = [];

        $db->statement(sprintf('create table if not exists "%s_default" partition of "%s" default', $table, $table));
        RowLevelSecurity::enable("{$table}_default", connection: $connection);

        $month = CarbonImmutable::instance($from)->utc()->startOfMonth();

        for ($i = 0; $i <= $monthsAhead; $i++, $month = $month->addMonth()) {
            $name = self::name($table, $month);

            if (self::exists($name, $connection)) {
                continue;
            }

            $db->statement(sprintf(
                "create table \"%s\" partition of \"%s\" for values from ('%s') to ('%s')%s",
                $name,
                $table,
                $month->toDateString(),
                $month->addMonth()->toDateString(),
                $fillfactor < 100 ? sprintf(' with (fillfactor = %d)', max(50, $fillfactor)) : '',
            ));
            RowLevelSecurity::enable($name, connection: $connection);
            $created[] = $name;
        }

        return $created;
    }

    /**
     * Drops whole months that end on or before the cutoff.
     *
     * @return list<string> dropped partitions
     */
    public static function dropOlderThan(string $table, CarbonInterface $cutoff, ?string $connection = null): array
    {
        self::assertIdentifier($table);
        $limit = CarbonImmutable::instance($cutoff)->utc()->startOfMonth();
        $dropped = [];

        foreach (self::partitions($table, $connection) as $name) {
            if (preg_match('/_p(\d{4})(\d{2})$/', $name, $m) !== 1) {
                continue;
            }

            $end = (new CarbonImmutable("{$m[1]}-{$m[2]}-01 00:00:00", 'UTC'))->addMonth();

            if ($end->lessThanOrEqualTo($limit)) {
                DB::connection($connection)->statement(sprintf('drop table "%s"', $name));
                $dropped[] = $name;
            }
        }

        return $dropped;
    }

    /**
     * Rows that get updated later (statuses) leave room on the page so updates stay HOT
     * (no index writes, less bloat). Append-only tables stay full.
     */
    public static function fillfactorFor(string $table): int
    {
        return in_array($table, ['whatsapp_messages', 'whatsapp_broadcast_sends'], true) ? 85 : 100;
    }

    /** @return list<string> */
    public static function partitions(string $table, ?string $connection = null): array
    {
        $rows = DB::connection($connection)->select(
            'select c.relname from pg_inherits i join pg_class c on c.oid = i.inhrelid join pg_class p on p.oid = i.inhparent where p.relname = ? order by c.relname',
            [$table],
        );

        return array_values(array_map(fn (object $r): string => (string) (((array) $r)['relname'] ?? ''), $rows));
    }

    public static function name(string $table, CarbonInterface $month): string
    {
        return $table.'_p'.$month->format('Ym');
    }

    public static function defaultRows(string $table, ?string $connection = null): int
    {
        self::assertIdentifier($table);

        return (int) DB::connection($connection)->scalar(sprintf('select count(*) from "%s_default"', $table));
    }

    private static function exists(string $name, ?string $connection): bool
    {
        return DB::connection($connection)->scalar('select to_regclass(?) is not null', [$name]) === true;
    }

    private static function assertIdentifier(string $name): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid identifier: {$name}");
        }
    }
}
