<?php

declare(strict_types=1);

namespace App\Support\Events;

/**
 * How modules announce that something happened. Today it is a plain Laravel event;
 * after the pilot the outbox (#29) implements this and publishes to Redpanda.
 */
interface EventPublisher
{
    public function publish(object $event): void;
}
