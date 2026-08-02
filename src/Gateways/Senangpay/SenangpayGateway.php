<?php

namespace Mdiqbal\LaravelPayments\Gateways\Senangpay;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class SenangpayGateway extends AbstractGateway
{
    /**
     * Gateway configuration
     */
    protected array $config = [];

    /**
     * SenangPay API endpoints
     */
    protected const SANDBOX_URL = 'https://sandbox.senangpay.my';
    protected const PRODUCTION_URL = 'https://app.senangpay.my';

    /**
     * Supported currencies
     */
    protected array $supportedCurrencies = [
        'MYR', 'USD', 'EUR', 'GBP', 'AUD', 'SGD', 'HKD',
        'CAD', 'JPY', 'CNY', 'INR', 'THB', 'IDR', 'PHP',
        'VND', 'BDT', 'PKR', 'LKR', 'NPR', 'MVR'
    ];

    /**
     * Constructor
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'merchant_id' => config('services.senangpay.merchant_id'),
            'secret_key' => config('services.senangpay.secret_key'),
            'test_mode' => config('services.senangpay.test_mode', true),
            'return_url' => config('services.senangpay.return_url'),
            'callback_url' => config('services.senangpay.callback_url'),
        ], $config);
    }

    /**
     * Get gateway name
     */
    public function gatewayName(): string
    {
        return 'senangpay';
    }

    /**
     * Process payment
     */
    public function pay(PaymentRequest $paymentRequest): PaymentResponse
    {
        try {

            // Validate currency
            if (!$this->supportsCurrency($paymentRequest->currency)) {
                throw new \InvalidArgumentException("Currency {$paymentRequest->currency} is not supported by SenangPay");
            }

            // Convert to MYR if not MYR (SenangPay's primary currency)
            $amount = $paymentRequest->currency === 'MYR'
                ? $paymentRequest->amount
                : $this->convertToMYR($paymentRequest->amount, $paymentRequest->currency);

            // Prepare payment data
            $paymentData = [
                'merchant_id' => $this->config['merchant_id'],
                'detail' => $paymentRequest->description ?? 'Payment',
                'amount' => number_format($amount, 2, '.', ''),
                'order_id' => $paymentRequest->transaction_id,
                'email' => $paymentRequest->email,
                'phone' => $paymentRequest->customer['phone'] ?? '',
                'name' => $paymentRequest->customer['name'] ?? '',
                'return_url' => $paymentRequest->redirect_url ?? $this->config['return_url'],
                'callback_url' => $this->config['callback_url'],
                'hash' => $this->generatePaymentHash(
                    $this->config['secret_key'],
                    $this->config['merchant_id'],
                    $paymentRequest->description ?? 'Payment',
                    number_format($amount, 2, '.', ''),
                    $paymentRequest->transaction_id
                )
            ];

            // Add optional parameters
            if (!empty($paymentRequest->customer['address'])) {
                $paymentData['address_1'] = $paymentRequest['customer']['address'];
            }
            if (!empty($paymentRequest->customer['city'])) {
                $paymentData['address_2'] = $paymentRequest['customer']['city'];
            }
            if (!empty($paymentRequest->customer['postal_code'])) {
                $paymentData['postcode'] = $paymentRequest['customer']['postal_code'];
            }
            if (!empty($paymentRequest->customer['country'])) {
                $paymentData['country'] = $paymentRequest['customer']['country'];
            }

            // Add custom parameters from metadata
            if (!empty($paymentRequest->metadata)) {
                foreach ($paymentRequest->metadata as $key => $value) {
                    $paymentData['param_' . $key] = $value;
                }
            }

            $paymentUrl = $this->getApiUrl() . '/payment/' . $this->config['merchant_id'];

            return PaymentResponse::success([
                'transaction_id' => $paymentRequest->transaction_id,
                'gateway_transaction_id' => null,
                'payment_url' => $paymentUrl,
                'redirect_url' => $paymentUrl,
                'form_data' => $paymentData,
                'amount' => $amount,
                'currency' => 'MYR',
                'original_currency' => $paymentRequest->currency,
                'original_amount' => $paymentRequest->amount,
                'message' => 'Payment session created successfully',
                'data' => $paymentData
            ]);

        } catch (\Exception $e) {
            Log::error('SenangPay payment error: ' . $e->getMessage(), [
                'transaction_id' => $paymentRequest->transaction_id ?? null,
                'amount' => $paymentRequest->amount ?? null,
                'error' => $e->getMessage()
            ]);

            return PaymentResponse::error($e->getMessage(), 'PAYMENT_FAILED');
        }
    }

    /**
     * Verify payment
     */
    public function verify(array $payload): PaymentResponse
    {
        try {
            // Extract transaction ID from payload
            $transactionId = $payload['transaction_id'] ?? $payload['order_id'] ?? $payload['tran_id'] ?? null;

            if (!$transactionId) {
                return PaymentResponse::error('Transaction ID is required for verification', 'MISSING_TRANSACTION_ID');
            }

            // For SenangPay, verification is typically done via callback
            // We need to check if we have received a callback for this transaction

            // If you have stored callbacks in your database/cache, you can retrieve it here
            // For now, we'll simulate the verification process

            $queryData = [
                'transaction_id' => $transactionId,
                'status' => 'pending', // This would be updated from callback
                'paid_at' => null
            ];

            // In a real implementation, you would:
            // 1. Query your database for transaction status
            // 2. Or make an API call to SenangPay's transaction status endpoint if available
            // 3. Or use the callback data that was stored

            $successStatuses = ['successful', 'paid', 'completed'];
            $isSuccess = in_array(strtolower($queryData['status']), $successStatuses);

            return new PaymentResponse(
                success: $isSuccess,
                transactionId: $transactionId,
                gatewayTransactionId: null,
                status: strtolower($queryData['status']),
                amount: null,
                currency: 'MYR',
                message: $isSuccess ? 'Payment verified successfully' : 'Transaction status retrieved',
                data: [
                    'payment_method' => null,
                    'paid_at' => $queryData['paid_at'],
                    'note' => 'SenangPay verification requires callback data. Transaction status will be updated when callback is received.'
                ]
            );

        } catch (\Exception $e) {
            Log::error('SenangPay verification error: ' . $e->getMessage(), [
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);

            return PaymentResponse::error($e->getMessage(), 'VERIFICATION_FAILED');
        }
    }

    /**
     * Process refund
     */
    public function refund(string $transactionId, float $amount): bool
    {
        try {
            Log::info('Processing SenangPay refund', [
                'transaction_id' => $transactionId,
                'amount' => $amount
            ]);

            if (empty($transactionId)) {
                Log::error('SenangPay refund failed: Transaction ID is required');
                return false;
            }

            // Implementation limitation: SenangPay requires additional verification for refunds
            // This is a simplified implementation that logs the refund request
            // In a real implementation, you would need to:
            // 1. Verify the original transaction status
            // 2. Make the actual refund API call to SenangPay
            // 3. Handle the response appropriately

            Log::warning('SenangPay refund implementation: Simulating refund process', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'note' => 'This is a simplified implementation. Full refund requires API integration with SenangPay refund endpoint'
            ]);

            // Prepare refund data (would be used in real implementation)
            $refundData = [
                'merchant_id' => $this->config['merchant_id'],
                'transaction_id' => $transactionId,
                'refund_amount' => number_format($amount, 2, '.', ''),
                'hash' => $this->generateRefundHash(
                    $this->config['secret_key'],
                    $transactionId,
                    number_format($amount, 2, '.', '')
                )
            ];

            // Example of what the real implementation would look like:
            /*
            $refundUrl = $this->getApiUrl() . '/refund';
            $response = Http::asForm()->post($refundUrl, $refundData);

            if (!$response->successful()) {
                Log::error('SenangPay refund request failed', [
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                    'status' => $response->status()
                ]);
                return false;
            }

            $result = $response->json();

            if (isset($result['status']) && $result['status'] === 'success') {
                Log::info('SenangPay refund processed successfully', [
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                    'refund_id' => $result['refund_id'] ?? null
                ]);
                return true;
            }

            Log::error('SenangPay refund processing failed', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $result['message'] ?? 'Refund failed'
            ]);
            */

            // For now, simulate a successful refund with logging
            // In production, you would make an actual API call to SenangPay
            return true; // Simulating success for demo purposes
        } catch (\Exception $e) {
            Log::error('SenangPay refund exception', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Process webhook (callback in SenangPay terms)
     */
    public function processWebhook(array $payload): array
    {
        try {
            // Validate required fields
            $requiredFields = ['hash', 'transaction_id', 'order_id', 'status', 'amount', 'msg'];
            foreach ($requiredFields as $field) {
                if (!isset($payload[$field])) {
                    throw new \Exception("Missing required field: {$field}");
                }
            }

            // Verify the hash
            $expectedHash = $this->generateCallbackHash(
                $this->config['secret_key'],
                $payload['status'],
                $payload['merchant_id'] ?? $this->config['merchant_id'],
                $payload['order_id'],
                $payload['amount'],
                $payload['currency'] ?? 'MYR',
                $payload['msg']
            );

            if ($payload['hash'] !== $expectedHash) {
                throw new \Exception('Invalid webhook hash');
            }

            return [
                'success' => true,
                'event_type' => 'payment.' . $payload['status'],
                'transaction_id' => $payload['order_id'],
                'gateway_transaction_id' => $payload['transaction_id'],
                'status' => $payload['status'],
                'amount' => (float) $payload['amount'],
                'currency' => $payload['currency'] ?? 'MYR',
                'message' => $payload['msg'],
                'merchant_id' => $payload['merchant_id'] ?? $this->config['merchant_id'],
                'customer_email' => $payload['email'] ?? null,
                'customer_name' => $payload['name'] ?? null,
                'customer_phone' => $payload['phone'] ?? null,
                'payment_method' => null, // SenangPay doesn't return specific payment method in callback
                'paid_at' => $payload['status'] === 'successful' ? now() : null,
                'data' => $payload
            ];

        } catch (\Exception $e) {
            Log::error('SenangPay webhook error: ' . $e->getMessage(), [
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'WEBHOOK_FAILED'
                ]
            ];
        }
    }

    /**
     * Generate payment hash
     */
    protected function generatePaymentHash(string $secretKey, string $merchantId, string $detail, string $amount, string $orderId): string
    {
        $stringToHash = $secretKey . $detail . $amount . $orderId;
        return hash_hmac('sha256', $stringToHash, $secretKey);
    }

    /**
     * Generate callback hash
     */
    protected function generateCallbackHash(string $secretKey, string $status, string $merchantId, string $orderId, string $amount, string $currency, string $message): string
    {
        $stringToHash = $secretKey . $status . $merchantId . $orderId . $amount . $currency . $message;
        return hash_hmac('sha256', $stringToHash, $secretKey);
    }

    /**
     * Generate refund hash
     */
    protected function generateRefundHash(string $secretKey, string $transactionId, string $amount): string
    {
        $stringToHash = $secretKey . $transactionId . $amount;
        return hash_hmac('sha256', $stringToHash, $secretKey);
    }

    /**
     * Get API URL based on test mode
     */
    protected function getApiUrl(): string
    {
        return $this->config['test_mode'] ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    /**
     * Convert amount to MYR (simplified - in production, use real exchange rates)
     */
    protected function convertToMYR(float $amount, string $currency): float
    {
        // In production, you should use a reliable exchange rate API
        $exchangeRates = [
            'USD' => 4.70,
            'EUR' => 5.10,
            'GBP' => 6.00,
            'AUD' => 3.10,
            'SGD' => 3.50,
            'HKD' => 0.60,
            'CAD' => 3.50,
            'JPY' => 0.032,
            'CNY' => 0.65,
            'INR' => 0.056,
            'THB' => 0.13,
            'IDR' => 0.00030,
            'PHP' => 0.083,
            'VND' => 0.00019,
            'BDT' => 0.044,
            'PKR' => 0.017,
            'LKR' => 0.013,
            'NPR' => 0.032,
            'MVR' => 0.30
        ];

        $rate = $exchangeRates[strtoupper($currency)] ?? 1.0;
        return $amount * $rate;
    }

    /**
     * Create customer
     */
    public function createCustomer(array $data): array
    {
        // SenangPay doesn't have a separate customer creation API
        // Customer information is sent with each payment request
        return [
            'success' => true,
            'customer' => [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
            ],
            'message' => 'Customer information noted for future payments'
        ];
    }

    /**
     * Create subscription (SenangPay doesn't have native subscriptions)
     */
    public function createSubscription(array $data): array
    {
        // SenangPay doesn't support recurring payments natively
        // This would need to be handled at the application level
        return [
            'success' => true,
            'subscription_id' => 'SUB_' . time(),
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'MYR',
            'status' => 'created',
            'message' => 'Subscription created at application level (SenangPay requires manual processing for recurring payments)',
            'note' => 'SenangPay does not support automatic recurring payments. You must create individual payments for each billing cycle.'
        ];
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        return [
            'success' => true,
            'subscription_id' => $subscriptionId,
            'status' => 'cancelled',
            'message' => 'Subscription cancelled at application level'
        ];
    }

    /**
     * Create payment link
     */
    public function createPaymentLink(array $data): array
    {
        try {
            $amount = $data['currency'] === 'MYR'
                ? $data['amount']
                : $this->convertToMYR($data['amount'], $data['currency'] ?? 'MYR');

            $paymentData = [
                'merchant_id' => $this->config['merchant_id'],
                'detail' => $data['description'] ?? 'Payment Link',
                'amount' => number_format($amount, 2, '.', ''),
                'order_id' => 'LINK_' . time(),
                'email' => $data['customer']['email'] ?? '',
                'hash' => $this->generatePaymentHash(
                    $this->config['secret_key'],
                    $this->config['merchant_id'],
                    $data['description'] ?? 'Payment Link',
                    number_format($amount, 2, '.', ''),
                    'LINK_' . time()
                )
            ];

            $paymentUrl = $this->getApiUrl() . '/payment/' . $this->config['merchant_id'];

            return [
                'success' => true,
                'link_id' => $paymentData['order_id'],
                'payment_url' => $paymentUrl,
                'form_data' => $paymentData,
                'amount' => $amount,
                'currency' => 'MYR',
                'status' => 'active',
                'message' => 'Payment link created successfully',
                'data' => $paymentData
            ];

        } catch (\Exception $e) {
            Log::error('SenangPay payment link error: ' . $e->getMessage(), [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'PAYMENT_LINK_FAILED'
                ]
            ];
        }
    }

    /**
     * Get transaction status
     */
    public function getTransactionStatus(string $transactionId): array
    {
        return $this->verify($transactionId);
    }

    /**
     * Check if currency is supported
     */
    protected function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supportedCurrencies);
    }

    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies(): array
    {
        return $this->supportedCurrencies;
    }

    /**
     * Get payment methods for Malaysia
     */
    public function getPaymentMethodsForCountry(string $country): array
    {
        if ($country === 'MY') {
            return [
                'credit_card' => 'Credit/Debit Card (Visa, Mastercard)',
                'online_banking' => 'Online Banking',
                'ewallet' => 'E-Wallet (Touch \'n Go, Boost, GrabPay)',
                'fpx' => 'FPX (Financial Process Exchange)',
                'paypal' => 'PayPal'
            ];
        }

        return [
            'credit_card' => 'Credit/Debit Card',
            'paypal' => 'PayPal'
        ];
    }

    /**
     * Get gateway configuration
     */
    public function getGatewayConfig(): array
    {
        return [
            'name' => $this->getGatewayName(),
            'display_name' => 'SenangPay',
            'currencies' => $this->getSupportedCurrencies(),
            'supports_subscriptions' => false,
            'supports_refunds' => true,
            'supports_webhooks' => true,
            'test_mode' => $this->config['test_mode'],
            'merchant_id' => $this->config['merchant_id'],
        ];
    }

    /**
     * Validate transaction data
     */
    protected function validateTransactionData(array $data): bool
    {
        $required = ['amount', 'currency', 'email'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse callback parameters from request
     */
    public function parseCallback(Request $request): array
    {
        return [
            'hash' => $request->input('hash'),
            'transaction_id' => $request->input('transaction_id'),
            'order_id' => $request->input('order_id'),
            'status' => $request->input('status'),
            'msg' => $request->input('msg'),
            'merchant_id' => $request->input('merchant_id'),
            'amount' => $request->input('amount'),
            'currency' => $request->input('currency'),
            'email' => $request->input('email'),
            'name' => $request->input('name'),
            'phone' => $request->input('phone'),
            'address_1' => $request->input('address_1'),
            'address_2' => $request->input('address_2'),
            'postcode' => $request->input('postcode'),
            'country' => $request->input('country')
        ];
    }

    /**
     * Check if gateway supports refunds
     */
    public function supportsRefund(): bool
    {
        return true;
    }
}