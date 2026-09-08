<?php

declare(strict_types=1);

namespace ZeroKYC\Tests;

use PHPUnit\Framework\TestCase;
use ZeroKYC\Webhook\WebhookVerifier;

/**
 * Round-trips every webhook fixture: body -> sign -> verify -> typed event.
 */
final class FixturesTest extends TestCase
{
    private const SECRET = 'whsec_fixture_secret';

    /** @dataProvider fixtures */
    public function testFixtureVerifiesAndParses(string $file, string $expectedType, ?string $expectedInvoiceId): void
    {
        $body = file_get_contents(__DIR__ . '/fixtures/webhooks/' . $file);
        self::assertNotFalse($body);

        $verifier = new WebhookVerifier(self::SECRET);
        $header = $verifier->sign($body);

        $event = $verifier->verify($body, $header);
        self::assertSame($expectedType, $event->type);
        self::assertSame($expectedInvoiceId, $event->invoiceId);
        self::assertNotSame('', $event->id);
    }

    /** @return list<array{0:string,1:string,2:string}> */
    public static function fixtures(): array
    {
        return [
            ['confirmed.json', 'payment.confirmed', 'inv_fixture_001'],
            ['underpaid.json', 'payment.underpaid', 'inv_fixture_002'],
            ['expired.json', 'invoice.expired', 'inv_fixture_003'],
            ['pending.json', 'payment.detected', 'inv_fixture_004'],
        ];
    }

    public function testConfirmedFixtureCarriesPaymentData(): void
    {
        $body = (string) file_get_contents(__DIR__ . '/fixtures/webhooks/confirmed.json');
        $verifier = new WebhookVerifier(self::SECRET);
        $event = $verifier->verify($body, $verifier->sign($body));

        self::assertSame('USDT_TRON', $event->data['asset']);
        self::assertSame('19.9', (string) $event->data['amount']);
        self::assertTrue($event->isPaymentConfirmed());
    }
}
