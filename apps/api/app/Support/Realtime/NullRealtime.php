<?php

declare(strict_types=1);

namespace App\Support\Realtime;

/** Used when Centrifugo is not configured (local, tests). */
final class NullRealtime implements Realtime
{
    public function publish(array $messages): void {}
}
