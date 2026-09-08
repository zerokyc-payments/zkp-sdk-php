<?php

declare(strict_types=1);

namespace ZeroKYC\Exception;

/**
 * 400/422 from the API (or local request validation): the payload is invalid.
 * Never retried automatically - fix the request instead.
 */
class ValidationException extends \RuntimeException implements ZeroKYCException
{
}
