<?php

declare(strict_types=1);

namespace ZeroKYC\Http;

/**
 * Minimal transport abstraction. The SDK ships with a zero-config cURL
 * implementation; inject your own (e.g. a PSR-18 adapter) for testing or
 * platform-specific stacks.
 */
interface HttpClientInterface
{
    /**
     * @param array<string,string> $headers
     *
     * @throws \ZeroKYC\Exception\NetworkException on DNS/connect/TLS/timeout failures
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?float $timeout = null,
    ): Response;
}
