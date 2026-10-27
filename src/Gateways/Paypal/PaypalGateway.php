<?php

namespace Mdiqbal\LaravelPayments\Gateways\Paypal;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
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

    /**
     * Verify (and capture) a PayPal order after the buyer returns.
     *
     * Pass the order id as 'token' (PayPal appends ?token=ORDER_ID to the
     * return URL), 'order_id' or 'id'. An APPROVED order is captured here; only
     * a COMPLETED capture counts as success. The payload itself is never trusted.
     */
    public function verify(array $payload): PaymentResponse
    {
        $orderId = $payload['token'] ?? $payload['order_id'] ?? $payload['orderID'] ?? $payload['id'] ?? null;

        if (!$orderId) {
            throw new PaymentException('PayPal order id (token) not found in payload');
        }

        try {
            $order = $this->getOrder($orderId);

            if (($order['status'] ?? null) === 'APPROVED') {
                $order = $this->captureOrder($orderId);
            }

            return $this->orderToResponse($order, $orderId);
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new PaymentException('PayPal verification failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Handle a PayPal webhook. The event is verified with PayPal's
     * verify-webhook-signature API using the configured webhook id.
     */
    public function handleWebhook(array $payload, string $rawBody, array $headers): PaymentResponse
    {
        $webhookId = $this->getModeConfig('webhook_id') ?? $this->getModeConfig('webhook_secret');
        if (!$webhookId) {
            throw PaymentException::configuration('paypal', 'webhook_id (PAYPAL_WEBHOOK_SECRET) is required to accept PayPal webhooks');
        }

        if (!$this->verifyWebhookSignature($rawBody, $headers, $webhookId)) {
            throw new InvalidSignatureException('Invalid PayPal webhook signature', gateway: 'paypal');
        }

        return $this->parseWebhookEvent($payload);
    }

    /**
     * Map a verified PayPal webhook event to a PaymentResponse.
     */
    protected function parseWebhookEvent(array $payload): PaymentResponse
    {
        $eventType = $payload['event_type'] ?? '';
        $resource = $payload['resource'] ?? [];

        [$status, $success] = match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.SALE.COMPLETED' => ['completed', true],
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.SALE.DENIED', 'CHECKOUT.ORDER.VOIDED' => ['failed', false],
            'PAYMENT.CAPTURE.PENDING', 'CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.CREATED' => ['pending', false],
            'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED' => ['refunded', false],
            default => ['unknown', false],
        };

        $amount = $resource['amount']['value'] ?? $resource['amount']['total'] ?? null;
        $currency = $resource['amount']['currency_code'] ?? $resource['amount']['currency'] ?? null;

        return new PaymentResponse(
            success: $success,
            transactionId: $resource['id'] ?? null,
            status: $status,
            data: [
                'order_id' => $resource['supplementary_data']['related_ids']['order_id'] ?? null,
                'custom_id' => $resource['custom_id'] ?? null,
                'event_type' => $eventType,
                'resource_type' => $payload['resource_type'] ?? null,
                'webhook_data' => $payload,
            ],
            gatewayReference: $resource['id'] ?? null,
            amount: $amount !== null ? (float) $amount : null,
            currency: $currency
        );
    }

    /**
     * Refund a capture. Accepts a capture id, or an order id whose capture is looked up.
     * An amount of 0 refunds the full capture.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        try {
            $capture = $this->findCapture($transactionId);

            $refundData = $this->processRefund($capture['id'], $amount, $capture['amount']['currency_code'] ?? 'USD');

            $this->log('info', 'PayPal refund processed', [
                'capture_id' => $capture['id'],
                'refund_id' => $refundData['id'] ?? null,
                'amount' => $amount,
                'status' => $refundData['status'] ?? null,
            ]);

            return in_array($refundData['status'] ?? null, ['COMPLETED', 'PENDING'], true);
        } catch (\Exception $e) {
            $this->log('error', 'PayPal refund failed', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('PayPal refund failed: ' . $e->getMessage(), 0, $e);
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
            return $this->orderToResponse($this->captureOrder($orderId), $orderId);
        } catch (\Exception $e) {
            throw new PaymentException('PayPal capture failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build a PaymentResponse from a v2 order representation.
     */
    protected function orderToResponse(array $order, string $orderId): PaymentResponse
    {
        $unit = $order['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? null;
        $captured = ($capture['status'] ?? null) === 'COMPLETED';

        $status = match (true) {
            $captured => 'completed',
            ($capture['status'] ?? null) === 'PENDING', in_array($order['status'] ?? null, ['CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED'], true) => 'pending',
            ($order['status'] ?? null) === 'VOIDED' => 'canceled',
            default => 'failed',
        };

        $amount = $capture['amount'] ?? $unit['amount'] ?? null;

        return new PaymentResponse(
            success: $captured,
            transactionId: $capture['id'] ?? $orderId,
            status: $status,
            data: [
                'order_id' => $orderId,
                'capture_id' => $capture['id'] ?? null,
                'reference_id' => $unit['reference_id'] ?? null,
                'order_status' => $order['status'] ?? null,
                'capture_data' => $order,
            ],
            gatewayReference: $orderId,
            amount: isset($amount['value']) ? (float) $amount['value'] : null,
            currency: $amount['currency_code'] ?? null
        );
    }

    protected function getEndpoint(string $path): string
    {
        $baseUrl = $this->isSandbox()
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        return $baseUrl . '/' . ltrim($path, '/');
    }

    protected function createHttpClient(array $options = []): Client
    {
        $defaultOptions = [
            'base_uri' => $this->getEndpoint(''),
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Accept-Language' => 'en_US',
                'User-Agent' => 'Laravel-Payments/1.0.0',
            ]
        ];

        return new Client(array_merge($defaultOptions, $options));
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
                    'value' => number_format($request->getAmount(), $this->currencyDecimals($request->getCurrency()) === 0 ? 0 : 2, '.', '')
                ],
                'custom_id' => $request->getMetadata()['custom_id'] ?? $request->getTransactionId(),
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
     * Get a PayPal order
     */
    private function getOrder(string $orderId): array
    {
        return $this->makeRequest('GET', "v2/checkout/orders/{$orderId}", [
            'headers' => ['Authorization' => 'Bearer ' . $this->getAccessToken()],
        ]);
    }

    /**
     * Resolve a capture from a capture id or an order id.
     */
    private function findCapture(string $id): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->getAccessToken()];

        try {
            return $this->makeRequest('GET', "v2/payments/captures/{$id}", ['headers' => $headers]);
        } catch (PaymentException $e) {
            $order = $this->getOrder($id);
            $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

            if (!$capture) {
                throw new PaymentException("No capture found for PayPal id {$id}", 0, $e);
            }

            return $capture;
        }
    }

    /**
     * Process refund
     */
    private function processRefund(string $captureId, float $amount, string $currency): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ],
        ];

        if ($amount > 0) {
            $options['json'] = [
                'amount' => [
                    'value' => number_format($amount, $this->currencyDecimals($currency) === 0 ? 0 : 2, '.', ''),
                    'currency_code' => $currency,
                ]
            ];
        } else {
            $options['body'] = '{}';
        }

        return $this->makeRequest('POST', "v2/payments/captures/{$captureId}/refund", $options);
    }

    /**
     * Verify webhook signature via PayPal's verify-webhook-signature API.
     * The original raw event JSON is embedded verbatim, as PayPal requires.
     */
    private function verifyWebhookSignature(string $rawBody, array $headers, string $webhookId): bool
    {
        $fields = [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id' => $webhookId,
        ];

        if (in_array('', $fields, true) || trim($rawBody) === '') {
            return false;
        }

        try {
            $body = substr(json_encode($fields), 0, -1) . ',"webhook_event":' . $rawBody . '}';

            $response = $this->makeRequest('POST', 'v1/notifications/verify-webhook-signature', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
            ]);

            return ($response['verification_status'] ?? null) === 'SUCCESS';
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
    protected function makeRequest(string $method, string $url, array $options = []): array
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