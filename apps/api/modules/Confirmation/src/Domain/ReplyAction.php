<?php

declare(strict_types=1);

namespace Modules\Confirmation\Domain;

/**
 * The buttons on the confirmation template. Their payload is "trik:c1:<action>:<run id>", set by Trik
 * when it sends the template, so a reply maps back to exactly one order.
 */
enum ReplyAction: string
{
    case Confirm = 'confirm';
    case Change = 'change';
    case Cancel = 'cancel';

    public const PREFIX = 'trik:c1:';

    public function payload(string $runId): string
    {
        return self::PREFIX.$this->value.':'.$runId;
    }

    /** @return array{0: self, 1: string}|null the action and run id, or null if this is not ours */
    public static function parse(?string $payload): ?array
    {
        if ($payload === null || preg_match('/^trik:c1:(confirm|change|cancel):([0-9a-f-]{36})$/', $payload, $m) !== 1) {
            return null;
        }

        return [self::from($m[1]), $m[2]];
    }
}
