<?php

declare(strict_types=1);

namespace ZeroKYC\Tests;

use PHPUnit\Framework\TestCase;
use ZeroKYC\Invoice\InvoiceStatus;
use ZeroKYC\Support\StatusMapper;

final class StatusMapperTest extends TestCase
{
    /** @dataProvider mappings */
    public function testMapsRawStatuses(string $raw, InvoiceStatus $expected): void
    {
        self::assertSame($expected, StatusMapper::normalize($raw));
    }

    /** @return list<array{0:string,1:InvoiceStatus}> */
    public static function mappings(): array
    {
        return [
            ['created', InvoiceStatus::PENDING],
            ['pending', InvoiceStatus::PENDING],
            ['detecting', InvoiceStatus::CONFIRMING],
            ['confirmed', InvoiceStatus::PAID],
            ['underpaid', InvoiceStatus::UNDERPAID],
            ['expired', InvoiceStatus::EXPIRED],
            ['canceled', InvoiceStatus::CANCELLED],
        ];
    }

    public function testUnknownStatusNormalizesToNull(): void
    {
        self::assertNull(StatusMapper::normalize('something-new'));
    }

    public function testUnknownStatusFailsExplicitly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatusMapper::normalizeOrFail('nope');
    }

    public function testTerminalStates(): void
    {
        foreach (['confirmed', 'underpaid', 'expired', 'canceled'] as $raw) {
            self::assertTrue(StatusMapper::isTerminal($raw), $raw);
        }
        foreach (['created', 'pending', 'detecting'] as $raw) {
            self::assertFalse(StatusMapper::isTerminal($raw), $raw);
        }
    }

    public function testPaidOnlyWhenConfirmed(): void
    {
        self::assertTrue(StatusMapper::isPaid('confirmed'));
        self::assertFalse(StatusMapper::isPaid('detecting'));
    }
}
