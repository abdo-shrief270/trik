<?php

declare(strict_types=1);

use Modules\Identity\Infrastructure\Models\User;

/** Creates a user and returns its id. */
function userId(): string
{
    return User::factory()->create()->id;
}
