<?php

namespace Mdiqbal\LaravelPayments\Gateways\Phonepe;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;

class PhonePeGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'phonepe';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $this->validateRequest($request);

        try {
            // Generate X-VERIFY header and payment data
            $paymentData = $this->buildPaymentData($request);
            $xVerify = $this->generateXVerify($paymentData);

            // Make API request to initiate payment
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-VERIFY' => $xVerify,
                'X-MERCHANT-ID' => $this->config->getMerchantId(),
            ])->post($this->getEndpoint('pg/v1/pay'), $paymentData);

            if (!$response->successful()) {
                $this->logError('Payment initiation failed', [
                    'response' => $response->body(),
                    'status' => $response->status(),
                    'request_id' => $request->getTransactionId()
                ]);

                return $this->createErrorResponse('Failed to initiate payment with PhonePe');
            }

            $result = $response->json();

            if (isset($result['success']) && $result['success']) {
                $paymentUrl = $result['data']['instrumentResponse']['redirectInfo']['url'] ?? null;

                return PaymentResponse::redirect($paymentUrl, [
                    'merchant_transaction_id' => $result['data']['merchantTransactionId'],
                    'transaction_id' => $request->getTransactionId(),
                    'phonepe_transaction_id' => $result['data']['transactionId'],
                    'payment_instrument' => $result['data']['instrumentResponse'] ?? null,
                    'message' => 'Redirect to PhonePe payment page'
                ]);
            }

            return $this->createErrorResponse($result['message'] ?? 'Payment initiation failed');
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
            $merchantTransactionId = $payload['merchantTransactionId'] ?? null;

            if (!$merchantTransactionId) {
                return $this->createErrorResponse('Merchant Transaction ID not found in webhook payload');
            }

            // Verify webhook signature
            if (!$this->verifyWebhookSignature($payload)) {
                return $this->createErrorResponse('Invalid webhook signature');
            }

            // Process the webhook event
            $webhookResult = $this->processWebhook($payload);

            if ($webhookResult['success']) {
                return PaymentResponse::success([
                    'transaction_id' => $merchantTransactionId,
                    'merchant_transaction_id' => $merchantTransactionId,
                    'phonepe_transaction_id' => $payload['transactionId'] ?? null,
                    'status' => $this->mapPhonePeStatus($payload['code'] ?? 'unknown'),
                    'payment_method' => $this->getPaymentMethodFromResponse($payload),
                    'amount' => (float) ($payload['amount'] ?? 0),
                    'currency' => 'INR', // PhonePe only supports INR
                    'customer_info' => [
                        'mobile_number' => $payload['paymentInstrument']['type'] === 'UPI' ? $payload['paymentInstrument']['upi']['vpa'] : null,
                        'payer_name' => $payload['payer']['name'] ?? null,
                        'payer_email' => null, // PhonePe doesn't provide email in webhooks
                    ],
                    'merchant_info' => [
                        'phonepe_transaction_id' => $payload['transactionId'] ?? null,
                        'merchant_id' => $payload['merchantId'] ?? null,
                        'provider_reference_id' => $payload['providerReferenceId'] ?? null,
                        'payment_instrument' => $payload['paymentInstrument'] ?? null,
                        'payment_mode' => $payload['paymentMode'] ?? null,
                    ],
                    'metadata' => [
                        'auth_date' => $payload['authDate'] ?? null,
                        'response_code' => $payload['responseCode'] ?? null,
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

    public function verify(string $merchantTransactionId): PaymentResponse
    {
        try {
            if (empty($merchantTransactionId)) {
                return $this->createErrorResponse('Merchant Transaction ID is required');
            }

            // Check transaction status using PhonePe API
            $checkStatusData = [
                'merchantId' => $this->config->getMerchantId(),
                'merchantTransactionId' => $merchantTransactionId,
            ];

            $xVerify = $this->generateStatusCheckXVerify($checkStatusData);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-VERIFY' => $xVerify,
                'X-MERCHANT-ID' => $this->config->getMerchantId(),
            ])->post($this->getEndpoint('pg/v1/status'), $checkStatusData);

            if (!$response->successful()) {
                return $this->createErrorResponse('Failed to check payment status');
            }

            $result = $response->json();

            if (isset($result['success']) && $result['success']) {
                return PaymentResponse::success([
                    'transaction_id' => $merchantTransactionId,
                    'merchant_transaction_id' => $merchantTransactionId,
                    'phonepe_transaction_id' => $result['data']['transactionId'] ?? null,
                    'status' => $this->mapPhonePeStatus($result['data']['code'] ?? 'unknown'),
                    'payment_method' => $this->getPaymentMethodFromResponse($result['data']),
                    'amount' => (float) ($result['data']['amount'] ?? 0),
                    'currency' => 'INR',
                    'customer_info' => [
                        'mobile_number' => $this->getMobileNumberFromResponse($result['data']),
                        'payer_name' => $result['data']['payer']['name'] ?? null,
                    ],
                    'merchant_info' => [
                        'phonepe_transaction_id' => $result['data']['transactionId'] ?? null,
                        'merchant_id' => $result['data']['merchantId'] ?? null,
                        'provider_reference_id' => $result['data']['providerReferenceId'] ?? null,
                        'payment_instrument' => $result['data']['paymentInstrument'] ?? null,
                    ],
                    'metadata' => [
                        'auth_date' => $result['data']['authDate'] ?? null,
                        'response_code' => $result['data']['responseCode'] ?? null,
                        'pay_response_code' => $result['data']['payResponseCode'] ?? null,
                    ]
                ]);
            }

            return $this->createErrorResponse('Transaction not found or query failed');
        } catch (Exception $e) {
            $this->logError('Payment verification failed', [
                'error' => $e->getMessage(),
                'merchant_transaction_id' => $merchantTransactionId
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function refund(array $data): PaymentResponse
    {
        try {
            $merchantTransactionId = $data['merchant_transaction_id'];
            $refundAmount = $data['amount'] ?? null;
            $refundReason = $data['reason'] ?? 'Refund requested';

            if (empty($merchantTransactionId)) {
                return $this->createErrorResponse('Merchant Transaction ID is required');
            }

            // Get original transaction details first
            $originalTransaction = $this->verify($merchantTransactionId);

            if (!$originalTransaction['success']) {
                return $this->createErrorResponse('Original transaction not found');
            }

            // Prepare refund request
            $refundData = [
                'merchantId' => $this->config->getMerchantId(),
                'merchantUserId' => $this->config->getMerchantUserId(),
                'merchantTransactionId' => $merchantTransactionId,
                'originalTransactionId' => $originalTransaction['phonepe_transaction_id'],
                'refundAmount' => $refundAmount,
                'refundedAmount' => $refundAmount,
            ];

            // Generate X-VERIFY for refund
            $xVerify = $this->generateRefundXVerify($refundData);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-VERIFY' => $xVerify,
                'X-MERCHANT-ID' => $this->config->getMerchantId(),
            ])->post($this->getEndpoint('pg/v1/refund'), $refundData);

            if (!$response->successful()) {
                return $this->createErrorResponse('Refund request failed');
            }

            $result = $response->json();

            if (isset($result['success']) && $result['success']) {
                return PaymentResponse::success([
                    'refund_id' => $result['data']['refundTransactionId'] ?? 'REF-' . time(),
                    'merchant_transaction_id' => $merchantTransactionId,
                    'amount_refunded' => (float) ($result['data']['refundAmount'] ?? $refundAmount),
                    'currency' => 'INR',
                    'reason' => $refundReason,
                    'status' => 'refunded',
                    'merchant_info' => [
                        'phonepe_refund_transaction_id' => $result['data']['refundTransactionId'] ?? null,
                        'merchant_transaction_id' => $merchantTransactionId,
                        'original_transaction_id' => $result['data']['originalTransactionId'] ?? null,
                    ]
                ]);
            }

            return $this->createErrorResponse('Refund processing failed: ' . ($result['message'] ?? 'Unknown error'));
        } catch (Exception $e) {
            $this->logError('Refund processing failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function getTransactionStatus(string $merchantTransactionId): PaymentResponse
    {
        return $this->verify($merchantTransactionId);
    }

    public function searchTransactions(array $filters = []): PaymentResponse
    {
        try {
            // PhonePe doesn't provide a direct search API with this package
            // This would typically require additional PhonePe services
            return $this->createErrorResponse('Transaction search not available with current PhonePe integration');
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
        return ['INR']; // PhonePe only supports Indian Rupee
    }

    public function getPaymentMethodsForCountry(string $countryCode): array
    {
        return $this->config->getPaymentMethods($countryCode) ?? [];
    }

    protected function buildPaymentData(PaymentRequest $request): array
    {
        return [
            'merchantId' => $this->config->getMerchantId(),
            'merchantTransactionId' => $request->getTransactionId(),
            'merchantUserId' => $this->config->getMerchantUserId(),
            'amount' => (int) round($request->getAmount() * 100), // Convert to paise
            'redirectUrl' => $request->getRedirectUrl(),
            'redirectMode' => 'POST',
            'callbackUrl' => $this->config->getWebhookUrl(),
            'mobileNumber' => $this->getMobileNumberFromRequest($request),
            'email' => $request->getEmail(),
            'shortName' => $this->getShortNameFromRequest($request),
            'requestType' => 'PAY',
            'paymentInstrument' => [
                'type' => 'PAY_PAGE'
            ]
        ];
    }

    protected function generateXVerify(array $data): string
    {
        $stringToEncode = $this->config->getMerchantId() . '|' .
                           ($data['merchantTransactionId'] ?? '') . '|' .
                           ($data['merchantUserId'] ?? '') . '|' .
                           ($data['amount'] ?? '') . '|' .
                           ($data['redirectUrl'] ?? '') . '|' .
                           ($data['redirectMode'] ?? '') . '|' .
                           ($data['callbackUrl'] ?? '') . '|' .
                           ($data['paymentInstrument']['type'] ?? '') . '|' .
                           ($data['requestType'] ?? '');

        $hash = hash('sha256', $stringToEncode, false);
        $salt = $this->config->getSaltKey();

        return $hash . '###' . $salt;
    }

    protected function generateStatusCheckXVerify(array $data): string
    {
        $stringToEncode = '/pg/v1/status' . '|' .
                           ($data['merchantId'] ?? '') . '|' .
                           ($data['merchantTransactionId'] ?? '') . '|' .
                           ($data['merchantUserId'] ?? '') . '|' .
                           ($data['requestType'] ?? '');

        $hash = hash('sha256', $stringToEncode, false);
        $salt = $this->config->getSaltKey();

        return $hash . '###' . $salt;
    }

    protected function generateRefundXVerify(array $data): string
    {
        $stringToEncode = '/pg/v1/refund' . '|' .
                           ($data['merchantId'] ?? '') . '|' .
                           ($data['merchantUserId'] ?? '') . '|' .
                           ($data['merchantTransactionId'] ?? '') . '|' .
                           ($data['originalTransactionId'] ?? '') . '|' .
                           ($data['refundAmount'] ?? '') . '|' .
                           ($data['refundedAmount'] ?? '');

        $hash = hash('sha256', $stringToEncode, false);
        $salt = $this->config->getSaltKey();

        return $hash . '###' . $salt;
    }

    protected function generateWebhookXVerify(string $webhookData): string
    {
        $hash = hash('sha256', $webhookData, false);
        $salt = $this->config->getSaltKey();

        return $hash . '###' . $salt;
    }

    protected function verifyWebhookSignature(array $payload): bool
    {
        try {
            $xVerifyHeader = $_SERVER['HTTP_X_VERIFY'] ?? '';
            $webhookData = json_encode($payload);

            if (empty($xVerifyHeader)) {
                return false;
            }

            $expectedXVerify = $this->generateWebhookXVerify($webhookData);

            return hash_equals($expectedXVerify, $xVerifyHeader);
        } catch (Exception $e) {
            $this->logError('Webhook signature verification failed', [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    protected function processWebhook(array $payload): array
    {
        try {
            $code = $payload['code'] ?? '';

            switch ($code) {
                case 'PAYMENT_SUCCESS':
                    return [
                        'success' => true,
                        'event_type' => 'payment.completed',
                        'status' => 'completed'
                    ];

                case 'PAYMENT_PENDING':
                    return [
                        'success' => true,
                        'event_type' => 'payment.pending',
                        'status' => 'pending'
                    ];

                case 'PAYMENT_ERROR':
                case 'AUTHORIZATION_FAILED':
                    return [
                        'success' => true,
                        'event_type' => 'payment.failed',
                        'status' => 'failed'
                    ];

                case 'TXN_SUCCESS':
                    return [
                        'success' => true,
                        'event_type' => 'payment.completed',
                        'status' => 'completed'
                    ];

                case 'TXN_FAILED':
                    return [
                        'success' => true,
                        'event_type' => 'payment.failed',
                        'status' => 'failed'
                    ];

                case 'REFUND_SUCCESS':
                    return [
                        'success' => true,
                        'event_type' => 'payment.refunded',
                        'status' => 'refunded'
                    ];

                default:
                    return [
                        'success' => false,
                        'error' => 'Unknown payment status: ' . $code
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

    protected function mapPhonePeStatus(string $status): string
    {
        $statusMap = [
            'PAYMENT_SUCCESS' => 'completed',
            'PAYMENT_PENDING' => 'pending',
            'PAYMENT_ERROR' => 'failed',
            'AUTHORIZATION_FAILED' => 'failed',
            'TXN_SUCCESS' => 'completed',
            'TXN_FAILED' => 'failed',
            'REFUND_SUCCESS' => 'refunded',
            'BAD_REQUEST' => 'failed',
            'INTERNAL_ERROR' => 'failed',
            'TXN_EXPIRED' => 'expired',
        ];

        return $statusMap[$status] ?? 'unknown';
    }

    protected function getMobileNumberFromRequest(PaymentRequest $request): ?string
    {
        $customer = $request->getCustomer();
        return $customer['phone'] ?? null;
    }

    protected function getShortNameFromRequest(PaymentRequest $request): string
    {
        $customer = $request->getCustomer();
        return $this->getShortName($customer['name'] ?? '');
    }

    protected function getShortName(string $fullName): string
    {
        return substr($fullName, 0, 50); // PhonePe has 50 character limit
    }

    protected function getMobileNumberFromResponse(array $data): ?string
    {
        $paymentInstrument = $data['paymentInstrument'] ?? [];

        if (isset($paymentInstrument['type']) && $paymentInstrument['type'] === 'UPI') {
            return $paymentInstrument['upi']['vpa'] ?? null;
        }

        return null;
    }

    protected function getPaymentMethodFromResponse(array $data): ?string
    {
        $paymentInstrument = $data['paymentInstrument'] ?? [];
        $paymentMode = $data['paymentMode'] ?? null;

        if (isset($paymentInstrument['type'])) {
            switch ($paymentInstrument['type']) {
                case 'UPI':
                    return 'UPI';
                case 'CREDIT_CARD':
                    return 'Credit Card';
                case 'DEBIT_CARD':
                    return 'Debit Card';
                case 'NET_BANKING':
                    return 'Net Banking';
                case 'PAYTM':
                    return 'Paytm';
                default:
                    return $paymentInstrument['type'];
            }
        }

        return $paymentMode;
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

        // PhonePe only supports INR
        if ($request->getCurrency() !== 'INR') {
            throw new Exception("Currency {$request->getCurrency()} is not supported. PhonePe only supports INR (Indian Rupee).");
        }

        // PhonePe minimum amount is 1 INR (100 paise)
        if ($request->getAmount() < 1) {
            throw new Exception('Payment amount must be at least 1 INR');
        }
    }

    protected function getEndpoint(string $path): string
    {
        $baseUrl = $this->isSandbox()
            ? 'https://api-preprod.phonepe.com/apis/pg-sandbox'
            : 'https://api.phonepe.com/apis/hermes';

        return $baseUrl . '/' . ltrim($path, '/');
    }

    protected function createErrorResponse(string $message): PaymentResponse
    {
        return PaymentResponse::error($message, 400, [
            'gateway' => 'phonepe',
            'timestamp' => time()
        ]);
    }

    public function parseCallback($request): array
    {
        return [
            'merchantTransactionId' => $request->input('merchantTransactionId'),
            'transactionId' => $request->input('transactionId'),
            'amount' => $request->input('amount'),
            'code' => $request->input('code'),
            'responseCode' => $request->input('responseCode'),
            'authDate' => $request->input('authDate'),
            'paymentInstrument' => $request->input('paymentInstrument'),
            'paymentMode' => $request->input('paymentMode'),
            'providerReferenceId' => $request->input('providerReferenceId'),
            'payer' => $request->input('payer'),
            'merchantId' => $request->input('merchantId'),
            'raw_body' => $request->getContent(),
            'headers' => $request->headers->all()
        ];
    }

    public function getGatewayConfig(): array
    {
        return [
            'merchant_id' => $this->config->getMerchantId(),
            'merchant_user_id' => $this->config->getMerchantUserId(),
            'salt_key' => $this->config->getSaltKey(),
            'test_mode' => $this->isSandbox(),
            'country' => $this->config->getCountry(),
            'webhook_url' => $this->config->getWebhookUrl()
        ];
    }

    public function createSubscription(array $subscriptionData): PaymentResponse
    {
        try {
            // PhonePe doesn't have direct subscription API with this package
            // This would typically require PhonePe's subscription services
            return $this->createErrorResponse('Subscription feature not available with current PhonePe integration');
        } catch (Exception $e) {
            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function cancelSubscription(string $subscriptionId): PaymentResponse
    {
        try {
            // PhonePe doesn't have direct subscription API with this package
            return $this->createErrorResponse('Subscription feature not available with current PhonePe integration');
        } catch (Exception $e) {
            return $this->createErrorResponse($e->getMessage());
        }
    }
}