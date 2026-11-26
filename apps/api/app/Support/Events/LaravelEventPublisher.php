<?php

declare(strict_types=1);

namespace App\Support\Events;

use Illuminate\Contracts\Events\Dispatcher;

final class LaravelEventPublisher implements EventPublisher
{
    public function __construct(private readonly Dispatcher $events) {}

    public function publish(object $event): void
    {
        $this->events->dispatch($event);
    }
}
