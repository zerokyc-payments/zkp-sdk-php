# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [SemVer](https://semver.org/).

## [0.1.0] - 2026-09-08

Initial beta.

### Added
- `ZeroKYC` facade: `createInvoice()`, `getInvoice()`, `cancelInvoice()`, `ping()`, `verifyWebhook()`.
- Typed invoice DTOs with normalized `InvoiceStatus` enum
  (PENDING / CONFIRMING / PAID / UNDERPAID / EXPIRED / CANCELLED / OVERPAID / FAILED).
- `WebhookVerifier`: `X-ZKP-Signature: t=...,v1=...` HMAC-SHA256 verification with
  strict header parsing, ±300 s replay window and constant-time comparison.
- `ReplayGuard` + `EventStoreInterface` for at-least-once delivery and
  payment matching (invoice / amount / asset) before crediting an order.
- `IdempotencyKey` helper (`zerokyc:{platform}:{entity}:{id}`, max 120 chars).
- Sandbox/production configuration with key/environment mismatch protection.
- Error mapping: `AuthenticationException` (401/403), `ValidationException`
  (400/422), `RateLimitException` (429 + Retry-After), `ApiException` (5xx/402/404),
  `NetworkException` (transport).
- Bounded exponential retry policy: GET always retried on transport/5xx;
  invoice creation retried only with an idempotency key.
- Zero-dependency cURL transport with injectable `HttpClientInterface`.
- PHPUnit suite incl. the documented HMAC test vector; fixtures for
  payment.detected / payment.confirmed / payment.underpaid / invoice.expired.
- Examples: create-invoice, get-invoice, verify-webhook, safe-webhook-handler.
