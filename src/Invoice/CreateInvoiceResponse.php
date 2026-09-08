<?php

declare(strict_types=1);

namespace ZeroKYC\Invoice;

/**
 * Result of invoice creation.
 *
 * idempotentReplay is true when the API returned a previously created
 * invoice for the same Idempotency-Key (safe timeout/retry path).
 */
final class CreateInvoiceResponse
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly bool $idempotentReplay,
    ) {
    }
}
