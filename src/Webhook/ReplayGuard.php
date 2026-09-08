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
     * Pure check: it never mutates the store - an event is marked processed
     * ONLY via markProcessed() after the local order update succeeded.
     * Marking here would turn any crash between this check and the order
     * update into a permanently unpaid order (the retry would be skipped).
     *
     * Concurrent deliveries of the same event can both pass this check;
     * adapters that need strict single-processing should implement an
     * atomic claim (INSERT ... ON CONFLICT / unique constraint) in their
     * EventStore and use it as the source of truth.
     */
    public function isDuplicate(string $eventId): bool
    {
        return $this->store->has($eventId);
    }

    public function markProcessed(string $eventId): void
    {
        $this->store->markProcessed($eventId);
    }

    /**
     * Payment-matching: does the confirmed webhook correspond to the local
     * order's invoice, with at least the expected amount in the expected asset?
     *
     * Asset expectations accept both spellings: "USDT" (ticker, as production
     * events carry it) and "USDT_TRON" (asset id - the network part must then
     * also match). minAmount is compared against the crypto amount actually
     * received; for non-stable assets pass the amount_crypto you invoiced.
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
        if ($asset !== null && !$this->assetMatches($event, $asset)) {
            return false;
        }
        if ($minAmount !== null) {
            $paid = $event->paidAmount();
            if ($paid === null || !Money::greaterOrEqual($paid, $minAmount)) {
                return false;
            }
        }
        return true;
    }

    private function assetMatches(WebhookEvent $event, string $expected): bool
    {
        $paidRaw = strtolower($event->paidAsset() ?? '');
        if ($paidRaw === '') {
            return false;
        }
        // expected and paid may each be a bare ticker ("usdt") or an asset id
        // ("usdt_tron"); the network may also ride in data.option.network
        [$expTicker, $expNetwork] = array_pad(explode('_', strtolower($expected), 2), 2, null);
        [$paidTicker, $paidRest] = array_pad(explode('_', $paidRaw, 2), 2, null);
        if ($paidTicker !== $expTicker) {
            return false;
        }
        $paidNetwork = strtolower($event->paidNetwork() ?? '');
        if ($paidNetwork === '') {
            $paidNetwork = $paidRest ?? '';
        }
        if ($expNetwork !== null && $paidNetwork !== '' && $paidNetwork !== $expNetwork) {
            return false;
        }
        return true;
    }
}
