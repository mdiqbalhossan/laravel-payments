<?php

namespace Mdiqbal\LaravelPayments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\DTO\WebhookPayload;

class WebhookController extends Controller
{
    /**
     * Handle incoming webhook from payment gateway
     */
    public function handle(Request $request, string $gateway)
    {
        try {
            // Validate gateway exists
            if (!Payment::hasGateway($gateway)) {
                return response()->json([
                    'error' => 'Gateway not found',
                    'message' => "Payment gateway '{$gateway}' is not configured"
                ], 404);
            }

            // Get the gateway instance
            $gatewayInstance = Payment::gateway($gateway);

            // Create webhook payload
            $payload = WebhookPayload::fromRequest(
                gateway: $gateway,
                payload: $request->all(),
                headers: $request->headers->all()
            );

            // Verify signature if gateway supports it
            if ($payload->hasSignature() && method_exists($gatewayInstance, 'validateWebhookSignature')) {
                if (!$gatewayInstance->validateWebhookSignature($payload->all(), $payload->signature)) {
                    throw new InvalidSignatureException("Invalid webhook signature for {$gateway}");
                }
            }

            // Process the webhook
            $response = $gatewayInstance->verify($payload->all());

            // Return appropriate response
            return $response->success
                ? response()->json(['status' => 'success'], 200)
                : response()->json(['status' => 'failed'], 400);

        } catch (InvalidSignatureException $e) {
            // Log the error
            logger()->error('Webhook signature verification failed', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'error' => 'Invalid signature',
                'message' => $e->getMessage()
            ], 401);

        } catch (\Exception $e) {
            // Log the error
            logger()->error('Webhook processing failed', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'error' => 'Processing failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}