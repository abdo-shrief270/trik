<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Console;

use App\Support\Database\MonthlyPartitions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily housekeeping (runs on the owner connection):
 *  - creates monthly partitions three months ahead
 *  - drops raw webhook partitions past retention
 *  - prunes inbound de-duplication keys past the redelivery window
 *  - warns when rows landed in a default partition (maintenance fell behind)
 */
final class MaintainCommand extends Command
{
    protected $signature = 'whatsapp:maintain';

    protected $description = 'Create WhatsApp partitions ahead, drop expired ones, prune de-duplication keys';

    public const TABLES = ['whatsapp_webhook_events', 'whatsapp_messages', 'whatsapp_broadcast_sends'];

    public function handle(): int
    {
        $connection = 'owner';

        foreach (self::TABLES as $table) {
            foreach (MonthlyPartitions::ensure($table, now(), 3, $connection) as $created) {
                $this->info("created {$created}");
            }

            if (($rows = MonthlyPartitions::defaultRows($table, $connection)) > 0) {
                $this->error("{$rows} rows in {$table}_default: create the missing partitions and move them");
            }
        }

        $months = max(1, (int) config('whatsapp.retention.webhook_events_months', 2));
        foreach (MonthlyPartitions::dropOlderThan('whatsapp_webhook_events', now()->subMonths($months), $connection) as $dropped) {
            $this->info("dropped {$dropped}");
        }

        $days = max(1, (int) config('whatsapp.retention.inbound_keys_days', 30));
        do {
            $deleted = DB::connection($connection)->delete(
                'delete from whatsapp_inbound_keys where ctid = any(array(select ctid from whatsapp_inbound_keys where created_at < now() - make_interval(days => ?) limit 10000))',
                [$days],
            );
        } while ($deleted === 10000);

        return self::SUCCESS;
    }
}
