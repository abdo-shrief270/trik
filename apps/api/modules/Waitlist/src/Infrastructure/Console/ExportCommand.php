<?php

declare(strict_types=1);

namespace Modules\Waitlist\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Waitlist\Domain\EntryStatus;
use Modules\Waitlist\Infrastructure\Models\WaitlistEntry;
use Symfony\Component\Console\Output\OutputInterface;

/** The team's list of pilot candidates: `php artisan waitlist:export > waitlist.csv`. */
final class ExportCommand extends Command
{
    protected $signature = 'waitlist:export {--status=confirmed : confirmed, pending, unsubscribed or all}';

    protected $description = 'Print the waitlist as CSV (confirmed entries by default)';

    private const COLUMNS = ['email', 'name', 'store_name', 'phone', 'platform', 'monthly_orders', 'locale', 'status', 'confirmed_at', 'created_at'];

    public function handle(): int
    {
        $status = (string) $this->option('status');
        if ($status !== 'all' && EntryStatus::tryFrom($status) === null) {
            $this->error('Unknown status. Use confirmed, pending, unsubscribed or all.');

            return self::INVALID;
        }

        $this->output->writeln(self::csv(self::COLUMNS), OutputInterface::OUTPUT_RAW);

        WaitlistEntry::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('created_at')
            ->each(function (WaitlistEntry $e): void {
                // Free text gets the formula guard; the phone is validated E.164 (its leading + is not a formula).
                $this->output->writeln(self::csv([
                    self::cell($e->email), self::cell($e->name), self::cell($e->store_name), $e->phone,
                    $e->platform->value, $e->monthly_orders->value, $e->locale, $e->status->value,
                    $e->confirmed_at?->toIso8601String() ?? '', $e->created_at->toIso8601String(),
                ]), OutputInterface::OUTPUT_RAW);
            });

        return self::SUCCESS;
    }

    /** @param list<string> $row */
    private static function csv(array $row): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open a temporary stream.');
        }
        fputcsv($stream, $row, escape: '');
        rewind($stream);
        $line = (string) stream_get_contents($stream);
        fclose($stream);

        return rtrim($line, "\n");
    }

    /** Values typed by strangers: neutralise spreadsheet formulas (=, +, -, @, tab, CR). */
    private static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
