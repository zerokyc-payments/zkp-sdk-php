<?php

declare(strict_types=1);

/**
 * Create a sandbox invoice and print the hosted-checkout URL.
 *
 * Set your keys first (console -> API keys):
 *   export ZEROKYC_API_KEY=pk_test_...
 */

require __DIR__ . '/../vendor/autoload.php';

use ZeroKYC\Idempotency\IdempotencyKey;
use ZeroKYC\Invoice\CreateInvoiceRequest;
use ZeroKYC\ZeroKYC;

$zkp = ZeroKYC::fromArray([
    'api_key' => (string) getenv('ZEROKYC_API_KEY'),
    'environment' => 'sandbox',
]);

$response = $zkp->createInvoice(
    CreateInvoiceRequest::make('19.90', 'USD')
        ->withOrderId('INV-' . random_int(1000, 9999))
        ->withDescription('VPS plan: starter')
        ->withPaymentCurrency('any'),
    // stable key: a crash + retry returns the same invoice, never a duplicate
    IdempotencyKey::make('example', 'demo', date('Ymd')),
);

$invoice = $response->invoice;
echo "invoice:    {$invoice->id}\n";
echo "status:     {$invoice->status->value} (raw: {$invoice->rawStatus})\n";
echo "amount:     {$invoice->amount} {$invoice->baseCurrency}\n";
echo "replay:     " . ($response->idempotentReplay ? 'yes (same invoice returned)' : 'no') . "\n";
echo "checkout:   {$invoice->checkoutUrl}\n";

foreach ($invoice->options as $option) {
    echo "  - {$option['asset']} on {$option['network']}: {$option['amount_crypto']} -> {$option['payment_address']}\n";
}
