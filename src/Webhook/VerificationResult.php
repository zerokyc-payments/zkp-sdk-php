<?php

declare(strict_types=1);

namespace ZeroKYC\Webhook;

/**
 * Non-throwing verification outcome (see WebhookVerifier::check()).
 */
final class VerificationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $reason,
        public readonly ?WebhookEvent $event,
    ) {
    }

    public static function invalid(string $reason): self
    {
        return new self(false, $reason, null);
    }

    public static function ok(WebhookEvent $event): self
    {
        return new self(true, null, $event);
    }
}
