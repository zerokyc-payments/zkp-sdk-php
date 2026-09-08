<?php

declare(strict_types=1);

namespace ZeroKYC\Exception;

/**
 * Webhook verification failed. The reason is machine-readable so callers can
 * log/monitor precisely without matching messages.
 */
class WebhookVerificationException extends \RuntimeException implements ZeroKYCException
{
    public const REASON_MISSING_HEADER = 'missing_header';
    public const REASON_MALFORMED_HEADER = 'malformed_header';
    public const REASON_STALE_TIMESTAMP = 'stale_timestamp';
    public const REASON_FUTURE_TIMESTAMP = 'future_timestamp';
    public const REASON_SIGNATURE_MISMATCH = 'signature_mismatch';
    public const REASON_MALFORMED_PAYLOAD = 'malformed_payload';

    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }
}
