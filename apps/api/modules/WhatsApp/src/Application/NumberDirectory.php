<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;

/** Which numbers the active workspace can send from, for other modules. */
final class NumberDirectory
{
    public function isActive(string $numberId): bool
    {
        return WhatsAppNumber::query()->whereKey($numberId)->where('status', 'active')->exists();
    }

    /** The workspace's first connected number, when nothing more specific is set. */
    public function defaultNumberId(): ?string
    {
        $id = WhatsAppNumber::query()->where('status', 'active')->orderBy('created_at')->value('id');

        return is_string($id) ? $id : null;
    }
}
