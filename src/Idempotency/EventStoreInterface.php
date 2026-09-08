<?php

declare(strict_types=1);

namespace ZeroKYC\Idempotency;

/**
 * Storage-agnostic at-least-once processing guard. Webhook delivery is
 * at-least-once: mark event ids processed and skip duplicates.
 *
 * Platform adapters implement this with their own database, e.g. WHMCS
 * tblgateway_log, WooCommerce custom table, Blesta key/value store.
 * The SDK ships an in-memory implementation for tests only.
 */
interface EventStoreInterface
{
    public function has(string $eventId): bool;

    public function markProcessed(string $eventId): void;
}
