<?php

declare(strict_types=1);

/**
 * Reconciliation / recovery: poll an invoice status server-to-server.
 * Use this when a webhook was missed, from a cron, or for support tooling.
 *
 *   export ZEROKYC_API_KEY=pk_test_...
 */

require __DIR__ . '/../vendor/autoload.php';

use ZeroKYC\Invoice\InvoiceStatus;
use ZeroKYC\ZeroKYC;

$invoiceId = $argv[1] ?? null;
if ($invoiceId === null) {
    fwrite(STDERR, "usage: php get-invoice.php <invoice-id>\n");
    exit(1);
}

$zkp = ZeroKYC::fromArray([
    'api_key' => (string) getenv('ZEROKYC_API_KEY'),
    'environment' => 'sandbox',
]);

$invoice = $zkp->getInvoice($invoiceId);

echo "invoice: {$invoice->id}\n";
echo "status:  {$invoice->status->value} (raw: {$invoice->rawStatus})\n";
echo "paid:    " . ($invoice->paidAmount !== null ? "{$invoice->paidAmount} {$invoice->paidAsset}" : '-') . "\n";
echo "expires: {$invoice->expiresAt}\n";

// Platform mapping example
match ($invoice->status) {
    InvoiceStatus::PAID => print("-> mark local order paid\n"),
    InvoiceStatus::UNDERPAID => print("-> flag order: contact customer\n"),
    InvoiceStatus::EXPIRED, InvoiceStatus::CANCELLED => print("-> close/void local order\n"),
    InvoiceStatus::PENDING, InvoiceStatus::CONFIRMING => print("-> keep waiting\n"),
    default => print("-> alert: unexpected state\n"),
};
