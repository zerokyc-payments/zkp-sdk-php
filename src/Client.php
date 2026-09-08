<?php

declare(strict_types=1);

namespace ZeroKYC;

use ZeroKYC\Exception\ApiException;
use ZeroKYC\Exception\AuthenticationException;
use ZeroKYC\Exception\NetworkException;
use ZeroKYC\Exception\RateLimitException;
use ZeroKYC\Exception\ValidationException;
use ZeroKYC\Http\CurlHttpClient;
use ZeroKYC\Http\HttpClientInterface;
use ZeroKYC\Http\Response;
use ZeroKYC\Idempotency\IdempotencyKey;

/**
 * HTTP client with error mapping and a bounded, idempotency-aware retry
 * policy. Platform adapters never talk to the API directly - they go through
 * this client (or the ZeroKYC facade).
 *
 * Retry rules:
 *  - GET:            retry NetworkException and 5xx
 *  - POST invoices:  retry only when an idempotency key is present
 *  - 429:            retried (max twice) honoring Retry-After up to 5s
 *  - 400/401/403/422: never retried
 */
final class Client
{
    public function __construct(
        private readonly Config $config,
        private ?HttpClientInterface $http = null,
        /** @var callable(int<0,max> $milliseconds): void tests inject a no-op */
        private $sleeper = null,
    ) {
        $this->http ??= new CurlHttpClient();
        $this->sleeper ??= static fn (int $ms): bool => usleep($ms * 1000) === false;
    }

    /**
     * @param array<string,mixed> $body payload for POST /v1/invoices
     * @param array<string,string> $extraHeaders
     * @return array{0:Response,1:bool} response + idempotentReplay flag
     */
    public function createInvoice(array $body, ?string $idempotencyKey = null, array $extraHeaders = []): array
    {
        if ($idempotencyKey !== null) {
            IdempotencyKey::assertValid($idempotencyKey);
        }
        $headers = array_merge($extraHeaders, [
            'Content-Type' => 'application/json',
        ]);
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->send(
            method: 'POST',
            path: '/v1/invoices',
            headers: $headers,
            body: json_encode($body, JSON_THROW_ON_ERROR),
            mayRetry: $idempotencyKey !== null,
            expectedStatus: 201,
        );
        return [$response, strtolower((string) $response->header('Idempotent-Replay')) === 'true'];
    }

    public function getInvoice(string $invoiceId): Response
    {
        return $this->send(
            method: 'GET',
            path: '/v1/invoices/' . rawurlencode($invoiceId),
            mayRetry: true,
            expectedStatus: 200,
        );
    }

    public function cancelInvoice(string $invoiceId): Response
    {
        return $this->send(
            method: 'POST',
            path: '/v1/invoices/' . rawurlencode($invoiceId) . '/cancel',
            headers: ['Content-Type' => 'application/json'],
            body: '{}',
            mayRetry: false, // cancel has no idempotency header semantics
            expectedStatus: 200,
        );
    }

    public function ping(): Response
    {
        return $this->send(
            method: 'GET',
            path: '/v1/ping',
            mayRetry: true,
            expectedStatus: 200,
        );
    }

    private function send(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
        bool $mayRetry = false,
        int $expectedStatus = 200,
    ): Response {
        $attempt = 0;
        while (true) {
            $delayMs = null;
            try {
                $response = $this->http->request(
                    $method,
                    $this->config->baseUrl . $path,
                    array_merge($headers, [
                        'Authorization' => 'Bearer ' . $this->config->apiKey,
                        'Accept' => 'application/json',
                    ]),
                    $body,
                    $this->config->timeout,
                );
            } catch (NetworkException $e) {
                if (!$mayRetry || $attempt >= $this->config->maxRetries) {
                    throw $e;
                }
                ($this->sleeper)($this->backoffMs($attempt));
                $attempt++;
                continue;
            }

            $status = $response->status;
            if ($status === $expectedStatus) {
                return $response;
            }

            if ($this->shouldRetryStatus($status) && $mayRetry && $attempt < $this->config->maxRetries) {
                $delayMs = $status === 429
                    ? $this->rateLimitDelayMs($response->header('retry-after'))
                    : $this->backoffMs($attempt);
                if ($delayMs !== null) {
                    ($this->sleeper)($delayMs);
                    $attempt++;
                    continue;
                }
                // Retry-After beyond the cap: fall through and surface the 429
            }

            [$code, $message, $docUrl] = $this->errorParts($response);
            $this->throwMapped($status, $message, $code, $docUrl, $response->header('retry-after'));
        }
    }

    /**
     * @return array{0:?string,1:string,?string} [errorCode, message, docUrl]
     */
    private function errorParts(Response $response): array
    {
        $decoded = $response->json();
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        return [
            isset($error['code']) ? (string) $error['code'] : null,
            (string) ($error['message'] ?? "unexpected HTTP {$response->status}"),
            isset($error['doc_url']) ? (string) $error['doc_url'] : null,
        ];
    }

    private function shouldRetryStatus(int $status): bool
    {
        // 408 request timeout is transport-ish and safe to retry for idempotent calls
        return $status === 429 || $status === 408 || $status >= 500;
    }

    private function throwMapped(
        int $status,
        string $message,
        ?string $code,
        ?string $docUrl,
        ?string $retryAfter,
    ): never {
        if ($status === 401 || $status === 403) {
            throw new AuthenticationException($message, $code);
        }
        if ($status === 429) {
            throw new RateLimitException($message, $retryAfter !== null ? (int) $retryAfter : null);
        }
        if ($status === 400 || $status === 422) {
            throw new ValidationException($message);
        }
        if ($status === 404) {
            throw new ApiException($message ?: 'not found', 404, $code, $docUrl);
        }
        if ($status === 402) {
            // account suspended cutoff - a billing state, not a bad request
            throw new ApiException($message, 402, $code, $docUrl);
        }
        throw new ApiException($message, $status, $code, $docUrl);
    }

    private function backoffMs(int $attempt): int
    {
        // 300ms, 600ms, 1200ms... bounded by maxRetries
        return 300 * (2 ** $attempt);
    }

    private function rateLimitDelayMs(?string $retryAfter): ?int
    {
        if ($retryAfter === null) {
            return $this->backoffMs(0);
        }
        $seconds = (int) $retryAfter;
        return $seconds <= 5 ? $seconds * 1000 : null; // give up beyond the cap
    }
}
