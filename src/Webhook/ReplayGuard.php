<?php

declare(strict_types=1);

namespace ZeroKYC\Webhook;

use ZeroKYC\Idempotency\EventStoreInterface;
use ZeroKYC\Support\Money;

/**
 * Duplicate/replay protection + payment-matching helpers.
 *
 * A verified signature alone is NOT enough to mark an order paid: validate
 * the payload against local expectations too (invoice id, amount, asset).
 */
final class ReplayGuard
{
    public function __construct(
        private readonly EventStoreInterface $store,
    ) {
    }

    /**
     * True when the event id was already processed (skip, answer 200).
     * Records the id as seen so a concurrent retry is also caught.
     */
    public function isDuplicate(string $eventId): bool
    {
        if ($this->store->has($eventId)) {
            return true;
        }
        $this->store->markProcessed($eventId);
        return false;
    }

    public function markProcessed(string $eventId): void
    {
        $this->store->markProcessed($eventId);
    }

    /**
     * Payment-matching: does the confirmed webhook correspond to the local
     * order's invoice, with at least the expected amount in the expected asset?
     *
     * amount and asset expectations are optional but recommended; amounts are
     * decimal strings compared without floats.
     */
    public function matchesOrder(
        WebhookEvent $event,
        string $expectedInvoiceId,
        ?string $minAmount = null,
        ?string $asset = null,
    ): bool {
        if ($event->invoiceId !== $expectedInvoiceId) {
            return false;
        }
        if ($asset !== null && (string) ($event->data['asset'] ?? '') !== $asset) {
            return false;
        }
        if ($minAmount !== null) {
            $paid = (string) ($event->data['amount'] ?? $event->data['amount_paid'] ?? '');
            if ($paid === '' || !Money::greaterOrEqual($paid, $minAmount)) {
                return false;
            }
        }
        return true;
    }
}
