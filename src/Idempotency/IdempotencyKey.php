<?php

declare(strict_types=1);

namespace ZeroKYC\Idempotency;

/**
 * Stable idempotency keys so a timeout + retry can never create two
 * invoices for one local order. Recommended format:
 *
 *     zerokyc:{platform}:{entity}:{id}
 *     zerokyc:whmcs:invoice:1042
 */
final class IdempotencyKey
{
    public const MAX_LENGTH = 120;

    public static function make(string $platform, string $entity, string $id): string
    {
        $key = "zerokyc:{$platform}:{$entity}:{$id}";
        self::assertValid($key);
        return $key;
    }

    public static function assertValid(string $key): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('idempotency key must not be empty');
        }
        if (strlen($key) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'idempotency key must be at most ' . self::MAX_LENGTH . ' characters'
            );
        }
    }
}
