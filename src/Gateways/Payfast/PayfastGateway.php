<?php

namespace Mdiqbal\LaravelPayments\Gateways\Payfast;

use Exception;
use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;

class PayfastGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'payfast';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $this->validateRequest($request);

        try {
            // Generate payment data
            $paymentData = $this->buildPaymentData($request);

            // Generate signature
            $signature = $this->generateSignature($paymentData);

            // Add signature to payment data
            $paymentData['signature'] = $signature;

            // Get the PayFast payment URL
            $paymentUrl = $this->getPaymentUrl();

            return PaymentResponse::redirect($paymentUrl, [
                'm_payment_id' => $paymentData['m_payment_id'],
                'amount' => $paymentData['amount'],
                'item_name' => $paymentData['item_name'],
                'signature' => $signature,
                'transaction_id' => $request->getTransactionId(),
                'payment_data' => $paymentData,
                'message' => 'Redirect to PayFast payment page'
            ], 'POST');
        } catch (Exception $e) {
            $this->logError('Payment creation failed', [
                'error' => $e->getMessage(),
                'request_id' => $request->getTransactionId()
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function verify(array $payload): PaymentResponse
    {
        try {
            $mPaymentId = $payload['m_payment_id'] ?? null;

            if (!$mPaymentId) {
                return $this->createErrorResponse('Payment ID not found in webhook payload');
            }

            // Verify webhook signature first
            if (!$this->verifyWebhookSignature($payload)) {
                return $this->createErrorResponse('Invalid webhook signature');
            }

            // Process the webhook event
            $webhookResult = $this->processWebhook($payload);

            if ($webhookResult['success']) {
                return PaymentResponse::success([
                    'transaction_id' => $payload['m_payment_id'] ?? $mPaymentId,
                    'm_payment_id' => $mPaymentId,
                    'status' => $this->mapPayfastStatus($payload['payment_status'] ?? 'unknown'),
                    'amount' => (float) ($payload['amount_gross'] ?? 0),
                    'currency' => 'ZAR', // PayFast only supports South African Rand
                    'fee_amount' => (float) ($payload['amount_fee'] ?? 0),
                    'net_amount' => (float) ($payload['amount_net'] ?? 0),
                    'payment_method' => $payload['payment_method'] ?? null,
                    'pf_payment_id' => $payload['pf_payment_id'] ?? null,
                    'merchant_info' => [
                        'payfast_payment_id' => $payload['pf_payment_id'] ?? null,
                        'merchant_id' => $payload['merchant_id'] ?? null,
                        'name_first' => $payload['name_first'] ?? null,
                        'name_last' => $payload['name_last'] ?? null,
                        'email_address' => $payload['email_address'] ?? null,
                        'custom_str1' => $payload['custom_str1'] ?? null,
                        'custom_str2' => $payload['custom_str2'] ?? null,
                        'custom_str3' => $payload['custom_str3'] ?? null,
                        'custom_str4' => $payload['custom_str4'] ?? null,
                        'custom_str5' => $payload['custom_str5'] ?? null,
                        'custom_int1' => $payload['custom_int1'] ?? null,
                        'custom_int2' => $payload['custom_int2'] ?? null,
                        'custom_int3' => $payload['custom_int3'] ?? null,
                        'custom_int4' => $payload['custom_int4'] ?? null,
                        'custom_int5' => $payload['custom_int5'] ?? null,
                    ],
                    'metadata' => [
                        'signature' => $payload['signature'] ?? null,
                        'token' => $payload['token'] ?? null,
                        'billing_date' => $payload['billing_date'] ?? null,
                    ]
                ]);
            }

            return $this->createErrorResponse('Webhook processing failed');
        } catch (Exception $e) {
            $this->logError('Payment verification failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function verifyTransaction(string $mPaymentId): PaymentResponse
    {
        try {
            if (empty($mPaymentId)) {
                return $this->createErrorResponse('Payment ID is required');
            }

            // Query PayFast for transaction status
            $response = Http::post($this->getEndpoint('process'), [
                'm_payment_id' => $mPaymentId,
                'merchant_id' => $this->config->getMerchantId(),
                'signature' => $this->generateQuerySignature($mPaymentId),
            ]);

            if (!$response->successful()) {
                return $this->createErrorResponse('Failed to query transaction status');
            }

            $result = $response->json();

            if (isset($result['status']) && $result['status'] === 'success') {
                return PaymentResponse::success([
                    'transaction_id' => $result['data']['m_payment_id'] ?? $mPaymentId,
                    'm_payment_id' => $mPaymentId,
                    'status' => $this->mapPayfastStatus($result['data']['payment_status'] ?? 'unknown'),
                    'amount' => (float) ($result['data']['amount_gross'] ?? 0),
                    'currency' => 'ZAR',
                    'fee_amount' => (float) ($result['data']['amount_fee'] ?? 0),
                    'net_amount' => (float) ($result['data']['amount_net'] ?? 0),
                    'payment_method' => $result['data']['payment_method'] ?? null,
                    'pf_payment_id' => $result['data']['pf_payment_id'] ?? null,
                    'merchant_info' => [
                        'payfast_payment_id' => $result['data']['pf_payment_id'] ?? null,
                    ]
                ]);
            }

            return $this->createErrorResponse('Transaction not found or query failed');
        } catch (Exception $e) {
            $this->logError('Payment verification failed', [
                'error' => $e->getMessage(),
                'm_payment_id' => $mPaymentId
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function refund(string $transactionId, float $amount): bool
    {
        try {
            // PayFast requires additional data for refunds that isn't available in the standard signature
            // In a real implementation, you would need to store the token during payment processing
            // For now, we'll provide a basic implementation that logs the request

            // This is a simplified implementation - PayFast refunds typically require:
            // 1. The m_payment_id (transactionId)
            // 2. A valid token (stored during payment)
            // 3. Merchant credentials

            // Since we can't access the token in this simplified signature,
            // we'll need to implement a different approach or extend the interface

            // For now, we'll return false indicating refunds need additional setup
            Log::error('PayFast refund requires token and additional setup', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'note' => 'Refund functionality requires storing payment tokens during initial transaction'
            ]);

            return false;
        } catch (Exception $e) {
            $this->logError('Refund processing failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'amount' => $amount
            ]);

            return false;
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function getTransactionStatus(string $mPaymentId): PaymentResponse
    {
        return $this->verifyTransaction($mPaymentId);
    }

    public function searchTransactions(array $filters = []): PaymentResponse
    {
        try {
            // PayFast doesn't have a direct search API
            // This would typically require their Merchant API or subscription services
            return $this->createErrorResponse('Transaction search not available with basic PayFast integration');
        } catch (Exception $e) {
            $this->logError('Transaction search failed', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function getSupportedCurrencies(): array
    {
        return ['ZAR']; // PayFast only supports South African Rand
    }

    public function getPaymentMethodsForCountry(string $countryCode): array
    {
        return $this->config->getPaymentMethods($countryCode) ?? [];
    }

    protected function buildPaymentData(PaymentRequest $request): array
    {
        // PayFast required fields
        $paymentData = [
            'merchant_id' => $this->config->getMerchantId(),
            'merchant_key' => $this->config->getMerchantKey(),
            'return_url' => $request->getRedirectUrl(),
            'cancel_url' => $request->getRedirectUrl(),
            'notify_url' => $this->config->getWebhookUrl(),
            'm_payment_id' => $request->getTransactionId(),
            'amount' => number_format($request->getAmount(), 2, '.', ''),
            'item_name' => $request->getDescription() ?? 'Payment',
            'item_description' => $request->getDescription() ?? '',
            'email_address' => $request->getEmail(),
            'custom_int1' => null,
            'custom_int2' => null,
            'custom_int3' => null,
            'custom_int4' => null,
            'custom_int5' => null,
            'custom_str1' => null,
            'custom_str2' => null,
            'custom_str3' => null,
            'custom_str4' => null,
            'custom_str5' => null,
        ];

        // Add customer details if available
        if ($request->getCustomer()) {
            $customer = $request->getCustomer();
            $paymentData['name_first'] = explode(' ', $customer['name'])[0] ?? '';
            $paymentData['name_last'] = explode(' ', $customer['name'], 2)[1] ?? '';
            $paymentData['cell_number'] = $customer['phone'] ?? null;
        }

        // Add custom fields from metadata
        if ($request->getMetadata()) {
            $metadata = $request->getMetadata();

            // Handle custom integer fields
            for ($i = 1; $i <= 5; $i++) {
                $key = "custom_int{$i}";
                if (isset($metadata[$key])) {
                    $paymentData[$key] = (int) $metadata[$key];
                }
            }

            // Handle custom string fields
            for ($i = 1; $i <= 5; $i++) {
                $key = "custom_str{$i}";
                if (isset($metadata[$key])) {
                    $paymentData[$key] = (string) $metadata[$key];
                }
            }
        }

        return $paymentData;
    }

    protected function generateSignature(array $data): string
    {
        // Remove signature if present
        unset($data['signature']);

        // Sort the data alphabetically
        ksort($data);

        // Create the query string
        $queryString = '';
        foreach ($data as $key => $value) {
            if ($value !== null && $value !== '') {
                $queryString .= urlencode($key) . '=' . urlencode($value) . '&';
            }
        }
        $queryString = rtrim($queryString, '&');

        // Generate MD5 hash with the passphrase
        $passPhrase = $this->config->getPassPhrase();
        if ($passPhrase) {
            $queryString .= '&passphrase=' . urlencode($passPhrase);
        }

        return md5($queryString);
    }

    protected function generateQuerySignature(string $mPaymentId): string
    {
        $data = [
            'merchant_id' => $this->config->getMerchantId(),
            'm_payment_id' => $mPaymentId,
        ];

        $passPhrase = $this->config->getPassPhrase();
        if ($passPhrase) {
            $data['passphrase'] = $passPhrase;
        }

        ksort($data);
        $queryString = http_build_query($data);

        return md5($queryString);
    }

    protected function generateRefundSignature(array $data): string
    {
        // Add passphrase if available
        $passPhrase = $this->config->getPassPhrase();
        if ($passPhrase) {
            $data['passphrase'] = $passPhrase;
        }

        ksort($data);
        $queryString = http_build_query($data);

        return md5($queryString);
    }

    protected function getPaymentUrl(): string
    {
        return $this->getEndpoint('process');
    }

    protected function processWebhook(array $payload): array
    {
        try {
            $status = $payload['payment_status'] ?? '';

            switch ($status) {
                case 'COMPLETE':
                    return [
                        'success' => true,
                        'event_type' => 'payment.completed',
                        'status' => 'completed'
                    ];

                case 'PENDING':
                    return [
                        'success' => true,
                        'event_type' => 'payment.pending',
                        'status' => 'pending'
                    ];

                case 'FAILED':
                    return [
                        'success' => true,
                        'event_type' => 'payment.failed',
                        'status' => 'failed'
                    ];

                case 'CANCELLED':
                    return [
                        'success' => true,
                        'event_type' => 'payment.cancelled',
                        'status' => 'cancelled'
                    ];

                default:
                    return [
                        'success' => false,
                        'error' => 'Unknown payment status: ' . $status
                    ];
            }
        } catch (Exception $e) {
            $this->logError('Webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    protected function verifyWebhookSignature(array $payload): array
    {
        try {
            // Get the signature from payload
            $signature = $payload['signature'] ?? '';

            if (empty($signature)) {
                return ['success' => false, 'error' => 'Missing signature'];
            }

            // Prepare data for signature verification
            $verificationData = $payload;
            unset($verificationData['signature']);

            // Add passphrase if available
            $passPhrase = $this->config->getPassPhrase();
            if ($passPhrase) {
                $verificationData['passphrase'] = $passPhrase;
            }

            // Generate expected signature
            ksort($verificationData);
            $queryString = http_build_query($verificationData);
            $expectedSignature = md5($queryString);

            // Compare signatures
            if (hash_equals($expectedSignature, $signature)) {
                return ['success' => true];
            }

            return ['success' => false, 'error' => 'Signature mismatch'];
        } catch (Exception $e) {
            $this->logError('Webhook signature verification failed', [
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function mapPayfastStatus(string $status): string
    {
        $statusMap = [
            'COMPLETE' => 'completed',
            'PENDING' => 'pending',
            'FAILED' => 'failed',
            'CANCELLED' => 'cancelled',
            'UNKNOWN' => 'unknown',
        ];

        return $statusMap[$status] ?? 'unknown';
    }

    protected function validateRequest(PaymentRequest $request): void
    {
        if ($request->getAmount() <= 0) {
            throw new Exception('Payment amount must be greater than 0');
        }

        if (empty($request->getTransactionId())) {
            throw new Exception('Transaction ID is required');
        }

        if (empty($request->getEmail())) {
            throw new Exception('Customer email is required');
        }

        // PayFast only supports ZAR
        if ($request->getCurrency() !== 'ZAR') {
            throw new Exception("Currency {$request->getCurrency()} is not supported. PayFast only supports ZAR (South African Rand).");
        }
    }

    protected function getEndpoint(string $path): string
    {
        $baseUrl = $this->isSandbox()
            ? 'https://sandbox.payfast.co.za'
            : 'https://www.payfast.co.za';

        return $baseUrl . '/' . ltrim($path, '/');
    }

    protected function createErrorResponse(string $message): PaymentResponse
    {
        return PaymentResponse::error($message, 400, [
            'gateway' => 'payfast',
            'timestamp' => time()
        ]);
    }

    public function parseCallback($request): array
    {
        return [
            'm_payment_id' => $request->input('m_payment_id'),
            'pf_payment_id' => $request->input('pf_payment_id'),
            'payment_status' => $request->input('payment_status'),
            'item_name' => $request->input('item_name'),
            'item_description' => $request->input('item_description'),
            'amount_gross' => $request->input('amount_gross'),
            'amount_fee' => $request->input('amount_fee'),
            'amount_net' => $request->input('amount_net'),
            'custom_str1' => $request->input('custom_str1'),
            'custom_str2' => $request->input('custom_str2'),
            'custom_str3' => $request->input('custom_str3'),
            'custom_str4' => $request->input('custom_str4'),
            'custom_str5' => $request->input('custom_str5'),
            'custom_int1' => $request->input('custom_int1'),
            'custom_int2' => $request->input('custom_int2'),
            'custom_int3' => $request->input('custom_int3'),
            'custom_int4' => $request->input('custom_int4'),
            'custom_int5' => $request->input('custom_int5'),
            'name_first' => $request->input('name_first'),
            'name_last' => $request->input('name_last'),
            'email_address' => $request->input('email_address'),
            'merchant_id' => $request->input('merchant_id'),
            'signature' => $request->input('signature'),
            'raw_body' => $request->getContent(),
            'headers' => $request->headers->all()
        ];
    }

    public function getGatewayConfig(): array
    {
        return [
            'merchant_id' => $this->config->getMerchantId(),
            'merchant_key' => $this->config->getMerchantKey(),
            'passphrase' => $this->config->getPassPhrase(),
            'test_mode' => $this->isSandbox(),
            'country' => $this->config->getCountry(),
            'webhook_url' => $this->config->getWebhookUrl()
        ];
    }

    public function createSubscription(array $subscriptionData): PaymentResponse
    {
        try {
            // PayFast supports recurring billing
            $subscriptionData['subscription_type'] = '1'; // Recurring
            $subscriptionData['billing_date'] = date('Y-m-d');
            $subscriptionData['frequency'] = $subscriptionData['frequency'] ?? '3'; // Monthly
            $subscriptionData['cycles'] = $subscriptionData['cycles'] ?? '0'; // Unlimited

            // Create subscription payment
            return $this->pay(new PaymentRequest([
                'amount' => $subscriptionData['amount'],
                'currency' => 'ZAR',
                'email' => $subscriptionData['email'],
                'transaction_id' => 'SUB_' . time(),
                'description' => $subscriptionData['description'] ?? 'Subscription',
                'redirect_url' => $subscriptionData['return_url'] ?? null,
                'metadata' => $subscriptionData
            ]));
        } catch (Exception $e) {
            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function cancelSubscription(string $mPaymentId): PaymentResponse
    {
        try {
            // Cancel subscription via API
            $response = Http::post($this->getEndpoint('eng/query/cancel'), [
                'merchant_id' => $this->config->getMerchantId(),
                'm_payment_id' => $mPaymentId,
                'signature' => $this->generateQuerySignature($mPaymentId),
            ]);

            if ($response->successful()) {
                return PaymentResponse::success([
                    'subscription_id' => $mPaymentId,
                    'status' => 'cancelled'
                ]);
            }

            return $this->createErrorResponse('Failed to cancel subscription');
        } catch (Exception $e) {
            return $this->createErrorResponse($e->getMessage());
        }
    }
}