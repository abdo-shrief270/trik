<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Holds the active workspace for this request or job.
 *
 * Two layers use it:
 *  1. Eloquent: the WorkspaceScope global scope filters by it (and throws when it is empty).
 *  2. PostgreSQL: it is mirrored into the session settings the row-level security policies read:
 *     app.workspace_id (writes) and app.workspace_ids (reads). With nothing set, policies match no rows.
 *
 * Two modes:
 *  - one workspace (set / run): read and write that workspace;
 *  - read-only across several workspaces (runReadOnly), for organization reports (ADR 0012):
 *    reads cover the list, writes are refused by Eloquent (require() throws) and by RLS.
 */
final class TenantContext
{
    public const SETTING = 'app.workspace_id';

    public const READ_SETTING = 'app.workspace_ids';

    private ?string $workspaceId = null;

    /** @var list<string> */
    private array $readable = [];

    public function __construct(private readonly DatabaseManager $db) {}

    public function id(): ?string
    {
        return $this->workspaceId;
    }

    public function has(): bool
    {
        return $this->workspaceId !== null;
    }

    /** True when a workspace or a read-only set of workspaces is active. */
    public function isActive(): bool
    {
        return $this->readable !== [];
    }

    /** @return list<string> workspaces that can be read right now */
    public function readable(): array
    {
        return $this->readable;
    }

    public function isReadOnly(): bool
    {
        return $this->workspaceId === null && $this->readable !== [];
    }

    public function require(): string
    {
        return $this->workspaceId ?? throw MissingWorkspaceContext::make();
    }

    public function set(string $workspaceId): void
    {
        if (! Str::isUuid($workspaceId)) {
            throw new InvalidArgumentException('Workspace id must be a UUID.');
        }

        $this->workspaceId = $workspaceId;
        $this->readable = [$workspaceId];
        $this->sync();
    }

    public function forget(): void
    {
        $this->workspaceId = null;
        $this->readable = [];
        $this->sync();
    }

    /**
     * Runs the callback inside a workspace and restores whatever was active before.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(string $workspaceId, callable $callback): mixed
    {
        $previous = [$this->workspaceId, $this->readable];
        $this->set($workspaceId);

        try {
            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    /**
     * Read-only across several workspaces (the caller decides which: only ones the viewer belongs to).
     *
     * @template T
     *
     * @param  list<string>  $workspaceIds
     * @param  callable(): T  $callback
     * @return T
     */
    public function runReadOnly(array $workspaceIds, callable $callback): mixed
    {
        foreach ($workspaceIds as $id) {
            if (! Str::isUuid($id)) {
                throw new InvalidArgumentException('Workspace ids must be UUIDs.');
            }
        }

        if ($workspaceIds === []) {
            throw MissingWorkspaceContext::make();
        }

        $previous = [$this->workspaceId, $this->readable];
        $this->workspaceId = null;
        $this->readable = array_values(array_unique($workspaceIds));
        $this->sync();

        try {
            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    /** @param array{0: string|null, 1: list<string>} $state */
    private function restore(array $state): void
    {
        [$this->workspaceId, $this->readable] = $state;
        $this->sync();
    }

    /** Pushes the current value to the database session (only if a connection is open). */
    private function sync(): void
    {
        $name = $this->db->getDefaultConnection();

        if (! array_key_exists($name, $this->db->getConnections())) {
            return; // nothing open yet; RlsConnectionListener applies it on connect
        }

        $this->apply($this->db->connection($name));
    }

    public function apply(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('select set_config(?, ?, false), set_config(?, ?, false)', [
            self::SETTING, $this->workspaceId ?? '',
            self::READ_SETTING, implode(',', $this->readable),
        ]);
    }
}
