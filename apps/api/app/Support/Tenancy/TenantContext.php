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
 *  2. PostgreSQL: it is mirrored into the session setting app.workspace_id, which the
 *     row-level security policies read. With no workspace set, policies match no rows.
 */
final class TenantContext
{
    public const SETTING = 'app.workspace_id';

    private ?string $workspaceId = null;

    public function __construct(private readonly DatabaseManager $db) {}

    public function id(): ?string
    {
        return $this->workspaceId;
    }

    public function has(): bool
    {
        return $this->workspaceId !== null;
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
        $this->sync();
    }

    public function forget(): void
    {
        $this->workspaceId = null;
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
        $previous = $this->workspaceId;
        $this->set($workspaceId);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->forget() : $this->set($previous);
        }
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

        $connection->statement('select set_config(?, ?, false)', [self::SETTING, $this->workspaceId ?? '']);
    }
}
