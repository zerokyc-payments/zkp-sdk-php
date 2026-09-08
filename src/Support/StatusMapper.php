<?php

declare(strict_types=1);

namespace ZeroKYC\Support;

use ZeroKYC\Invoice\InvoiceStatus;

/**
 * Maps raw API statuses to the normalized SDK enum.
 *
 * Raw (API):      created | pending | detecting | confirmed | underpaid | expired | canceled
 * Normalized:     PENDING | CONFIRMING | PAID | UNDERPAID | EXPIRED | CANCELLED | FAILED
 *
 * Platform plugins map the normalized value to their own order states; they
 * never see raw strings.
 */
final class StatusMapper
{
    private const MAP = [
        'created' => InvoiceStatus::PENDING,
        'pending' => InvoiceStatus::PENDING,
        'detecting' => InvoiceStatus::CONFIRMING,
        'confirmed' => InvoiceStatus::PAID,
        'underpaid' => InvoiceStatus::UNDERPAID,
        'expired' => InvoiceStatus::EXPIRED,
        'canceled' => InvoiceStatus::CANCELLED,
        // overpayment is credited and confirmed server-side; kept distinct
        // for forward compatibility and local bookkeeping
        'overpaid' => InvoiceStatus::OVERPAID,
    ];

    /** @var list<string> raw statuses after which the invoice never changes */
    private const RAW_TERMINAL = ['confirmed', 'underpaid', 'expired', 'canceled'];

    public static function normalize(string $raw): ?InvoiceStatus
    {
        return self::MAP[$raw] ?? null;
    }

    public static function normalizeOrFail(string $raw): InvoiceStatus
    {
        $status = self::normalize($raw);
        if ($status === null) {
            throw new \InvalidArgumentException("unknown invoice status '{$raw}'");
        }
        return $status;
    }

    public static function isTerminal(string $raw): bool
    {
        return in_array($raw, self::RAW_TERMINAL, true);
    }

    public static function isPaid(string $raw): bool
    {
        return $raw === 'confirmed';
    }
}
