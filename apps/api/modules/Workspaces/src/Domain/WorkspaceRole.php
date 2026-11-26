<?php

declare(strict_types=1);

namespace Modules\Workspaces\Domain;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Agent = 'agent';
}
