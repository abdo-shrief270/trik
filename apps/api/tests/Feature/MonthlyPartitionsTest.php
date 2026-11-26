<?php

declare(strict_types=1);

use App\Support\Database\MonthlyPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    DB::rollBack();
    DB::connection('owner')->statement('drop table if exists partition_probe cascade');
    DB::beginTransaction();
});

it('creates monthly partitions ahead with row-level security, and drops old ones', function (): void {
    $owner = DB::connection('owner');
    $owner->statement('drop table if exists partition_probe cascade');
    $owner->statement('create table partition_probe (id int, workspace_id uuid, created_at timestamptz not null) partition by range (created_at)');

    $created = MonthlyPartitions::ensure('partition_probe', CarbonImmutable::parse('2026-11-15'), 2, 'owner');

    expect($created)->toBe(['partition_probe_p202611', 'partition_probe_p202612', 'partition_probe_p202701'])
        ->and(MonthlyPartitions::ensure('partition_probe', CarbonImmutable::parse('2026-11-15'), 2, 'owner'))->toBe([])
        ->and(MonthlyPartitions::partitions('partition_probe', 'owner'))->toContain('partition_probe_default')
        ->and($owner->scalar("select bool_and(relrowsecurity) from pg_class where relname like 'partition_probe_%'"))->toBeTrue();

    $owner->insert("insert into partition_probe values (1, gen_random_uuid(), '2026-12-03')");
    expect(MonthlyPartitions::defaultRows('partition_probe', 'owner'))->toBe(0);

    expect(MonthlyPartitions::dropOlderThan('partition_probe', CarbonImmutable::parse('2027-01-10'), 'owner'))
        ->toBe(['partition_probe_p202611', 'partition_probe_p202612']);
});

it('partitions the WhatsApp tables from the first migration', function (string $table): void {
    expect(MonthlyPartitions::partitions($table, 'owner'))
        ->toContain($table.'_default', MonthlyPartitions::name($table, now()), MonthlyPartitions::name($table, now()->addMonths(3)));
})->with(['whatsapp_webhook_events', 'whatsapp_messages', 'whatsapp_broadcast_sends']);
