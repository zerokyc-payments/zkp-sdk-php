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
        return new self(
            id: (string) ($payload['id'] ?? ''),
            type: (string) ($payload['type'] ?? ''),
            invoiceId: isset($payload['invoice_id']) ? (string) $payload['invoice_id'] : null,
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
            raw: $payload,
        );
    }

    public function isPaymentConfirmed(): bool
    {
        return $this->type === 'payment.confirmed';
    }
}
