<?php

declare(strict_types=1);

namespace Modules\Contacts\Application;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contacts\Infrastructure\Models\Contact;

/** Finds or creates the workspace's contacts, for other modules. */
final class ContactDirectory
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * The contact of each phone, created when new, in two queries whatever the count.
     * A name fills an empty name (WhatsApp profile names never overwrite one the team typed).
     *
     * @param  array<string, string|null>  $phones  phone digits => profile name
     * @return array<string, string> phone => contact id
     */
    public function resolve(array $phones): array
    {
        if ($phones === []) {
            return [];
        }

        $workspace = $this->tenant->require();
        $values = [];
        $bindings = [];
        ksort($phones); // a stable lock order between concurrent batches

        foreach ($phones as $phone => $name) {
            $values[] = '(?::uuid, ?::uuid, ?, ?, now(), now())';
            array_push($bindings, (string) Str::uuid7(), $workspace, (string) $phone, $name === null ? null : Str::limit($name, 250, ''));
        }

        DB::statement(
            'insert into contacts_contacts (id, workspace_id, phone, name, created_at, updated_at) values '.implode(', ', $values)
            .' on conflict (workspace_id, phone) do update set name = excluded.name, updated_at = now()'
            .' where contacts_contacts.name is null and excluded.name is not null',
            $bindings,
        );

        /** @var array<string, string> */
        return Contact::query()->whereIn('phone', array_map('strval', array_keys($phones)))->pluck('id', 'phone')->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{id: string, name: string|null, phone: string}>
     */
    public function find(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Contact::query()->whereIn('id', array_values(array_unique($ids)))->get(['id', 'name', 'phone']) as $c) {
            $out[$c->id] = ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone];
        }

        return $out;
    }
}
