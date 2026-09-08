<?php

declare(strict_types=1);

namespace ZeroKYC\Http;

use ZeroKYC\Exception\NetworkException;

/**
 * Zero-config cURL transport (no external dependencies).
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly bool $verifyTls = true,
    ) {
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?float $timeout = null,
    ): Response {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new NetworkException('curl init failed for ' . $url);
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // signed webhook semantics; never follow
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => (int) ceil($timeout ?? 15.0),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new NetworkException("transport failure (curl {$errno}): {$error}", $errno);
        }

        /** @var string $raw */
        $parts = explode("\r\n\r\n", (string) $raw, 2);
        $headerBlock = $parts[0] ?? '';
        $responseBody = $parts[1] ?? '';

        $status = 0;
        $respHeaders = [];
        foreach (explode("\r\n", $headerBlock) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int) $m[1];
                continue; // skip status lines incl. 1xx intermediates
            }
            $sep = strpos($line, ':');
            if ($sep !== false) {
                $respHeaders[strtolower(trim(substr($line, 0, $sep)))] = trim(substr($line, $sep + 1));
            }
        }

        return new Response($status, $responseBody, $respHeaders);
    }
}
