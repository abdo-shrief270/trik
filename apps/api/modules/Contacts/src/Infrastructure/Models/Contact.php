<?php

declare(strict_types=1);

namespace Modules\Contacts\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $phone
 * @property string|null $name
 */
final class Contact extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'contacts_contacts';

    protected $guarded = ['id', 'workspace_id'];
}
