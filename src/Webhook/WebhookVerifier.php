<?php

declare(strict_types=1);

namespace ZeroKYC\Webhook;

use ZeroKYC\Exception\WebhookVerificationException;

/**
 * Verifies ZeroKYC Pay webhook deliveries.
 *
 * Every delivery carries one header:
 *
 *     X-ZKP-Signature: t=<unix seconds>,v1=<64-char lowercase hex>
 *
 * v1 = HMAC-SHA256(webhook_secret, "{t}.{raw_body}") where raw_body is the
 * exact request body as received (never re-serialized JSON).
 *
 * Usage inside a controller (never trust a browser success URL instead):
 *
 *     $event = (new WebhookVerifier($secret))->verify(
 *         file_get_contents('php://input'),
 *         $_SERVER['HTTP_X_ZKP_SIGNATURE'] ?? ''
 *     );
 */
final class WebhookVerifier
{
    public const DEFAULT_TOLERANCE = 300;

    /** strict t=<int>,v1=<64 hex>; rejects whitespace, extra pairs, uppercase */
    private const HEADER_FORMAT = '/^t=(\d{1,12}),v1=([0-9a-f]{64})$/';

    public function __construct(
        private readonly string $webhookSecret,
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE,
    ) {
        if ($webhookSecret === '') {
            throw new \InvalidArgumentException('webhook secret must not be empty');
        }
        if ($toleranceSeconds < 1) {
            throw new \InvalidArgumentException('tolerance must be at least 1 second');
        }
    }

    /**
     * Verify and decode; throws WebhookVerificationException on any failure.
     *
     * @throws WebhookVerificationException
     */
    public function verify(string $rawBody, string $signatureHeader, ?int $now = null): WebhookEvent
    {
        $result = $this->check($rawBody, $signatureHeader, $now);
        if ($result->event === null) {
            throw new WebhookVerificationException(
                $this->messageFor($result->reason ?? 'invalid'),
                $result->reason ?? 'invalid',
            );
        }
        return $result->event;
    }

    /**
     * Non-throwing variant for pipelines that prefer result objects.
     */
    public function check(string $rawBody, string $signatureHeader, ?int $now = null): VerificationResult
    {
        if ($signatureHeader === '') {
            return VerificationResult::invalid(WebhookVerificationException::REASON_MISSING_HEADER);
        }
        if (preg_match(self::HEADER_FORMAT, $signatureHeader, $m) !== 1) {
            return VerificationResult::invalid(WebhookVerificationException::REASON_MALFORMED_HEADER);
        }
        [, $timestamp, $signature] = $m;

        $now ??= time();
        $skew = $now - (int) $timestamp;
        if ($skew > $this->toleranceSeconds) {
            return VerificationResult::invalid(WebhookVerificationException::REASON_STALE_TIMESTAMP);
        }
        if (-$skew > $this->toleranceSeconds) {
            return VerificationResult::invalid(WebhookVerificationException::REASON_FUTURE_TIMESTAMP);
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->webhookSecret);
        if (!hash_equals($expected, $signature)) {
            return VerificationResult::invalid(WebhookVerificationException::REASON_SIGNATURE_MISMATCH);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return VerificationResult::invalid(WebhookVerificationException::REASON_MALFORMED_PAYLOAD);
        }

        return VerificationResult::ok(WebhookEvent::fromArray($payload));
    }

    /**
     * Build a signature header for a body (tests and local replay tooling).
     */
    public function sign(string $rawBody, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $v1 = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->webhookSecret);
        return "t={$timestamp},v1={$v1}";
    }

    private function messageFor(string $reason): string
    {
        return match ($reason) {
            WebhookVerificationException::REASON_MISSING_HEADER => 'X-ZKP-Signature header is missing',
            WebhookVerificationException::REASON_MALFORMED_HEADER => 'signature header is malformed (expected t=<int>,v1=<64 lowercase hex>)',
            WebhookVerificationException::REASON_STALE_TIMESTAMP => 'signature timestamp is outside the tolerance window (stale)',
            WebhookVerificationException::REASON_FUTURE_TIMESTAMP => 'signature timestamp is outside the tolerance window (future)',
            WebhookVerificationException::REASON_SIGNATURE_MISMATCH => 'signature does not match the raw body',
            WebhookVerificationException::REASON_MALFORMED_PAYLOAD => 'verified body is not a JSON object',
            default => 'verification failed',
        };
    }
}
