<?php

declare(strict_types=1);

namespace ZeroKYC\Tests;

use PHPUnit\Framework\TestCase;
use ZeroKYC\Idempotency\IdempotencyKey;
use ZeroKYC\Support\Money;
use ZeroKYC\Webhook\ReplayGuard;
use ZeroKYC\Webhook\WebhookEvent;

final class SupportTest extends TestCase
{
    public function testIdempotencyKeyFormat(): void
    {
        self::assertSame(
            'zerokyc:whmcs:invoice:1042',
            IdempotencyKey::make('whmcs', 'invoice', '1042'),
        );
    }

    public function testIdempotencyKeyLengthLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdempotencyKey::make('platform', str_repeat('x', 100), str_repeat('y', 30));
    }

    /** @dataProvider comparisons */
    public function testMoneyCompare(string $a, string $b, int $expected): void
    {
        self::assertSame($expected, Money::compare($a, $b) <=> 0, "{$a} vs {$b}");
    }

    /** @return list<array{0:string,1:string,2:int}> */
    public static function comparisons(): array
    {
        return [
            ['1.5', '1.5', 0],
            ['2', '10', -1],        // string compare would say otherwise
            ['10.0000001', '10', 1],
            ['0.1', '0.0999', 1],
            ['19.90', '19.9', 0],
            ['0', '0.000', 0],
            ['1000000.01', '1000000.0099', 1],
        ];
    }

    public function testMoneyValidAmounts(): void
    {
        self::assertTrue(Money::isValid('19.90'));
        self::assertTrue(Money::isValid('1'));
        self::assertFalse(Money::isValid('0'));
        self::assertFalse(Money::isValid('-5'));
        self::assertFalse(Money::isValid('abc'));
        self::assertFalse(Money::isValid('1,5'));
    }

    public function testReplayGuardDetectsDuplicates(): void
    {
        $store = new InMemoryEventStore();
        $guard = new ReplayGuard($store);

        self::assertFalse($guard->isDuplicate('evt_1'));
        self::assertTrue($guard->isDuplicate('evt_1'));
        self::assertFalse($guard->isDuplicate('evt_2'));
    }

    public function testMatchesOrderValidatesInvoiceAmountAndAsset(): void
    {
        $guard = new ReplayGuard(new InMemoryEventStore());

        // production payload shape: ticker + network nested under data.option
        $event = WebhookEvent::fromArray([
            'id' => 'evt_1',
            'type' => 'payment.confirmed',
            'data' => [
                'invoice_id' => 'inv_9',
                'option' => ['asset' => 'USDT', 'network' => 'tron', 'paid_amount' => '20.5'],
            ],
        ]);

        self::assertTrue($guard->matchesOrder($event, 'inv_9'));
        self::assertTrue($guard->matchesOrder($event, 'inv_9', '19.90', 'USDT'));       // ticker
        self::assertTrue($guard->matchesOrder($event, 'inv_9', '19.90', 'USDT_TRON')); // asset id
        self::assertTrue($guard->matchesOrder($event, 'inv_9', '20.5', 'USDT_TRON'));
        self::assertFalse($guard->matchesOrder($event, 'inv_OTHER'));                    // wrong invoice
        self::assertFalse($guard->matchesOrder($event, 'inv_9', '20.6'));                // under expected
        self::assertFalse($guard->matchesOrder($event, 'inv_9', '19.90', 'BTC'));        // wrong asset
        self::assertFalse($guard->matchesOrder($event, 'inv_9', '19.90', 'USDT_BTC'));   // wrong network part

        // docs-vector shape (flat asset/amount) keeps working
        $legacy = WebhookEvent::fromArray([
            'id' => 'evt_2',
            'type' => 'payment.confirmed',
            'invoice_id' => 'inv_9',
            'data' => ['asset' => 'USDT_TRON', 'amount' => '20.5'],
        ]);
        self::assertTrue($guard->matchesOrder($legacy, 'inv_9', '19.90', 'USDT_TRON'));
    }
}
