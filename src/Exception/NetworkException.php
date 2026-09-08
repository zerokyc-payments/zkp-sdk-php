<?php

declare(strict_types=1);

namespace ZeroKYC\Exception;

/**
 * Transport failure: DNS, connect, TLS or timeout. Safe to retry for GET,
 * and safe to retry for invoice creation when an idempotency key was used.
 */
class NetworkException extends \RuntimeException implements ZeroKYCException
{
    public function __construct(
        string $message,
        public readonly int $curlError = 0,
    ) {
        parent::__construct($message);
    }
}
