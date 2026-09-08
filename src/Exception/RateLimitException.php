<?php

declare(strict_types=1);

namespace ZeroKYC\Exception;

/**
 * 429 from the API. Carries the Retry-After delay (seconds) when provided.
 */
class RateLimitException extends \RuntimeException implements ZeroKYCException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
