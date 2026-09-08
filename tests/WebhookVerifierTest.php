<?php

declare(strict_types=1);

namespace ZeroKYC\Tests;

use PHPUnit\Framework\TestCase;
use ZeroKYC\Exception\WebhookVerificationException;
use ZeroKYC\Webhook\WebhookVerifier;

final class WebhookVerifierTest extends TestCase
{
    // Canonical test vector from https://zerokyc-payments.com/docs/hmac-verification
    private const VECTOR_SECRET = 'whsec_zkp_test_vector_2026';
    private const VECTOR_BODY = '{"id":"evt_test_001","type":"payment.confirmed","invoice_id":"inv_test_001"}';
    private const VECTOR_TS = 1788788073;
    private const VECTOR_SIG = 'ade537fa13aec79a6d1648bd7f197872066c161676c389243ab5c6b13fea7f52';

    public function testAcceptsDocumentedTestVector(): void
    {
        $verifier = new WebhookVerifier(self::VECTOR_SECRET);
        $event = $verifier->verify(
            self::VECTOR_BODY,
            't=' . self::VECTOR_TS . ',v1=' . self::VECTOR_SIG,
            now: self::VECTOR_TS,
        );

        self::assertSame('evt_test_001', $event->id);
        self::assertSame('payment.confirmed', $event->type);
        self::assertSame('inv_test_001', $event->invoiceId);
        self::assertTrue($event->isPaymentConfirmed());
    }

    public function testRejectsWrongSecret(): void
    {
        $verifier = new WebhookVerifier('whsec_some_other_secret');

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/signature does not match/');

        $verifier->verify(
            self::VECTOR_BODY,
            't=' . self::VECTOR_TS . ',v1=' . self::VECTOR_SIG,
            now: self::VECTOR_TS,
        );
    }

    public function testRejectsModifiedBody(): void
    {
        $verifier = new WebhookVerifier(self::VECTOR_SECRET);
        $forged = str_replace('inv_test_001', 'inv_evil_999', self::VECTOR_BODY);

        $result = $verifier->check($forged, 't=' . self::VECTOR_TS . ',v1=' . self::VECTOR_SIG, self::VECTOR_TS);
        self::assertFalse($result->valid);
        self::assertSame(WebhookVerificationException::REASON_SIGNATURE_MISMATCH, $result->reason);
    }

    public function testRejectsStaleTimestamp(): void
    {
        $verifier = new WebhookVerifier(self::VECTOR_SECRET);
        $result = $verifier->check(
            self::VECTOR_BODY,
            't=' . self::VECTOR_TS . ',v1=' . self::VECTOR_SIG,
            self::VECTOR_TS + 301,
        );
        self::assertFalse($result->valid);
        self::assertSame(WebhookVerificationException::REASON_STALE_TIMESTAMP, $result->reason);
    }

    public function testRejectsFutureTimestamp(): void
    {
        $verifier = new WebhookVerifier(self::VECTOR_SECRET);
        $result = $verifier->check(
            self::VECTOR_BODY,
            't=' . self::VECTOR_TS . ',v1=' . self::VECTOR_SIG,
            self::VECTOR_TS - 301,
        );
        self::assertFalse($result->valid);
        self::assertSame(WebhookVerificationException::REASON_FUTURE_TIMESTAMP, $result->reason);
    }

    public function testAcceptsEdgeOfToleranceWindow(): void
    {
        $verifier = new WebhookVerifier(self::VECTOR_SECRET);
        $result = $verifier->check(
            self::VECTOR_BODY,
            't=' . self::VECTOR_TS . ',v1=' . self::VECTOR_SIG,
            self::VECTOR_TS + 300,
        );
        self::assertTrue($result->valid);
    }

    /** @dataProvider malformedHeaders */
    public function testRejectsMalformedHeaders(string $header, string $reason): void
    {
        $verifier = new WebhookVerifier(self::VECTOR_SECRET);
        $result = $verifier->check(self::VECTOR_BODY, $header, self::VECTOR_TS);
        self::assertFalse($result->valid);
        self::assertSame($reason, $result->reason);
    }

    /** @return list<array{0:string,1:string}> */
    public static function malformedHeaders(): array
    {
        return [
            ['', WebhookVerificationException::REASON_MISSING_HEADER],
            ['garbage', WebhookVerificationException::REASON_MALFORMED_HEADER],
            ['t=abc,v1=' . self::VECTOR_SIG, WebhookVerificationException::REASON_MALFORMED_HEADER],
            ['t=' . self::VECTOR_TS, WebhookVerificationException::REASON_MALFORMED_HEADER],
            ['v1=' . self::VECTOR_SIG, WebhookVerificationException::REASON_MALFORMED_HEADER],
            ['t=' . self::VECTOR_TS . ',v1=ADE537FA13AEC79A6D1648BD7F197872066C161676C389243AB5C6B13FEA7F52', WebhookVerificationException::REASON_MALFORMED_HEADER],
            ['t=' . self::VECTOR_TS . ',v1=' . self::VECTOR_SIG . ',extra=1', WebhookVerificationException::REASON_MALFORMED_HEADER],
            ['t=' . self::VECTOR_TS . ',v1=deadbeef', WebhookVerificationException::REASON_MALFORMED_HEADER],
        ];
    }

    public function testRejectsVerifiedButNonJsonBody(): void
    {
        $verifier = new WebhookVerifier(self::VECTOR_SECRET);
        $body = 'not json at all';
        $header = $verifier->sign($body, self::VECTOR_TS);

        $result = $verifier->check($body, $header, self::VECTOR_TS);
        self::assertFalse($result->valid);
        self::assertSame(WebhookVerificationException::REASON_MALFORMED_PAYLOAD, $result->reason);
    }

    public function testSignProducesVerifiableHeader(): void
    {
        $verifier = new WebhookVerifier(self::VECTOR_SECRET);
        $header = $verifier->sign(self::VECTOR_BODY, self::VECTOR_TS);

        self::assertSame('t=' . self::VECTOR_TS . ',v1=' . self::VECTOR_SIG, $header);
    }

    public function testEmptySecretIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WebhookVerifier('');
    }
}
