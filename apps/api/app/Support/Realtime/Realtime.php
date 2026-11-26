<?php

declare(strict_types=1);

namespace App\Support\Realtime;

/**
 * Pushes small notifications to connected web and mobile clients (Centrifugo in production).
 * Best effort: a failed publish is reported and never fails the caller; clients re-sync from the
 * API when they reconnect. Publish after commit, never inside a transaction.
 */
interface Realtime
{
    /** @param list<array{channel: string, data: array<string, mixed>}> $messages */
    public function publish(array $messages): void;
}
