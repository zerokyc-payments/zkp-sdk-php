<?php

declare(strict_types=1);

/**
 * The complete, production-safe webhook flow. Copy this shape into your
 * platform adapter (WHMCS callback, WooCommerce hook, Blesta/Paymenter
 * controller) and implement the four TODOs with your platform's storage.
 *
 * Flow:
 *   raw body -> signature verification -> duplicate check -> local order
 *   lookup -> invoice/amount/asset validation -> mark paid -> mark event
 *   processed -> HTTP 200.
 *
 * Never trust a browser success/return URL as proof of payment.
 */

require __DIR__ . '/../vendor/autoload.php';

use ZeroKYC\Exception\WebhookVerificationException;
use ZeroKYC\Idempotency\EventStoreInterface;
use ZeroKYC\Webhook\ReplayGuard;
use ZeroKYC\Webhook\WebhookVerifier;
use ZeroKYC\Webhook\WebhookEvent;
use ZeroKYC\ZeroKYC;

$rawBody = file_get_contents('php://input') ?: '';
$signatureHeader = $_SERVER['HTTP_X_ZKP_SIGNATURE'] ?? '';

$zkp = ZeroKYC::fromArray([
    'api_key' => (string) getenv('ZEROKYC_API_KEY'),
    'environment' => 'production',
    'webhook_secret' => (string) getenv('ZEROKYC_WEBHOOK_SECRET'),
]);

// 1. Signature + timestamp first, always before touching the payload.
try {
    $event = $zkp->verifyWebhook($rawBody, $signatureHeader);
} catch (WebhookVerificationException $e) {
    http_response_code(400); // ZeroKYC retries on non-2xx; garbage must not be retried forever...
    exit;                    // ...but genuine failures get re-delivered by the platform
}

/** @var EventStoreInterface $store TODO implement with YOUR database table */
$store = new class implements EventStoreInterface {
    private array $seen = [];
    public function has(string $eventId): bool
    {
        return in_array($eventId, $this->seen, true); // TODO: SELECT from your table
    }
    public function markProcessed(string $eventId): void
    {
        $this->seen[] = $eventId; // TODO: INSERT into your table
    }
};
$guard = new ReplayGuard($store);

// 2. At-least-once delivery: skip duplicates, answer 200 so retries stop.
if ($guard->isDuplicate($event->id)) {
    http_response_code(200);
    exit;
}

// 3. Only confirmation events mark orders paid.
if (!$event->isPaymentConfirmed()) {
    http_response_code(200);
    exit;
}

// 4. Find the local order. TODO: look up by order_id/invoice id in YOUR tables
$localOrder = [
    'order_id' => $event->data['order_id'] ?? null,
    'expected_invoice_id' => $event->invoiceId, // stored when the invoice was created
    'expected_amount' => '19.90',
    'expected_asset' => 'USDT_TRON',
];

// 5. Signature validity alone is NOT enough: match invoice, amount and asset.
$matches = $guard->matchesOrder(
    $event,
    $localOrder['expected_invoice_id'],
    $localOrder['expected_amount'],
    $localOrder['expected_asset'],
);

if (!$matches) {
    // Log loudly - this is either a bookkeeping bug or something odd.
    error_log("zerokyc: event {$event->id} did not match local order {$localOrder['order_id']}");
    http_response_code(200); // do not brick the delivery queue; investigate manually
    exit;
}

// 6. Belt and suspenders for high-value orders: confirm server-to-server.
// $invoice = $zkp->getInvoice($event->invoiceId);
// if (! $invoice->isPaid()) { ... }

// 7. TODO: mark the order paid in YOUR platform (activate service, send email...)

// 8. Event is processed; mark AFTER the order update succeeds.
$guard->markProcessed($event->id);

http_response_code(200);
