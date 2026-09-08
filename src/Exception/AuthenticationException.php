<?php

declare(strict_types=1);

namespace ZeroKYC\Exception;

/**
 * 401/403 from the API: missing/invalid key or forbidden scope.
 * Never retried automatically.
 */
class AuthenticationException extends \RuntimeException implements ZeroKYCException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}
