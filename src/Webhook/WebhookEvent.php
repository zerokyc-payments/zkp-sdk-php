<?php

declare(strict_types=1);

namespace ZeroKYC\Webhook;

/**
 * Verified webhook event (the decoded payload of a signature-checked body).
 *
 * Typical payloads: payment.detected, payment.confirmed, payment.underpaid,
 * invoice.created, invoice.expired, invoice.canceled, sweep.failed,
 * platform_invoice, suspension, webhook.test.
 */
final class WebhookEvent
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $invoiceId,
        public readonly array $data,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        // production payloads keep the invoice id inside `data`; the docs
        // test vector carries it top-level - support both
        $invoiceId = $payload['invoice_id'] ?? $data['invoice_id'] ?? null;

        return new self(
            id: (string) ($payload['id'] ?? ''),
            type: (string) ($payload['type'] ?? ''),
            invoiceId: $invoiceId !== null ? (string) $invoiceId : null,
            data: $data,
            raw: $payload,
        );
    }

    public function isPaymentConfirmed(): bool
    {
        return $this->type === 'payment.confirmed';
    }

    /**
     * Crypto amount actually received (payment.* events carry it under
     * data.option.paid_amount; docs-vector shape uses data.amount).
     * For non-stable assets this is in crypto units - compare against the
     * amount_crypto you invoiced, not the base-currency total.
     */
    public function paidAmount(): ?string
    {
        $option = $this->data['option'] ?? null;
        $paid = is_array($option)
            ? ($option['paid_amount'] ?? null)
            : null;
        $paid ??= $this->data['amount_paid'] ?? $this->data['amount'] ?? null;
        return $paid !== null && $paid !== '' ? (string) $paid : null;
    }

    /**
     * Asset the payment arrived in: production events use the ticker under
     * data.option.asset (e.g. "USDT" with network "tron"); the docs-vector
     * shape uses the asset id data.asset ("USDT_TRON").
     */
    public function paidAsset(): ?string
    {
        $option = $this->data['option'] ?? null;
        $asset = is_array($option) ? ($option['asset'] ?? null) : null;
        $asset ??= $this->data['asset'] ?? null;
        return $asset !== null && $asset !== '' ? (string) $asset : null;
    }

    /** Network of the paying option ("tron", "polygon", ...), when present. */
    public function paidNetwork(): ?string
    {
        $option = $this->data['option'] ?? null;
        $network = is_array($option) ? ($option['network'] ?? null) : null;
        return $network !== null && $network !== '' ? (string) $network : null;
    }
}
