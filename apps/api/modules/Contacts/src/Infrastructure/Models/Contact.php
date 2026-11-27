<?php

declare(strict_types=1);

namespace Modules\Contacts\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $phone
 * @property string|null $name
 * @property Carbon|null $created_at
 */
final class Contact extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'contacts_contacts';

    protected $guarded = ['id', 'workspace_id'];
}
