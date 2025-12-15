<?php

namespace Mdiqbal\LaravelPayments\Gateways\Paypal;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class PaypalGateway extends AbstractGateway
{
    private ?string $accessToken = null;
    private ?int $tokenExpires = null;

    public function gatewayName(): string
    {
        return 'paypal';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            // Create PayPal order
            $orderData = $this->createOrder($request);

            // Extract approval URL from response
            $approvalUrl = null;
            foreach ($orderData['links'] as $link) {
                if ($link['rel'] === 'approve') {
                    $approvalUrl = $link['href'];
                    break;
                }
            }

            if (!$approvalUrl) {
                throw new PaymentException('Unable to get PayPal approval URL');
            }

            $this->log('info', 'PayPal order created', [
                'order_id' => $orderData['id'],
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency()
            ]);

            return PaymentResponse::redirect($approvalUrl, [
                'transaction_id' => $orderData['id'],
                'order_id' => $orderData['id'],
                'status' => 'created',
                'message' => 'Redirect to PayPal for payment approval'
            ]);

        } catch (\Exception $e) {
            $this->log('error', 'PayPal payment creation failed', [
                'error' => $e->getMessage(),
                'amount' => $request->getAmount()
            ]);
            throw new PaymentException('PayPal payment failed: ' . $e->getMessage());
        }
    }

    public function verify(array $payload): PaymentResponse
    {
        try {
            // PayPal webhook events include resource type and event type
            $eventType = $payload['event_type'] ?? '';
            $resource = $payload['resource'] ?? [];
            $resourceType = $payload['resource_type'] ?? '';

            // Verify webhook signature
            if (!$this->verifyWebhookSignature($payload)) {
                throw new PaymentException('Invalid PayPal webhook signature');
            }

            // Handle different event types
            switch ($eventType) {
                case 'PAYMENT.AUTHORIZATION.CREATED':
                case 'PAYMENT.SALE.COMPLETED':
                case 'PAYMENT.CAPTURE.COMPLETED':
                case 'CHECKOUT.ORDER.APPROVED':
                    $status = 'completed';
                    $success = true;
                    break;

                case 'PAYMENT.SALE.DENIED':
                case 'PAYMENT.CAPTURE.DENIED':
                case 'CHECKOUT.ORDER.CANCELLED':
                    $status = 'failed';
                    $success = false;
                    break;

                case 'CHECKOUT.ORDER.CREATED':
                    $status = 'pending';
                    $success = false;
                    break;

                default:
                    $status = 'unknown';
                    $success = false;
            }

            // Get transaction details
            $transactionId = $resource['id'] ?? $resource['sale_id'] ?? null;
            $orderId = $resource['id'] ?? $resource['order_id'] ?? null;
            $amount = $resource['amount']['total'] ?? 0;
            $currency = $resource['amount']['currency'] ?? 'USD';

            $this->log('info', 'PayPal webhook verified', [
                'event_type' => $eventType,
                'transaction_id' => $transactionId,
                'order_id' => $orderId,
                'status' => $status
            ]);

            return new PaymentResponse(
                success: $success,
                transactionId: $transactionId,
                status: $status,
                data: [
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'event_type' => $eventType,
                    'resource_type' => $resourceType,
                    'webhook_data' => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'PayPal webhook verification failed', [
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('PayPal webhook verification failed: ' . $e->getMessage());
        }
    }

    public function refund(string $transactionId, float $amount): bool
    {
        try {
            // First capture the payment if it's an order
            if (str_starts_with($transactionId, 'order-')) {
                $order = $this->captureOrder($transactionId);
                if (isset($order['purchase_units'][0]['payments']['captures'][0]['id'])) {
                    $transactionId = $order['purchase_units'][0]['payments']['captures'][0]['id'];
                }
            }

            // Process refund
            $refundData = $this->processRefund($transactionId, $amount);

            $this->log('info', 'PayPal refund processed', [
                'transaction_id' => $transactionId,
                'refund_id' => $refundData['id'] ?? null,
                'amount' => $amount
            ]);

            return true;

        } catch (\Exception $e) {
            $this->log('error', 'PayPal refund failed', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('PayPal refund failed: ' . $e->getMessage());
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * Capture payment after approval
     */
    public function capturePayment(string $orderId): PaymentResponse
    {
        try {
            $captureData = $this->captureOrder($orderId);

            $status = $captureData['status'] === 'COMPLETED' ? 'completed' : 'pending';
            $transactionId = $captureData['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
            $amount = $captureData['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0;

            return new PaymentResponse(
                success: $captureData['status'] === 'COMPLETED',
                transactionId: $transactionId,
                status: $status,
                data: [
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'capture_data' => $captureData
                ]
            );

        } catch (\Exception $e) {
            throw new PaymentException('PayPal capture failed: ' . $e->getMessage());
        }
    }

    protected function getEndpoint(string $path): string
    {
        $baseUrl = $this->isSandbox()
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        return $baseUrl . '/' . ltrim($path, '/');
    }

    protected function createHttpClient(): Client
    {
        return new Client([
            'base_uri' => $this->getEndpoint(''),
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Accept-Language' => 'en_US',
                'User-Agent' => 'Laravel-Payments/1.0.0',
            ]
        ]);
    }

    /**
     * Get PayPal access token
     */
    private function getAccessToken(): string
    {
        // Return cached token if still valid
        if ($this->accessToken && $this->tokenExpires && time() < $this->tokenExpires) {
            return $this->accessToken;
        }

        $clientId = $this->getModeConfig('client_id');
        $clientSecret = $this->getModeConfig('client_secret');

        if (!$clientId || !$clientSecret) {
            throw new PaymentException('PayPal client credentials not configured');
        }

        $client = new Client();
        $response = $client->request('POST', $this->getEndpoint('v1/oauth2/token'), [
            'auth' => [$clientId, $clientSecret],
            'form_params' => [
                'grant_type' => 'client_credentials'
            ],
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Language' => 'en_US'
            ]
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data['access_token'])) {
            throw new PaymentException('Failed to get PayPal access token');
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpires = time() + ($data['expires_in'] - 60); // Subtract 1 minute buffer

        return $this->accessToken;
    }

    /**
     * Create PayPal order
     */
    private function createOrder(PaymentRequest $request): array
    {
        $accessToken = $this->getAccessToken();

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $request->getTransactionId() ?? $this->generateOrderId(),
                'description' => $request->getDescription() ?? 'Payment',
                'amount' => [
                    'currency_code' => $request->getCurrency(),
                    'value' => number_format($request->getAmount(), 2, '.', '')
                ],
                'custom_id' => $request->getMetadata()['custom_id'] ?? null
            ]],
            'application_context' => [
                'return_url' => $request->getReturnUrl() ?? route('payment.success'),
                'cancel_url' => $request->getCancelUrl() ?? route('payment.cancel'),
                'brand_name' => $request->getMetadata()['brand_name'] ?? config('app.name'),
                'locale' => 'en-US',
                'landing_page' => 'BILLING',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
            ]
        ];

        // Add items if provided
        if (!empty($request->getMetadata()['items'])) {
            $payload['purchase_units'][0]['items'] = $request->getMetadata()['items'];
        }

        $response = $this->makeRequest('POST', 'v2/checkout/orders', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ],
            'json' => $payload
        ]);

        return $response;
    }

    /**
     * Capture PayPal order
     */
    private function captureOrder(string $orderId): array
    {
        $accessToken = $this->getAccessToken();

        $response = $this->makeRequest('POST', "v2/checkout/orders/{$orderId}/capture", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ]
        ]);

        return $response;
    }

    /**
     * Process refund
     */
    private function processRefund(string $captureId, float $amount): array
    {
        $accessToken = $this->getAccessToken();

        $payload = [
            'amount' => [
                'value' => number_format($amount, 2, '.', ''),
                'currency_code' => 'USD' // TODO: Get from original transaction
            ]
        ];

        $response = $this->makeRequest('POST', "v2/payments/captures/{$captureId}/refund", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ],
            'json' => $payload
        ]);

        return $response;
    }

    /**
     * Verify webhook signature
     */
    private function verifyWebhookSignature(array $payload): bool
    {
        $webhookId = $this->getConfig('webhook_secret');

        if (!$webhookId) {
            // Skip verification if webhook ID is not configured
            return true;
        }

        try {
            $headers = getallheaders() ?: [];
            $authAlgo = $headers['PAYPAL-AUTH-ALGO'] ?? '';
            $signature = $headers['PAYPAL-TRANSACTION-ID'] ?? '';
            $certId = $headers['PAYPAL-CERT-ID'] ?? '';
            $transmissionId = $headers['PAYPAL-TRANSMISSION-ID'] ?? '';
            $transmissionTime = $headers['PAYPAL-TRANSMISSION-TIME'] ?? '';

            $verificationPayload = [
                'cert_id' => $certId,
                'webhook_id' => $webhookId,
                'transmission_id' => $transmissionId,
                'transmission_sig' => $signature,
                'transmission_time' => $transmissionTime,
                'webhook_event' => $payload,
                'auth_algo' => $authAlgo
            ];

            $response = $this->makeRequest('POST', 'v1/notifications/verify-webhook-signature', [
                'json' => $verificationPayload
            ]);

            return $response['verification_status'] === 'SUCCESS';

        } catch (\Exception $e) {
            $this->log('error', 'Webhook signature verification failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Make authenticated HTTP request
     */
    private function makeRequest(string $method, string $url, array $options = []): array
    {
        $client = $this->createHttpClient();

        try {
            $response = $client->request($method, $url, $options);
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($data === null) {
                throw new PaymentException("Invalid JSON response from PayPal");
            }

            // Handle PayPal errors
            if (isset($data['error'])) {
                $message = $data['error_description'] ?? $data['error'] ?? 'PayPal API error';
                throw new PaymentException($message);
            }

            if (isset($data['details']) && is_array($data['details'])) {
                $messages = array_map(fn($detail) => $detail['description'] ?? '', $data['details']);
                if (!empty($messages)) {
                    throw new PaymentException(implode(', ', $messages));
                }
            }

            return $data;

        } catch (RequestException $e) {
            $message = "PayPal API request failed: {$e->getMessage()}";

            if ($e->hasResponse()) {
                $body = $e->getResponse()->getBody()->getContents();
                $data = json_decode($body, true);

                if ($data && isset($data['message'])) {
                    $message = $data['message'];
                } elseif ($data && isset($data['error_description'])) {
                    $message = $data['error_description'];
                }
            }

            throw new PaymentException($message, 0, $e);
        }
    }
}