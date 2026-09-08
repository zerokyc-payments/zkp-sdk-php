<?php

declare(strict_types=1);

namespace ZeroKYC;

use ZeroKYC\Http\HttpClientInterface;
use ZeroKYC\Invoice\CreateInvoiceRequest;
use ZeroKYC\Invoice\CreateInvoiceResponse;
use ZeroKYC\Invoice\Invoice;
use ZeroKYC\Webhook\WebhookEvent;
use ZeroKYC\Webhook\WebhookVerifier;

/**
 * Facade - the single entry point platform integrations should use.
 *
 *     $zkp = new ZeroKYC([
 *         'api_key'     => getenv('ZEROKYC_API_KEY'),       // pk_test_... / pk_live_...
 *         'environment' => 'sandbox',                        // sandbox | production
 *         'webhook_secret' => getenv('ZEROKYC_WEBHOOK_SECRET'), // whsec_...
 *     ]);
 *
 *     $response = $zkp->createInvoice(
 *         CreateInvoiceRequest::make('19.90', 'USD')->withOrderId('INV-1042'),
 *         IdempotencyKey::make('whmcs', 'invoice', '1042'),
 *     );
 *     header('Location: ' . $response->invoice->checkoutUrl);
 */
final class ZeroKYC
{
    private Client $client;

    public function __construct(
        public readonly Config $config,
        ?HttpClientInterface $http = null,
    ) {
        $this->client = new Client($config, $http);
    }

    /** @param array<string,mixed> $options see Config::fromArray() */
    public static function fromArray(array $options): self
    {
        return new self(Config::fromArray($options));
    }

    /**
     * Create an invoice. With an idempotency key a timeout+retry returns the
     * same invoice instead of creating a duplicate.
     */
    public function createInvoice(
        CreateInvoiceRequest $request,
        ?string $idempotencyKey = null,
    ): CreateInvoiceResponse {
        [$response, $replay] = $this->client->createInvoice($request->toArray(), $idempotencyKey);
        $data = $response->json();
        if ($data === null) {
            throw new Exception\ApiException('invoice response was not JSON', 201);
        }
        return new CreateInvoiceResponse(Invoice::fromArray($data), $replay);
    }

    /**
     * Fetch an invoice for reconciliation, cron checks or recovery after a
     * lost webhook. Polling is fine; webhooks are still the primary signal.
     */
    public function getInvoice(string $invoiceId): Invoice
    {
        $data = $this->client->getInvoice($invoiceId)->json();
        if ($data === null) {
            throw new Exception\ApiException('invoice response was not JSON', 200);
        }
        return Invoice::fromArray($data);
    }

    /** Cancel an invoice that has not been paid yet. */
    public function cancelInvoice(string $invoiceId): Invoice
    {
        $data = $this->client->cancelInvoice($invoiceId)->json();
        if ($data === null) {
            throw new Exception\ApiException('invoice response was not JSON', 200);
        }
        return Invoice::fromArray($data);
    }

    /** Liveness/configuration probe; returns the decoded /v1/ping body. */
    public function ping(): array
    {
        $data = $this->client->ping()->json();
        return $data ?? [];
    }

    /**
     * Verify a webhook delivery. Uses the webhook_secret from the config when
     * set; pass an explicit secret when merchants configure it per endpoint.
     *
     * @throws Exception\WebhookVerificationException
     */
    public function verifyWebhook(string $rawBody, string $signatureHeader, ?string $secret = null): WebhookEvent
    {
        return $this->verifier($secret)->verify($rawBody, $signatureHeader);
    }

    public function verifier(?string $secret = null): WebhookVerifier
    {
        return new WebhookVerifier($secret ?? $this->config->webhookSecret);
    }

    public function client(): Client
    {
        return $this->client;
    }
}
