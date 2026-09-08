<?php

declare(strict_types=1);

namespace ZeroKYC\Invoice;

use ZeroKYC\Support\Money;

/**
 * Fluent, locally-validated invoice creation request.
 *
 *     $request = CreateInvoiceRequest::make('19.90', 'USD')
 *         ->withOrderId('INV-1042')
 *         ->withPaymentCurrency('USDT_TRON')
 *         ->withWebhookUrl('https://shop.example/hooks/zerokyc');
 */
final class CreateInvoiceRequest
{
    public const CURRENCIES = ['USD', 'EUR', 'RUB'];

    /**
     * @param array<string,mixed> $metadata
     */
    private function __construct(
        private string $amount,
        private string $baseCurrency = 'USD',
        private string $paymentCurrency = 'any',
        private ?string $orderId = null,
        private ?string $description = null,
        private ?int $ttlMinutes = null,
        private ?string $webhookUrl = null,
        private ?string $successUrl = null,
        private array $metadata = [],
    ) {
    }

    public static function make(string $amount, string $baseCurrency = 'USD'): self
    {
        return new self(amount: $amount, baseCurrency: $baseCurrency);
    }

    public function withOrderId(?string $orderId): self
    {
        $this->orderId = $orderId;
        return $this;
    }

    public function withPaymentCurrency(string $paymentCurrency): self
    {
        $this->paymentCurrency = $paymentCurrency;
        return $this;
    }

    public function withDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function withTtlMinutes(int $minutes): self
    {
        $this->ttlMinutes = $minutes;
        return $this;
    }

    public function withWebhookUrl(?string $url): self
    {
        $this->webhookUrl = $url;
        return $this;
    }

    public function withSuccessUrl(?string $url): self
    {
        $this->successUrl = $url;
        return $this;
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * @return array<string,mixed> JSON body for POST /v1/invoices
     */
    public function toArray(): array
    {
        $this->validate();

        $payload = [
            'amount' => $this->amount,
            'base_currency' => $this->baseCurrency,
            'payment_currency' => $this->paymentCurrency,
        ];
        if ($this->orderId !== null) {
            $payload['order_id'] = $this->orderId;
        }
        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }
        if ($this->ttlMinutes !== null) {
            $payload['ttl_minutes'] = $this->ttlMinutes;
        }
        if ($this->webhookUrl !== null) {
            $payload['webhook_url'] = $this->webhookUrl;
        }
        if ($this->successUrl !== null) {
            $payload['success_url'] = $this->successUrl;
        }
        if ($this->metadata !== []) {
            $payload['metadata'] = $this->metadata;
        }
        return $payload;
    }

    /**
     * Local validation mirrors the API rules so obvious mistakes never leave
     * the process (never rely on it as the only line of defense).
     */
    public function validate(): void
    {
        if (!Money::isValid($this->amount)) {
            throw new \InvalidArgumentException(
                "amount must be a positive decimal string, got '{$this->amount}'"
            );
        }
        if ($this->baseCurrency === '') {
            throw new \InvalidArgumentException('base_currency must not be empty');
        }
        if ($this->ttlMinutes !== null && ($this->ttlMinutes < 10 || $this->ttlMinutes > 4320)) {
            throw new \InvalidArgumentException('ttl_minutes must be between 10 and 4320');
        }
        if ($this->orderId !== null && strlen($this->orderId) > 255) {
            throw new \InvalidArgumentException('order_id must be at most 255 characters');
        }
        if ($this->description !== null && strlen($this->description) > 500) {
            throw new \InvalidArgumentException('description must be at most 500 characters');
        }
        foreach (['webhook_url' => $this->webhookUrl, 'success_url' => $this->successUrl] as $field => $url) {
            if ($url !== null && strlen($url) > 2000) {
                throw new \InvalidArgumentException("{$field} must be at most 2000 characters");
            }
        }
    }
}
