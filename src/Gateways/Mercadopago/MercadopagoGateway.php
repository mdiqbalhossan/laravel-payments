<?php

namespace Mdiqbal\LaravelPayments\Gateways\Mercadopago;

use Exception;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;

class MercadopagoGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'mercadopago';
    }

    protected function getAccessToken(): string
    {
        return $this->getModeConfig('access_token');
    }

    protected function getWebhookSecret(): ?string
    {
        return $this->getModeConfig('webhook_secret');
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $this->validateRequest($request);

        try {
            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                throw new PaymentException('Mercado Pago access token is required');
            }

            // Create preference using direct API call
            $preference = $this->createPreference($request);

            if ($preference && isset($preference['id'])) {
                // Get the appropriate checkout URL based on mode
                $checkoutUrl = $this->getCheckoutUrl($preference['id']);

                $this->log('info', 'Mercado Pago preference created', [
                    'preference_id' => $preference['id'],
                    'transaction_id' => $request->getTransactionId(),
                    'amount' => $request->getAmount()
                ]);

                return PaymentResponse::redirect($checkoutUrl, [
                    'preference_id' => $preference['id'],
                    'init_point' => $preference['init_point'] ?? null,
                    'sandbox_init_point' => $preference['sandbox_init_point'] ?? null,
                    'transaction_id' => $request->getTransactionId(),
                    'message' => 'Redirect to Mercado Pago payment page'
                ]);
            }

            return $this->createErrorResponse('Failed to create payment preference');
        } catch (Exception $e) {
            $this->log('error', 'Payment creation failed', [
                'error' => $e->getMessage(),
                'request_id' => $request->getTransactionId()
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function verify(array $payload): PaymentResponse
    {
        try {
            $paymentId = $payload['data']['id'] ?? null;

            if (!$paymentId) {
                return $this->createErrorResponse('Payment ID not found in webhook payload');
            }

            // Verify webhook signature if configured
            $webhookSecret = $this->getWebhookSecret();
            if ($webhookSecret) {
                $signature = $_SERVER['HTTP_X_MELI_SIGNATURE'] ?? '';
                if (!$this->verifyWebhookSignature($signature, $payload)) {
                    throw new InvalidSignatureException('Invalid webhook signature');
                }
            }

            // Get payment details from Mercado Pago API
            $payment = $this->getPaymentDetails($paymentId);

            if (!$payment) {
                return $this->createErrorResponse('Payment not found');
            }

            // Map Mercado Pago status to standard status
            $status = $this->mapMercadoPagoStatus($payment['status'] ?? 'unknown');
            $success = in_array($status, ['completed', 'authorized']);

            return new PaymentResponse(
                success: $success,
                transactionId: $payment['external_reference'] ?? $paymentId,
                status: $status,
                data: [
                    'transaction_id' => $payment['external_reference'] ?? $paymentId,
                    'payment_id' => $paymentId,
                    'status' => $status,
                    'payment_method_id' => $payment['payment_method_id'] ?? null,
                    'payment_type_id' => $payment['payment_type_id'] ?? null,
                    'currency' => $payment['currency_id'] ?? 'USD',
                    'amount' => (float) ($payment['transaction_amount'] ?? 0),
                    'captured_amount' => (float) ($payment['transaction_details']['total_paid_amount'] ?? 0),
                    'net_amount' => (float) ($payment['transaction_details']['net_amount'] ?? 0),
                    'fee_amount' => (float) (($payment['transaction_details']['total_paid_amount'] ?? 0) - ($payment['transaction_details']['net_amount'] ?? 0)),
                    'payment_method' => $payment['payment_method_id'] ?? null,
                    'card_info' => $this->extractCardInfo($payment),
                    'payer_info' => $this->extractPayerInfo($payment),
                    'merchant_info' => [
                        'mercado_pago_payment_id' => $paymentId,
                        'preference_id' => $payment['preference_id'] ?? null,
                        'status_detail' => $payment['status_detail'] ?? null,
                        'operation_type' => $payment['operation_type'] ?? null,
                        'date_created' => $payment['date_created'] ?? null,
                        'date_approved' => $payment['date_approved'] ?? null,
                    ],
                    'metadata' => $payment['metadata'] ?? []
                ]
            );
        } catch (Exception $e) {
            $this->log('error', 'Payment verification failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function refund(string $transactionId, float $amount): bool
    {
        try {
            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                throw new PaymentException('Mercado Pago access token is required');
            }

            // First, get the payment details to check if refund is possible
            $payment = $this->getPaymentDetails($transactionId);
            if (!$payment) {
                throw new PaymentException('Payment not found');
            }

            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ];

            // Process refund
            if ($amount && $amount < ($payment['transaction_amount'] ?? 0)) {
                // Partial refund
                $refundData = [
                    'amount' => $amount
                ];
                $response = $this->makeRequest('POST', $this->getEndpoint("v1/payments/{$transactionId}/refunds"), [
                    'headers' => $headers,
                    'json' => $refundData
                ]);
            } else {
                // Full refund
                $response = $this->makeRequest('POST', $this->getEndpoint("v1/payments/{$transactionId}/refunds"), [
                    'headers' => $headers
                ]);
            }

            $this->log('info', 'Refund processed', [
                'payment_id' => $transactionId,
                'amount' => $amount,
                'refund_id' => $response['id'] ?? null
            ]);

            return true;
        } catch (Exception $e) {
            $this->log('error', 'Refund failed', [
                'payment_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function getTransactionStatus(string $transactionId): PaymentResponse
    {
        try {
            $payment = $this->getPaymentDetails($transactionId);

            if (!$payment) {
                return $this->createErrorResponse('Payment not found');
            }

            $status = $this->mapMercadoPagoStatus($payment['status'] ?? 'unknown');
            $success = in_array($status, ['completed', 'authorized']);

            return new PaymentResponse(
                success: $success,
                transactionId: $payment['external_reference'] ?? $transactionId,
                status: $status,
                data: $payment
            );
        } catch (Exception $e) {
            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function getSupportedCurrencies(): array
    {
        return [
            'ARS', // Argentine Peso
            'BRL', // Brazilian Real
            'CLP', // Chilean Peso
            'COP', // Colombian Peso
            'MXN', // Mexican Peso
            'PEN', // Peruvian Sol
            'UYU', // Uruguayan Peso
            'USD', // US Dollar
            'EUR', // Euro (limited)
        ];
    }

    protected function createPreference(PaymentRequest $request): ?array
    {
        try {
            $headers = [
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
            ];

            // Build items array
            $items = [[
                'title' => $request->getDescription() ?? 'Payment',
                'quantity' => 1,
                'currency_id' => $request->getCurrency(),
                'unit_price' => $request->getAmount()
            ]];

            // Prepare preference data
            $preferenceData = [
                'items' => $items,
                'external_reference' => $request->getTransactionId(),
                'payer' => $this->buildPayerData($request),
                'back_urls' => [
                    'success' => $request->getRedirectUrl(),
                    'failure' => $request->getRedirectUrl(),
                    'pending' => $request->getRedirectUrl()
                ],
                'auto_return' => 'approved',
                'payment_methods' => [
                    'excluded_payment_methods' => [],
                    'excluded_payment_types' => [],
                    'installments' => null
                ],
                'metadata' => $request->getMetadata() ?? []
            ];

            // Create preference using API
            $response = $this->makeRequest('POST', $this->getEndpoint('checkout/preferences'), [
                'headers' => $headers,
                'json' => $preferenceData
            ]);

            return [
                'id' => $response['id'],
                'init_point' => $response['init_point'] ?? null,
                'sandbox_init_point' => $response['sandbox_init_point'] ?? null,
            ];
        } catch (Exception $e) {
            $this->log('error', 'Failed to create preference', [
                'error' => $e->getMessage(),
                'request_data' => $request->toArray()
            ]);
            return null;
        }
    }

    protected function buildPayerData(PaymentRequest $request): array
    {
        $payer = [];

        if ($request->getEmail()) {
            $payer['email'] = $request->getEmail();
        }

        if ($request->getCustomer()) {
            $customer = $request->getCustomer();
            if (isset($customer['name'])) {
                $nameParts = explode(' ', $customer['name'], 2);
                $payer['name'] = $customer['name'];
                $payer['first_name'] = $nameParts[0] ?? null;
                $payer['last_name'] = $nameParts[1] ?? null;
            }

            if (isset($customer['phone'])) {
                $payer['phone'] = [
                    'area_code' => '',
                    'number' => $customer['phone']
                ];
            }

            if (isset($customer['address'])) {
                $payer['address'] = [
                    'zip_code' => $customer['postal_code'] ?? '',
                    'street_name' => $customer['address'] ?? '',
                    'street_number' => $customer['address_number'] ?? ''
                ];
            }

            if (isset($customer['identification'])) {
                $payer['identification'] = $customer['identification'];
            }
        }

        return $payer;
    }

    protected function getCheckoutUrl(string $preferenceId): string
    {
        if ($this->isSandbox()) {
            return "https://sandbox.mercadopago.com.ar/checkout?preference_id={$preferenceId}";
        }

        // Production checkout URLs for different countries
        $country = $this->getConfig('country', 'MX');
        $checkoutUrls = [
            'AR' => 'https://www.mercadopago.com.ar/checkout',
            'BR' => 'https://www.mercadopago.com.br/checkout',
            'CL' => 'https://www.mercadopago.cl/checkout',
            'CO' => 'https://www.mercadopago.com.co/checkout',
            'MX' => 'https://www.mercadopago.com.mx/checkout',
            'PE' => 'https://www.mercadopago.com.pe/checkout',
            'UY' => 'https://www.mercadopago.com.uy/checkout',
        ];

        $baseUrl = $checkoutUrls[$country] ?? $checkoutUrls['MX'];
        return "{$baseUrl}?preference_id={$preferenceId}";
    }

    protected function getPaymentDetails(string $paymentId): ?array
    {
        try {
            $headers = [
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
            ];

            return $this->makeRequest('GET', $this->getEndpoint("v1/payments/{$paymentId}"), [
                'headers' => $headers
            ]);
        } catch (Exception $e) {
            $this->log('error', 'Failed to get payment details', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function verifyWebhookSignature(string $signature, array $payload): bool
    {
        if (empty($signature) || empty($payload)) {
            return false;
        }

        // Extract the signature parts
        $parts = explode(',', $signature);
        $signatureData = [];

        foreach ($parts as $part) {
            [$key, $value] = explode('=', $part, 2);
            $signatureData[$key] = $value;
        }

        if (!isset($signatureData['ts'], $signatureData['v1'])) {
            return false;
        }

        // Verify timestamp (prevent replay attacks - webhook must be within 5 minutes)
        $timestamp = (int) $signatureData['ts'];
        $currentTime = time();
        if (abs($currentTime - $timestamp) > 300) {
            return false;
        }

        // Generate expected signature
        $secret = $this->getWebhookSecret();
        $payloadId = $payload['id'] ?? '';
        $expectedSignature = hash_hmac('sha256', $payloadId . $timestamp, $secret);

        return hash_equals($expectedSignature, $signatureData['v1']);
    }

    protected function extractCardInfo(array $payment): ?array
    {
        if (!isset($payment['card'])) {
            return null;
        }

        return [
            'id' => $payment['card']['id'] ?? null,
            'last_four_digits' => $payment['card']['last_four_digits'] ?? null,
            'expiration_month' => $payment['card']['expiration_month'] ?? null,
            'expiration_year' => $payment['card']['expiration_year'] ?? null,
            'cardholder' => [
                'name' => $payment['card']['cardholder']['name'] ?? null,
                'identification' => $payment['card']['cardholder']['identification'] ?? null
            ]
        ];
    }

    protected function extractPayerInfo(array $payment): array
    {
        return [
            'id' => $payment['payer']['id'] ?? null,
            'email' => $payment['payer']['email'] ?? null,
            'name' => $payment['payer']['name'] ?? null,
            'first_name' => $payment['payer']['first_name'] ?? null,
            'last_name' => $payment['payer']['last_name'] ?? null,
            'phone' => [
                'area_code' => $payment['payer']['phone']['area_code'] ?? null,
                'number' => $payment['payer']['phone']['number'] ?? null
            ],
            'identification' => [
                'type' => $payment['payer']['identification']['type'] ?? null,
                'number' => $payment['payer']['identification']['number'] ?? null
            ]
        ];
    }

    protected function mapMercadoPagoStatus(string $status): string
    {
        $statusMap = [
            'pending' => 'pending',
            'approved' => 'completed',
            'authorized' => 'authorized',
            'in_process' => 'processing',
            'in_mediation' => 'disputed',
            'rejected' => 'failed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'charged_back' => 'reversed',
        ];

        return $statusMap[$status] ?? 'unknown';
    }

    protected function validateRequest(PaymentRequest $request): void
    {
        if ($request->getAmount() <= 0) {
            throw new PaymentException('Payment amount must be greater than 0');
        }

        if (empty($request->getTransactionId())) {
            throw new PaymentException('Transaction ID is required');
        }

        if (empty($request->getEmail())) {
            throw new PaymentException('Customer email is required');
        }

        // Validate currency
        $supportedCurrencies = $this->getSupportedCurrencies();
        if (!in_array($request->getCurrency(), $supportedCurrencies)) {
            throw new PaymentException("Currency {$request->getCurrency()} is not supported by Mercado Pago");
        }
    }

    protected function getEndpoint(string $path): string
    {
        // Mercado Pago uses the same API URL for both sandbox and production
        // The mode is determined by the access token
        return 'https://api.mercadopago.com/' . ltrim($path, '/');
    }

    protected function createErrorResponse(string $message): PaymentResponse
    {
        return PaymentResponse::error($message, 400, [
            'gateway' => 'mercadopago',
            'timestamp' => time()
        ]);
    }

    public function parseCallback($request): array
    {
        return [
            'type' => $request->input('type'),
            'data' => $request->input('data'),
            'id' => $request->input('id'),
            'topic' => $request->input('topic'),
            'resource' => $request->input('resource'),
            'raw_body' => $request->getContent(),
            'headers' => $request->headers->all()
        ];
    }

    public function getGatewayConfig(): array
    {
        return [
            'access_token' => $this->getAccessToken(),
            'test_mode' => $this->isSandbox(),
            'country' => $this->getConfig('country', 'MX'),
            'webhook_secret' => $this->getWebhookSecret()
        ];
    }
}