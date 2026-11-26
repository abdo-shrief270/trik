<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\WhatsApp\Domain\Provider;

/**
 * Finds which workspace a webhook belongs to before any workspace is active.
 *
 * Uses two narrow SECURITY DEFINER functions (see the WhatsApp migration) that return only
 * the workspace id and provider, so the app role never needs to read numbers across workspaces.
 */
final class WebhookRouter
{
    /** @return array{workspace_id: string, provider: Provider}|null */
    public function byNumberId(string $numberId): ?array
    {
        if (! Str::isUuid($numberId)) {
            return null;
        }

        $row = DB::selectOne('select workspace_id, provider from whatsapp_webhook_route(?)', [$numberId]);

        return $row === null ? null : ['workspace_id' => (string) $row->workspace_id, 'provider' => Provider::from((string) $row->provider)];
    }

    /** @return array{workspace_id: string, number_id: string}|null */
    public function byExternalId(Provider $provider, string $externalId): ?array
    {
        $row = DB::selectOne('select workspace_id, number_id from whatsapp_webhook_route_external(?, ?)', [$provider->value, $externalId]);

        return $row === null ? null : ['workspace_id' => (string) $row->workspace_id, 'number_id' => (string) $row->number_id];
    }
}
