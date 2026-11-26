<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/** Thrown when tenant data is touched while no workspace is active. Fails closed. */
final class MissingWorkspaceContext extends RuntimeException
{
    public static function make(): self
    {
        return new self('No workspace is active. Tenant data can only be read or written inside a workspace context.');
    }
}
