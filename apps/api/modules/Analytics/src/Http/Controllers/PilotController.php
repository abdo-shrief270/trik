<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Analytics\Application\PilotReport;
use Modules\Analytics\Infrastructure\Models\PilotSettings;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Pilot results (#345): before vs after Trik for this seller. */
final class PilotController
{
    public function show(PilotReport $report): JsonResponse
    {
        return response()->json(['data' => $report->summary()]);
    }

    /** The go-live date (null: the first WhatsApp confirmation) and the baseline length. */
    public function update(Request $request, PilotReport $report): JsonResponse
    {
        $data = $request->validate([
            'live_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now', 'after:2020-01-01'],
            'baseline_days' => ['sometimes', 'integer', 'between:14,180'],
        ]);

        $settings = PilotSettings::current();
        if (array_key_exists('live_at', $data)) {
            $settings->live_at = $data['live_at'] === null ? null : Carbon::parse((string) $data['live_at']);
        }
        if (array_key_exists('baseline_days', $data)) {
            $settings->baseline_days = (int) $data['baseline_days'];
        }
        $settings->save();

        return response()->json(['data' => $report->summary()]);
    }

    /** One row per week, as a spreadsheet for the pilot review. */
    public function weekly(PilotReport $report): StreamedResponse
    {
        $rows = $report->weekly();
        $columns = ['week', 'period', 'orders', 'confirmed', 'cancelled', 'shipped', 'delivered', 'refused', 'open',
            'confirmation_rate', 'cancellation_rate', 'refusal_rate', 'median_minutes_to_confirm'];

        return response()->streamDownload(function () use ($rows, $columns): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads it as UTF-8
            fputcsv($out, $columns, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $c): string => self::cell($row[$c] ?? null), $columns), escape: '');
            }
            fclose($out);
        }, 'trik-pilot-weekly-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** Numbers as numbers; anything that a spreadsheet would run as a formula is neutralised. */
    private static function cell(int|float|string|null $v): string
    {
        $s = $v === null ? '' : (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $s) === 1 && ! is_numeric($s) ? "'".$s : $s;
    }
}
