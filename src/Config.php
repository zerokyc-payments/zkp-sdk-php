<?php

declare(strict_types=1);

namespace ZeroKYC;

/**
 * SDK configuration.
 *
 * Recommended construction is through the ZeroKYC facade:
 *
 *     $zkp = new ZeroKYC([
 *         'api_key'      => getenv('ZEROKYC_API_KEY'),
 *         'environment'  => 'sandbox',          // or 'production'
 *         'webhook_secret' => getenv('ZEROKYC_WEBHOOK_SECRET'), // optional
 *     ]);
 *
 * The base URL is defined centrally by the SDK. The override option exists
 * for tests and local development only - platform adapters must never
 * hard-code URLs.
 */
final class Config
{
    public const ENV_SANDBOX = 'sandbox';
    public const ENV_PRODUCTION = 'production';

    public const DEFAULT_BASE_URL = 'https://api.zerokyc-payments.com';

    /**
     * @param array<string,mixed> $options
     *
     * Supported keys:
     *  - api_key        (string, required) pk_test_... / pk_live_... publishable key
     *  - environment    (string) sandbox|production, inferred from the key when omitted
     *  - webhook_secret (string) whsec_... endpoint secret, used by the verifyWebhook() facade
     *  - timeout        (float) per-request HTTP timeout in seconds, default 15
     *  - max_retries    (int)   bounded retries per request, default 2 (3 attempts total)
     *  - base_url       (string) override, tests/local dev only
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $environment = self::ENV_PRODUCTION,
        public readonly string $webhookSecret = '',
        public readonly float $timeout = 15.0,
        public readonly int $maxRetries = 2,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('api_key is required');
        }
        if (!in_array($environment, [self::ENV_SANDBOX, self::ENV_PRODUCTION], true)) {
            throw new \InvalidArgumentException(
                "environment must be 'sandbox' or 'production', got '{$environment}'"
            );
        }
        // Sandbox keys create simulated invoices; mixing them up silently is
        // the classic production incident, so refuse the mismatch outright.
        $isTestKey = str_starts_with($apiKey, 'pk_test_');
        if ($environment === self::ENV_PRODUCTION && $isTestKey) {
            throw new \InvalidArgumentException(
                'environment is production but the api_key is a sandbox key (pk_test_...); '
                . 'use a pk_live_... key or set environment to sandbox'
            );
        }
        if ($environment === self::ENV_SANDBOX && !$isTestKey) {
            throw new \InvalidArgumentException(
                'environment is sandbox but the api_key is not a sandbox key; '
                . 'expected a pk_test_... key'
            );
        }
        if ($maxRetries < 0 || $maxRetries > 5) {
            throw new \InvalidArgumentException('max_retries must be between 0 and 5');
        }
    }

    /**
     * @param array<string,mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $apiKey = (string) ($options['api_key'] ?? '');
        $environment = isset($options['environment'])
            ? (string) $options['environment']
            : (str_starts_with($apiKey, 'pk_test_') ? self::ENV_SANDBOX : self::ENV_PRODUCTION);

        return new self(
            apiKey: $apiKey,
            environment: $environment,
            webhookSecret: (string) ($options['webhook_secret'] ?? ''),
            timeout: (float) ($options['timeout'] ?? 15.0),
            maxRetries: (int) ($options['max_retries'] ?? 2),
            baseUrl: rtrim((string) ($options['base_url'] ?? self::DEFAULT_BASE_URL), '/'),
        );
    }

    public function isSandbox(): bool
    {
        return $this->environment === self::ENV_SANDBOX;
    }
}
