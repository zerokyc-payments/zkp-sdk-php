<?php

declare(strict_types=1);

namespace ZeroKYC\Invoice;

use ZeroKYC\Support\StatusMapper;

/**
 * Typed invoice representation (create + get responses share the shape).
 */
final class Invoice
{
    /**
     * @param array<string,mixed> $metadata merchant-defined passthrough
     * @param list<array<string,mixed>> $options payment options (asset, address, amount...)
     * @param list<array<string,mixed>> $observations on-chain payments seen so far
     * @param array<string,mixed> $raw full decoded response
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $orderId,
        public readonly ?string $description,
        public readonly string $amount,
        public readonly string $baseCurrency,
        public readonly string $paymentCurrency,
        public readonly string $rawStatus,
        public readonly InvoiceStatus $status,
        public readonly int $ttlMinutes,
        public readonly string $expiresAt,
        public readonly string $createdAt,
        public readonly string $checkoutUrl,
        public readonly array $metadata,
        public readonly array $options,
        public readonly array $observations,
        public readonly ?string $paidAmount,
        public readonly ?string $paidAsset,
        public readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $rawStatus = (string) ($data['status'] ?? '');
        return new self(
            id: (string) ($data['id'] ?? ''),
            orderId: isset($data['order_id']) ? (string) $data['order_id'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            amount: (string) ($data['amount'] ?? ''),
            baseCurrency: (string) ($data['base_currency'] ?? ''),
            paymentCurrency: (string) ($data['payment_currency'] ?? ''),
            rawStatus: $rawStatus,
            status: StatusMapper::normalize($rawStatus) ?? InvoiceStatus::FAILED,
            ttlMinutes: (int) ($data['ttl_minutes'] ?? 0),
            expiresAt: (string) ($data['expires_at'] ?? ''),
            createdAt: (string) ($data['created_at'] ?? ''),
            checkoutUrl: (string) ($data['checkout_url'] ?? ''),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            options: is_array($data['options'] ?? null) ? $data['options'] : [],
            observations: is_array($data['observations'] ?? null) ? $data['observations'] : [],
            paidAmount: isset($data['paid_amount']) ? (string) $data['paid_amount'] : null,
            paidAsset: isset($data['paid_asset']) ? (string) $data['paid_asset'] : null,
            raw: $data,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::PAID;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Find a payment option by asset id (e.g. USDT_TRON) or null.
     *
     * @return array<string,mixed>|null
     */
    public function option(string $asset): ?array
    {
        foreach ($this->options as $option) {
            if (($option['asset'] ?? null) === $asset) {
                return $option;
            }
        }
        return null;
    }
}
