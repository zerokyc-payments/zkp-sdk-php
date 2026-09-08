<?php

declare(strict_types=1);

namespace ZeroKYC\Exception;

/**
 * The API answered with an unexpected error (5xx or a malformed envelope).
 */
class ApiException extends \RuntimeException implements ZeroKYCException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly ?string $docUrl = null,
    ) {
        parent::__construct($message);
    }
}
