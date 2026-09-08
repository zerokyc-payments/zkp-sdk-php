<?php

declare(strict_types=1);

namespace ZeroKYC\Invoice;

/**
 * Normalized invoice/payment status. Platform plugins map these to their own
 * billing/order states; raw API strings never leak past this enum.
 */
enum InvoiceStatus: string
{
    /** Waiting for payment (raw created/pending). */
    case PENDING = 'PENDING';

    /** Payment seen on-chain, waiting for confirmations (raw detecting). */
    case CONFIRMING = 'CONFIRMING';

    /** Confirmed on-chain; the invoice is credited and paid. */
    case PAID = 'PAID';

    /** Invoice lifetime ended without (sufficient) payment. */
    case EXPIRED = 'EXPIRED';

    /** Payment arrived below the accepted window. */
    case UNDERPAID = 'UNDERPAID';

    /** Reserved: overpayment (currently credited as PAID server-side). */
    case OVERPAID = 'OVERPAID';

    /** Canceled by the merchant before expiry. */
    case CANCELLED = 'CANCELLED';

    /** Unexpected/unknown state; treat as unrecoverable and alert. */
    case FAILED = 'FAILED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::PENDING, self::CONFIRMING => false,
            default => true,
        };
    }
}
