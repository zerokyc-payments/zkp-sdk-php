# zkp-sdk-php

Official PHP SDK for the [ZeroKYC Pay](https://zerokyc-payments.com) crypto payment gateway.
Framework-agnostic, no third-party runtime dependencies (requires the
PHP JSON and cURL extensions), PHP 8.1+.

WHMCS, WooCommerce, Blesta, Paymenter, FOSSBilling and OpenCart adapters for
ZeroKYC are built on this package - if you integrate anything else, build it
here too: API client, webhook verification, idempotency and status mapping
are done for you.

## Install

```bash
composer require zerokyc/zkp-sdk-php
```

(If the package is not on Packagist yet, use the VCS repository:

```bash
composer config repositories.zkp vcs https://github.com/zerokyc-payments/zkp-sdk-php
composer require zerokyc/zkp-sdk-php:@dev
```
)

## Quickstart (sandbox invoice in 5 minutes)

1. Create an account at [console.zerokyc-payments.com](https://console.zerokyc-payments.com)
   and copy a **sandbox** API key (`pk_test_...`) from *API keys*.
2. Create an invoice and send the buyer to the hosted checkout:

```php
use ZeroKYC\Idempotency\IdempotencyKey;
use ZeroKYC\Invoice\CreateInvoiceRequest;
use ZeroKYC\ZeroKYC;

$zkp = new ZeroKYC([
    'api_key'     => getenv('ZEROKYC_API_KEY'), // pk_test_... (sandbox) / pk_live_... (production)
    'environment' => 'sandbox',
]);

$response = $zkp->createInvoice(
    CreateInvoiceRequest::make('19.90', 'USD')
        ->withOrderId('INV-1042')
        ->withDescription('VPS plan: starter'),
    IdempotencyKey::make('myshop', 'invoice', '1042'), // stable per local order
);

header('Location: ' . $response->invoice->checkoutUrl);
```

3. Get paid: ZeroKYC detects the on-chain payment and POSTs a signed webhook
   to your endpoint. **A verified webhook (or a server-side `getInvoice()`)
   is the only proof of payment - never a browser success URL.**

```php
use ZeroKYC\Exception\WebhookVerificationException;

try {
    $event = $zkp->verifyWebhook(
        file_get_contents('php://input'),          // exact raw body
        $_SERVER['HTTP_X_ZKP_SIGNATURE'] ?? '',
    );
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event->isPaymentConfirmed()) {
    // match invoice id + amount + asset against the local order, then activate
}
```

Full production flow with duplicate protection and payment matching:
[examples/safe-webhook-handler.php](examples/safe-webhook-handler.php).

## Configuration

| Key              | Required | Notes                                                        |
|------------------|----------|--------------------------------------------------------------|
| `api_key`        | yes      | `pk_test_...` sandbox / `pk_live_...` production              |
| `environment`    | no       | `sandbox` \| `production`; inferred from the key; mismatch throws |
| `webhook_secret` | for webhooks | `whsec_...` from console → Webhooks                      |
| `timeout`        | no       | per-request HTTP timeout, seconds (default 15)               |
| `max_retries`    | no       | bounded retries, 0-5 (default 2)                             |
| `base_url`       | tests only | overrides the central API base URL                          |

The API base URL is defined centrally by the SDK. Never hard-code URLs in
platform adapters.

## Status normalization

Raw API statuses never leak into your billing logic:

| API (raw)                          | SDK enum                    |
|------------------------------------|-----------------------------|
| `created`, `pending`               | `InvoiceStatus::PENDING`    |
| `detecting` (seen, awaiting confs) | `InvoiceStatus::CONFIRMING` |
| `confirmed`                        | `InvoiceStatus::PAID`       |
| `underpaid`                        | `InvoiceStatus::UNDERPAID`  |
| `expired`                          | `InvoiceStatus::EXPIRED`    |
| `canceled`                         | `InvoiceStatus::CANCELLED`  |
| unknown                            | `InvoiceStatus::FAILED` (alert) |

`$invoice->isPaid()` / `$invoice->isTerminal()` answer the common questions.

## Idempotency

Always pass a stable idempotency key when creating invoices from an order:

```php
IdempotencyKey::make('whmcs', 'invoice', (string) $invoiceId);
// zerokyc:whmcs:invoice:1042  (max 120 chars)
```

A timeout + retry then returns **the same** invoice
(`CreateInvoiceResponse::idempotentReplay === true`) instead of a duplicate.

## Error handling & retries

| Exception                    | HTTP           | Retried automatically? |
|------------------------------|----------------|------------------------|
| `AuthenticationException`    | 401 / 403      | never                  |
| `ValidationException`        | 400 / 422      | never                  |
| `RateLimitException`         | 429            | yes (honors Retry-After up to 5s, max twice) |
| `ApiException`               | 5xx, 402, 404… | only GET / idempotent POST |
| `NetworkException`           | timeout/DNS    | only GET / idempotent POST |
| `WebhookVerificationException` | n/a          | n/a (reason code on `$e->reason`) |

Backoff is bounded exponential (300ms → 600ms → 1200ms, capped by `max_retries`).

## Webhook security checklist

- verify against the **exact raw body** (never `json_encode(json_decode(...))`);
- `t=`/`v1=` header format, lowercase hex, constant-time compare (built in);
- default ±300 s timestamp window (`new WebhookVerifier($secret, 600)` to widen);
- treat delivery as **at-least-once**: deduplicate with `ReplayGuard` +
  `EventStoreInterface` backed by your database;
- match invoice id / amount / asset against the local order before activating
  (`ReplayGuard::matchesOrder()`);
- never log API keys or webhook secrets (the SDK never puts them in exceptions).

Self-test against the documented vector:

```php
$verifier = new WebhookVerifier('whsec_zkp_test_vector_2026');
$event = $verifier->verify(
    '{"id":"evt_test_001","type":"payment.confirmed","invoice_id":"inv_test_001"}',
    't=1788788073,v1=ade537fa13aec79a6d1648bd7f197872066c161676c389243ab5c6b13fea7f52',
    now: 1788788073,
);
```

## Assets

USDT (TRC-20), USDC/USDT (Polygon, Arbitrum), BTC, XMR, TON, USDT-TON. Pass an
asset id as `payment_currency` to pin one (`USDT_TRON`), or `any` (default) to
let the buyer choose at checkout.

## Tests

```bash
composer install
composer test
```

## License

MIT — see [LICENSE](LICENSE).
