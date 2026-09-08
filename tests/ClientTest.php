<?php

declare(strict_types=1);

namespace ZeroKYC\Tests;

use PHPUnit\Framework\TestCase;
use ZeroKYC\Config;
use ZeroKYC\Exception\AuthenticationException;
use ZeroKYC\Exception\NetworkException;
use ZeroKYC\Exception\RateLimitException;
use ZeroKYC\Exception\ValidationException;
use ZeroKYC\Http\HttpClientInterface;
use ZeroKYC\Http\Response;
use ZeroKYC\Invoice\CreateInvoiceRequest;
use ZeroKYC\Invoice\InvoiceStatus;
use ZeroKYC\ZeroKYC;

final class ClientTest extends TestCase
{
    public function testCreateInvoiceParsesResponse(): void
    {
        $sdk = new ZeroKYC(
            Config::fromArray(['api_key' => 'pk_test_demo', 'base_url' => 'https://api.test']),
            new StubHttp(fn (): Response => new Response(201, self::invoiceJson(), ['idempotent-replay' => 'false'])),
        );

        $result = $sdk->createInvoice(
            CreateInvoiceRequest::make('19.90', 'USD')->withOrderId('INV-1042'),
            'zerokyc:test:invoice:1042',
        );

        self::assertSame('inv_123', $result->invoice->id);
        self::assertSame(InvoiceStatus::PENDING, $result->invoice->status);
        self::assertSame('created', $result->invoice->rawStatus);
        self::assertSame('https://pay.test/pay/inv_123', $result->invoice->checkoutUrl);
        self::assertFalse($result->idempotentReplay);
        self::assertCount(2, $result->invoice->options);
        self::assertNotNull($result->invoice->option('USDT_TRON'));
        self::assertNull($result->invoice->option('SOL'));
    }

    public function testIdempotentReplayFlagComesFromHeader(): void
    {
        $sdk = new ZeroKYC(
            Config::fromArray(['api_key' => 'pk_test_demo', 'base_url' => 'https://api.test']),
            new StubHttp(fn (): Response => new Response(201, self::invoiceJson(), ['Idempotent-Replay' => 'true'])),
        );

        $result = $sdk->createInvoice(CreateInvoiceRequest::make('5.00'));
        self::assertTrue($result->idempotentReplay);
    }

    public function testGetInvoice(): void
    {
        $sdk = new ZeroKYC(
            Config::fromArray(['api_key' => 'pk_test_demo', 'base_url' => 'https://api.test']),
            new StubHttp(fn (): Response => new Response(200, self::invoiceJson(['status' => 'confirmed', 'paid_amount' => '19.9', 'paid_asset' => 'USDT_TRON']))),
        );

        $invoice = $sdk->getInvoice('inv_123');
        self::assertTrue($invoice->isPaid());
        self::assertSame(InvoiceStatus::PAID, $invoice->status);
        self::assertSame('19.9', $invoice->paidAmount);
    }

    /** @dataProvider errorMapping */
    public function testErrorMapping(int $status, string $class, ?string $retryAfter = null): void
    {
        $sdk = new ZeroKYC(
            Config::fromArray(['api_key' => 'pk_test_demo', 'base_url' => 'https://api.test']),
            new StubHttp(fn (): Response => new Response(
                $status,
                json_encode(['error' => ['code' => 'x', 'message' => 'boom', 'doc_url' => null]]),
                $retryAfter !== null ? ['retry-after' => $retryAfter] : [],
            )),
        );

        $this->expectException($class);
        $this->expectExceptionMessage('boom');

        try {
            $sdk->getInvoice('inv_123');
        } catch (RateLimitException $e) {
            self::assertSame(12, $e->retryAfter);
            throw $e;
        }
    }

    /** @return list<array{0:int,1:string,2?:string}> */
    public static function errorMapping(): array
    {
        return [
            [401, AuthenticationException::class],
            [403, AuthenticationException::class],
            [400, ValidationException::class],
            [422, ValidationException::class],
            [429, RateLimitException::class, '12'],
            [500, \ZeroKYC\Exception\ApiException::class],
        ];
    }

    public function testNetworkFailureRetriedOnGetThenThrows(): void
    {
        $calls = 0;
        $http = new class implements HttpClientInterface {
            public int $calls = 0;
            public function request(string $m, string $u, array $h = [], ?string $b = null, ?float $t = null): Response
            {
                $this->calls++;
                throw new NetworkException('timeout', 28);
            }
        };
        $slept = [];
        $client = new \ZeroKYC\Client(
            Config::fromArray(['api_key' => 'pk_test_demo', 'base_url' => 'https://api.test']),
            $http,
            function (int $ms) use (&$slept): void {
                $slept[] = $ms;
            },
        );

        try {
            $client->getInvoice('inv_1');
            self::fail('NetworkException expected');
        } catch (NetworkException) {
        }
        self::assertSame(3, $http->calls);        // 1 + 2 retries
        self::assertCount(2, $slept);             // 300ms, 600ms
    }

    public function testPostWithoutIdempotencyKeyIsNotRetried(): void
    {
        $http = new class implements HttpClientInterface {
            public int $calls = 0;
            public function request(string $m, string $u, array $h = [], ?string $b = null, ?float $t = null): Response
            {
                $this->calls++;
                throw new NetworkException('dns', 6);
            }
        };
        $client = new \ZeroKYC\Client(
            Config::fromArray(['api_key' => 'pk_test_demo', 'base_url' => 'https://api.test']),
            $http,
            static function (): void {
            },
        );

        try {
            $client->createInvoice(['amount' => '5']);
            self::fail('NetworkException expected');
        } catch (NetworkException) {
        }
        self::assertSame(1, $http->calls);
    }

    public function testPostWithIdempotencyKeyRetries(): void
    {
        $http = new class implements HttpClientInterface {
            public int $calls = 0;
            public function request(string $m, string $u, array $h = [], ?string $b = null, ?float $t = null): Response
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new NetworkException('timeout', 28);
                }
                return new Response(201, ClientTest::invoiceJson(), []);
            }
        };
        $client = new \ZeroKYC\Client(
            Config::fromArray(['api_key' => 'pk_test_demo', 'base_url' => 'https://api.test']),
            $http,
            static function (): void {
            },
        );

        [$response, $replay] = $client->createInvoice(['amount' => '5'], 'zerokyc:test:invoice:1');
        self::assertSame(201, $response->status);
        self::assertFalse($replay);
        self::assertSame(2, $http->calls);
    }

    public function testConfigRejectsSandboxKeyInProduction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Config::fromArray(['api_key' => 'pk_test_demo', 'environment' => 'production']);
    }

    public function testConfigRejectsLiveKeyInSandbox(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Config::fromArray(['api_key' => 'pk_live_demo', 'environment' => 'sandbox']);
    }

    public function testConfigInfersEnvironmentFromKey(): void
    {
        $config = Config::fromArray(['api_key' => 'pk_test_demo']);
        self::assertTrue($config->isSandbox());
        $config = Config::fromArray(['api_key' => 'pk_live_demo']);
        self::assertFalse($config->isSandbox());
    }

    public function testCreateInvoiceRequestValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CreateInvoiceRequest::make('abc')->toArray();
    }

    public function testCreateInvoiceRequestPayload(): void
    {
        $payload = CreateInvoiceRequest::make('19.90')
            ->withOrderId('INV-1042')
            ->withPaymentCurrency('USDT_TRON')
            ->withTtlMinutes(60)
            ->withMetadata(['plan' => 'pro'])
            ->toArray();

        self::assertSame('19.90', $payload['amount']);
        self::assertSame('USD', $payload['base_currency']);
        self::assertSame('USDT_TRON', $payload['payment_currency']);
        self::assertSame('INV-1042', $payload['order_id']);
        self::assertSame(60, $payload['ttl_minutes']);
        self::assertSame(['plan' => 'pro'], $payload['metadata']);
        self::assertArrayNotHasKey('webhook_url', $payload);
    }

    /** @param array<string,mixed> $overrides */
    public static function invoiceJson(array $overrides = []): string
    {
        return json_encode(array_merge([
            'id' => 'inv_123',
            'order_id' => 'INV-1042',
            'description' => null,
            'amount' => '19.90',
            'base_currency' => 'USD',
            'payment_currency' => 'any',
            'status' => 'created',
            'ttl_minutes' => 360,
            'expires_at' => '2026-09-08T12:00:00Z',
            'created_at' => '2026-09-08T06:00:00Z',
            'checkout_url' => 'https://pay.test/pay/inv_123',
            'metadata' => [],
            'options' => [
                ['asset' => 'USDT_TRON', 'network' => 'tron', 'payment_address' => 'Tabc', 'amount_crypto' => '19.9', 'rate' => '1', 'status' => 'open'],
                ['asset' => 'BTC', 'network' => 'bitcoin', 'payment_address' => 'bc1q', 'amount_crypto' => '0.0002', 'rate' => '99000', 'status' => 'open'],
            ],
            'observations' => [],
            'paid_amount' => null,
            'paid_asset' => null,
        ], $overrides));
    }
}

/** @internal */
final class StubHttp implements HttpClientInterface
{
    /** @var callable():Response */
    private $responder;

    public function __construct(callable $responder)
    {
        $this->responder = $responder;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?float $timeout = null): Response
    {
        return ($this->responder)();
    }
}
