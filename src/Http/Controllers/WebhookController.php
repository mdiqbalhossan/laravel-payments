<?php

namespace Mdiqbal\LaravelPayments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\WebhookPayload;
use Mdiqbal\LaravelPayments\Events\WebhookHandled;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use Mdiqbal\LaravelPayments\Facades\Payment;

class WebhookController extends Controller
{
    /**
     * Handle incoming webhook from payment gateway
     */
    public function handle(Request $request, string $gateway)
    {
        if (!Payment::hasGateway($gateway)) {
            return response()->json([
                'error' => 'Gateway not found',
                'message' => "Payment gateway '{$gateway}' is not configured"
            ], 404);
        }

        try {
            $gatewayInstance = Payment::gateway($gateway);
            $payload = WebhookPayload::fromHttpRequest($gateway, $request);

            // Gateways verify their own signature header against the raw body
            $response = $gatewayInstance instanceof AbstractGateway
                ? $gatewayInstance->handleWebhook($payload->all(), $payload->rawBody, $payload->headers)
                : $gatewayInstance->verify($payload->all());

            event(new WebhookHandled($gateway, $response, $payload->all()));

            return $response->success
                ? response()->json(['status' => 'success'], 200)
                : response()->json(['status' => 'failed', 'message' => $response->message], 400);

        } catch (InvalidSignatureException $e) {
            logger()->warning('Webhook signature verification failed', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Invalid signature',
                'message' => $e->getMessage()
            ], 401);

        } catch (PaymentException $e) {
            logger()->warning('Webhook rejected', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Webhook rejected',
                'message' => $e->getMessage()
            ], 400);

        } catch (\Throwable $e) {
            logger()->error('Webhook processing failed', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Processing failed',
            ], 500);
        }
    }
}
