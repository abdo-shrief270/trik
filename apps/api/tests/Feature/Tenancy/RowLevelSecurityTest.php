<?php

declare(strict_types=1);

use App\Support\Tenancy\BelongsToWorkspace;
use App\Support\Tenancy\MissingWorkspaceContext;
use App\Support\Tenancy\RowLevelSecurity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Workspaces\Infrastructure\Models\Workspace;

/** A throwaway tenant table, created on the owner connection like a real migration would. */
final class TenancyProbe extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'tenancy_probes';

    protected $fillable = ['label'];
}

beforeEach(function (): void {
    Schema::connection('owner')->dropIfExists('tenancy_probes');
    Schema::connection('owner')->create('tenancy_probes', function ($table): void {
        $table->uuid('id')->primary();
        $table->foreignUuid('workspace_id');
        $table->string('label');
        $table->timestamps();
    });
    RowLevelSecurity::enable('tenancy_probes', connection: 'owner');

    $this->a = Workspace::factory()->create(['owner_id' => userId()]);
    $this->b = Workspace::factory()->create(['owner_id' => userId()]);
    $this->ctx = app(TenantContext::class);
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
    DB::rollBack(); // release the test transaction before dropping the table it touched
    Schema::connection('owner')->dropIfExists('tenancy_probes');
    DB::beginTransaction();
});

it('runs the app connection as the restricted role, not the owner', function (): void {
    expect(DB::scalar('select current_user'))->toBe(config('database.connections.pgsql.role'))
        ->and(DB::scalar('select rolbypassrls or rolsuper from pg_roles where rolname = current_user'))->toBeFalse();
});

it('stamps new rows with the active workspace', function (): void {
    $row = $this->ctx->run($this->a->id, fn () => TenancyProbe::query()->create(['label' => 'mine']));

    expect($row->workspace_id)->toBe($this->a->id);
});

it('never lets workspace A read workspace B', function (): void {
    $this->ctx->run($this->a->id, fn () => TenancyProbe::query()->create(['label' => 'a-1']));
    $this->ctx->run($this->b->id, fn () => TenancyProbe::query()->create(['label' => 'b-1']));

    $this->ctx->run($this->a->id, function (): void {
        expect(TenancyProbe::query()->pluck('label')->all())->toBe(['a-1'])
            // the database enforces it even when the Eloquent scope is bypassed
            ->and(TenancyProbe::query()->withoutGlobalScopes()->pluck('label')->all())->toBe(['a-1'])
            ->and(DB::table('tenancy_probes')->pluck('label')->all())->toBe(['a-1']);
    });
});

it('rejects writes into another workspace at the database level', function (): void {
    $this->ctx->run($this->a->id, function (): void {
        // savepoint, so the failed insert does not abort the test's own transaction
        DB::transaction(fn () => DB::table('tenancy_probes')->insert([
            'id' => (string) str()->uuid(), 'workspace_id' => $this->b->id, 'label' => 'smuggled',
        ]));
    });
})->throws(QueryException::class, 'row-level security');

it('cannot update or delete rows from another workspace', function (): void {
    $this->ctx->run($this->b->id, fn () => TenancyProbe::query()->create(['label' => 'b-1']));

    $this->ctx->run($this->a->id, function (): void {
        expect(DB::table('tenancy_probes')->update(['label' => 'hacked']))->toBe(0)
            ->and(DB::table('tenancy_probes')->delete())->toBe(0);
    });

    expect($this->ctx->run($this->b->id, fn () => TenancyProbe::query()->value('label')))->toBe('b-1');
});

it('fails closed in Eloquent when no workspace is active', function (): void {
    TenancyProbe::query()->get();
})->throws(MissingWorkspaceContext::class);

it('fails closed in the database when no workspace is active', function (): void {
    $this->ctx->run($this->a->id, fn () => TenancyProbe::query()->create(['label' => 'a-1']));

    expect(DB::table('tenancy_probes')->count())->toBe(0);
});

it('turns row-level security on for every table with a workspace_id column', function (): void {
    $unprotected = DB::connection('owner')->select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace and n.nspname = 'public'
        join pg_attribute a on a.attrelid = c.oid and a.attname = 'workspace_id' and not a.attisdropped
        where c.relkind = 'r'
          and c.relname not in ('workspaces_members')
          and not c.relrowsecurity
        SQL);

    expect(array_column($unprotected, 'relname'))->toBe([]);
});
