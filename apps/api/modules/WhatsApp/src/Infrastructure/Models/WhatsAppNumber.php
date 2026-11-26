<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\WhatsApp\Application\ConnectedNumber;
use Modules\WhatsApp\Domain\Provider;

/**
 * A seller's WhatsApp business number.
 *
 * @property string $id
 * @property string $workspace_id
 * @property Provider $provider
 * @property string $external_id phone_number_id (Meta) or channel id (360dialog)
 * @property string|null $waba_id
 * @property string|null $display_phone_number
 * @property string|null $verified_name
 * @property array<string, string> $credentials encrypted at rest
 * @property string $status
 */
final class WhatsAppNumber extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'whatsapp_numbers';

    protected $fillable = ['provider', 'external_id', 'waba_id', 'display_phone_number', 'verified_name', 'credentials', 'status'];

    protected $hidden = ['credentials'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'credentials' => 'encrypted:array',
        ];
    }

    public function toConnected(): ConnectedNumber
    {
        return new ConnectedNumber($this->id, $this->provider, $this->external_id, $this->waba_id, $this->credentials);
    }

    public function webhookSecret(): ?string
    {
        return $this->credentials['webhook_secret'] ?? null;
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'external_id' => $this->external_id,
            'waba_id' => $this->waba_id,
            'display_phone_number' => $this->display_phone_number,
            'verified_name' => $this->verified_name,
            'status' => $this->status,
        ];
    }
}
