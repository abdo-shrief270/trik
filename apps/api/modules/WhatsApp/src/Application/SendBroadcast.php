<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\WhatsApp\Application\Jobs\SendBroadcastChunk;
use Modules\WhatsApp\Domain\Messages\TemplateMessage;
use Modules\WhatsApp\Domain\MessageStatus;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;

/**
 * Queues one template to many contacts from one number (the Campaigns module calls this).
 * Rows are inserted in bulk and sent by chunk jobs that respect the number's send rate.
 * At scale (ADR 0010) the Go dispatcher takes over the sending; the table stays the same.
 */
final class SendBroadcast
{
    public const CHUNK = 200;

    /**
     * @param  list<PhoneNumber>  $recipients
     * @return int sends queued (duplicates in the list are dropped)
     */
    public function handle(WhatsAppNumber $number, string $broadcastId, TemplateMessage $template, array $recipients): int
    {
        $unique = [];
        foreach ($recipients as $to) {
            $unique[$to->digits] = $to;
        }

        $now = now();
        $ids = [];

        DB::transaction(function () use ($unique, $number, $broadcastId, $template, $now, &$ids): void {
            foreach (array_chunk(array_values($unique), 1000) as $chunk) {
                $rows = [];

                foreach ($chunk as $to) {
                    $ids[] = $id = (string) Str::uuid7();
                    $rows[] = [
                        'id' => $id,
                        'workspace_id' => $number->workspace_id,
                        'number_id' => $number->id,
                        'broadcast_id' => $broadcastId,
                        'contact_phone' => $to->digits,
                        'template' => $template->name,
                        'status' => MessageStatus::Queued->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('whatsapp_broadcast_sends')->insert($rows);
            }
        });

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            SendBroadcastChunk::dispatch($number->id, $template, $chunk)->afterCommit();
        }

        return count($ids);
    }
}
