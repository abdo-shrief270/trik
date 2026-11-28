<?php

declare(strict_types=1);

namespace Modules\Confirmation\Application;

use Illuminate\Support\Carbon;
use Modules\Confirmation\Infrastructure\Models\ConfirmationRun;

/** Facts about confirmation in the active workspace, for reports. */
final class ConfirmationStats
{
    /** When Trik first asked a customer to confirm here: the natural go-live date of a pilot. */
    public function firstRunAt(): ?Carbon
    {
        $first = ConfirmationRun::query()->min('created_at');

        return $first === null ? null : Carbon::parse((string) $first);
    }
}
