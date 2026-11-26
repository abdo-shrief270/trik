<?php

declare(strict_types=1);

namespace App\Support\Authorization;

/** What one member may do in one workspace. */
final readonly class MemberAccess
{
    /** @param list<string> $grants */
    public function __construct(public string $role, public array $grants, public ?string $customRoleId = null) {}
}
