<?php

declare(strict_types=1);

namespace ZeroKYC\Http;

/**
 * Immutable HTTP response. Header lookup is case-insensitive.
 */
final class Response
{
    /**
     * @param array<string,string> $headers normalized to lowercase keys
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        array $headers = [],
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = $value;
        }
        $this->headers = $normalized;
    }

    /** @var array<string,string> */
    private readonly array $headers;

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string,mixed>|null decoded JSON body, null when not valid JSON
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
