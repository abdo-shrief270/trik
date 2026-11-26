<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveWorkspace;
use App\Support\Http\Problem;
use App\Support\Tenancy\MissingWorkspaceContext;
use App\Support\Tenancy\RowLevelSecurity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Workspaces\Application\CreateWorkspace;
use Modules\Workspaces\Application\OrganizationContext;
use Modules\Workspaces\Domain\OrganizationRole;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\OrganizationMember;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

function bearer(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
}

it('creates the organization and first workspace at sign-up, invisible to a one-brand seller', function (): void {
    $response = $this->postJson('/v1/auth/register', [
        'name' => 'Mona', 'email' => 'mona@org.test', 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        'workspace_name' => 'Mona Store', 'device_name' => 'phone',
    ])->assertCreated();

    $orgId = $response->json('workspace.organization_id');
    expect($orgId)->toBeString();

    $this->withToken($response->json('token'))->getJson('/v1/auth/me')
        ->assertOk()
        ->assertJsonCount(1, 'organizations')
        ->assertJsonPath('organizations.0.id', $orgId)
        ->assertJsonPath('organizations.0.name', 'Mona Store')
        ->assertJsonPath('organizations.0.role', 'owner')
        ->assertJsonPath('organizations.0.workspaces.0.name', 'Mona Store')
        ->assertJsonCount(1, 'workspaces');
});

it('shows an agent only the workspaces they belong to (Slack-style switcher)', function (): void {
    $owner = User::factory()->create();
    $a = app(CreateWorkspace::class)->forUser('Brand A', $owner->id);
    $b = app(CreateWorkspace::class)->forUser('Brand B', $owner->id);
    app(CreateWorkspace::class)->forUser('Brand C', $owner->id);
    expect($b->organizationId)->toBe($a->organizationId); // same organization

    $agent = User::factory()->create();
    foreach ([$a, $b] as $ws) {
        WorkspaceMember::query()->create(['workspace_id' => $ws->id, 'user_id' => $agent->id, 'role' => WorkspaceRole::Agent]);
    }
    OrganizationMember::query()->create(['organization_id' => $a->organizationId, 'user_id' => $agent->id, 'role' => OrganizationRole::Member]);

    $orgs = $this->withHeaders(bearer($agent))->getJson('/v1/organizations')->assertOk()->json('data');
    expect($orgs)->toHaveCount(1)
        ->and($orgs[0]['role'])->toBe('member')
        ->and(array_column($orgs[0]['workspaces'], 'name'))->toBe(['Brand A', 'Brand B']);

    // switching = the same token, another X-Workspace-Id
    foreach ([$a, $b] as $ws) {
        $this->withHeaders([...bearer($agent), ResolveWorkspace::HEADER => $ws->id])->getJson('/v1/workspace')->assertOk()->assertJsonPath('data.role', 'agent');
    }
});

it('puts new workspaces in the right organization', function (): void {
    $owner = User::factory()->create();
    $first = app(CreateWorkspace::class)->forUser('First', $owner->id);

    $this->withHeaders(bearer($owner))->postJson('/v1/workspaces', ['name' => 'Second'])
        ->assertCreated()->assertJsonPath('data.organization_id', $first->organizationId);

    // an agent who manages no organization gets a new one of their own
    $agent = User::factory()->create();
    WorkspaceMember::query()->create(['workspace_id' => $first->id, 'user_id' => $agent->id, 'role' => WorkspaceRole::Agent]);
    OrganizationMember::query()->create(['organization_id' => $first->organizationId, 'user_id' => $agent->id, 'role' => OrganizationRole::Member]);
    $own = $this->withHeaders(bearer($agent))->postJson('/v1/workspaces', ['name' => 'My side shop'])->assertCreated()->json('data.organization_id');
    expect($own)->not->toBe($first->organizationId);

    // …and cannot add one to the organization they are only a member of
    $this->withHeaders(bearer($agent))->postJson('/v1/workspaces', ['name' => 'Sneaky', 'organization_id' => $first->organizationId])
        ->assertForbidden()->assertJsonPath('code', 'organization-forbidden');

    // someone managing two organizations has to choose
    OrganizationMember::query()->where('organization_id', $first->organizationId)->where('user_id', $agent->id)->update(['role' => 'admin']);
    $this->withHeaders(bearer($agent))->postJson('/v1/workspaces', ['name' => 'Which?'])
        ->assertUnprocessable()->assertJsonValidationErrors('organization_id');
});

describe('read-only organization context', function (): void {
    beforeEach(function (): void {
        Schema::connection('owner')->dropIfExists('org_probe');
        Schema::connection('owner')->create('org_probe', function ($t): void {
            $t->id();
            $t->foreignUuid('workspace_id');
            $t->string('label');
        });
        RowLevelSecurity::enable('org_probe', connection: 'owner');

        $this->owner = User::factory()->create();
        $this->a = app(CreateWorkspace::class)->forUser('A', $this->owner->id);
        $this->b = app(CreateWorkspace::class)->forUser('B', $this->owner->id);
        $this->hidden = app(CreateWorkspace::class)->forUser('Hidden', User::factory()->create()->id); // other organization
        $ctx = app(TenantContext::class);
        foreach (['a' => $this->a, 'b' => $this->b, 'hidden' => $this->hidden] as $label => $ws) {
            $ctx->run($ws->id, fn () => DB::table('org_probe')->insert(['workspace_id' => $ws->id, 'label' => $label]));
        }
    });

    afterEach(function (): void {
        app(TenantContext::class)->forget();
        DB::rollBack();
        Schema::connection('owner')->dropIfExists('org_probe');
        DB::beginTransaction();
    });

    it('reads the viewer\'s workspaces of that organization, nothing else', function (): void {
        $labels = app(OrganizationContext::class)->run($this->a->organizationId, $this->owner->id,
            fn () => DB::table('org_probe')->orderBy('label')->pluck('label')->all());

        expect($labels)->toBe(['a', 'b']);
    });

    it('never writes, not even into the viewer\'s own workspaces', function (): void {
        app(OrganizationContext::class)->run($this->a->organizationId, $this->owner->id, function (): void {
            expect(fn () => DB::transaction(fn () => DB::table('org_probe')->insert(['workspace_id' => $this->a->id, 'label' => 'x'])))
                ->toThrow(QueryException::class, 'row-level security')
                ->and(DB::table('org_probe')->update(['label' => 'changed']))->toBe(0)
                ->and(DB::table('org_probe')->delete())->toBe(0)
                ->and(fn () => app(TenantContext::class)->require())->toThrow(MissingWorkspaceContext::class);
        });
    });

    it('refuses an organization the viewer has no workspace in', function (): void {
        app(OrganizationContext::class)->run($this->hidden->organizationId, $this->owner->id, fn () => null);
    })->throws(Problem::class);
});

it('has read and write policies on every tenant table', function (): void {
    $rows = DB::connection('owner')->select(<<<'SQL'
        select c.relname, coalesce(string_agg(p.polcmd::text, '' order by p.polcmd::text), '') as cmds
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace and n.nspname = 'public'
        join pg_attribute a on a.attrelid = c.oid and a.attname = 'workspace_id' and not a.attisdropped
        left join pg_policy p on p.polrelid = c.oid
        where c.relkind in ('r', 'p') and c.relname not in ('workspaces_members')
        group by c.relname
        SQL);

    // a = insert, d = delete, r = select, w = update
    $wrong = array_values(array_map(fn ($r) => "{$r->relname}: {$r->cmds}", array_filter($rows, fn ($r) => $r->cmds !== 'adrw')));

    expect($rows)->not->toBeEmpty()->and($wrong)->toBe([]);
});
