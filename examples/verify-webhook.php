<?php

declare(strict_types=1);

/**
 * Minimal webhook signature verification (PSR-7 frameworks: pass the raw
 * body and the X-ZKP-Signature header from the request).
 */

require __DIR__ . '/../vendor/autoload.php';

use ZeroKYC\Exception\WebhookVerificationException;
use ZeroKYC\Webhook\WebhookVerifier;

$secret = (string) getenv('ZEROKYC_WEBHOOK_SECRET'); // whsec_... from console -> Webhooks

$verifier = new WebhookVerifier($secret, toleranceSeconds: 300);

try {
    $event = $verifier->verify(
        file_get_contents('php://input') ?: '',  // exact raw body - never re-serialized JSON
        $_SERVER['HTTP_X_ZKP_SIGNATURE'] ?? '',
    );
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    error_log("zerokyc webhook rejected: {$e->reason}");
    exit;
}

// signature is valid; handle by type
error_log("zerokyc event {$event->type} for invoice {$event->invoiceId}");
http_response_code(200);
