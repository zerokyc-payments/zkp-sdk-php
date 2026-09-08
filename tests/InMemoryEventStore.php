<?php

declare(strict_types=1);

namespace ZeroKYC\Tests;

use ZeroKYC\Idempotency\EventStoreInterface;

/**
 * Test-only event store. Production adapters persist to their own database.
 */
final class InMemoryEventStore implements EventStoreInterface
{
    /** @var array<string,true> */
    private array $seen = [];

    public function has(string $eventId): bool
    {
        return isset($this->seen[$eventId]);
    }

    public function markProcessed(string $eventId): void
    {
        $this->seen[$eventId] = true;
    }
}
