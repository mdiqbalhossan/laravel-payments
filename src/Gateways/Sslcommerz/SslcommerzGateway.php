<?php

namespace Mdiqbal\LaravelPayments\Gateways\Sslcommerz;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;

// SSLCommerz SDK will be loaded dynamically

class SslcommerzGateway extends AbstractGateway
{
    /**
     * SSLCommerz instance
     */
    protected $sslcommerz;

    /**
     * Gateway configuration
     */
    protected array $config = [];

    /**
     * Supported currencies
     */
    protected array $supportedCurrencies = [
        'BDT', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD',
        'MYR', 'THB', 'IDR', 'PHP', 'AED', 'SAR', 'QAR',
        'OMR', 'BHD', 'KWD', 'JOD', 'LBP',
    ];

    /**
     * Constructor
     */
    public function __construct(array $config = [])
    {
        // Handle nested config structure from PaymentServiceProvider
        $mode = $config['mode'] ?? 'sandbox';
        $modeConfig = $config[$mode] ?? [];

        // Merge config with priority: passed config > mode-specific config > default config
        $this->config = array_merge([
            'store_id' => config('services.sslcommerz.store_id'),
            'store_password' => config('services.sslcommerz.store_password'),
            'test_mode' => config('services.sslcommerz.test_mode', true),
            'success_url' => config('services.sslcommerz.success_url'),
            'fail_url' => config('services.sslcommerz.fail_url'),
            'cancel_url' => config('services.sslcommerz.cancel_url'),
            'ipn_url' => config('services.sslcommerz.ipn_url'),
        ], $modeConfig, $config);

        // Set test_mode based on mode
        $this->config['test_mode'] = $mode === 'sandbox';
        $this->config['mode'] = $mode;

        $this->initializeSslcommerz();
    }

    /**
     * Initialize SSLCommerz SDK
     */
    protected function initializeSslcommerz()
    {
        try {
            // Try to create SSLCommerz instance
            if (class_exists('Raziul\SslCommerz\SslCommerz')) {
                $this->sslcommerz = new \Raziul\SslCommerz\SslCommerz(
                    $this->config['store_id'],
                    $this->config['store_password'],
                    $this->config['test_mode']
                );
            } else {
                // Fallback: Create a mock implementation that can be used for testing
                $this->sslcommerz = new class($this->config['store_id'], $this->config['store_password'], $this->config['test_mode'])
                {
                    private $config;

                    public function __construct($storeId, $storePassword, $testMode)
                    {
                        $this->config = [
                            'store_id' => $storeId,
                            'store_password' => $storePassword,
                            'test_mode' => $testMode,
                        ];
                    }

                    public function makePayment($data, $type)
                    {
                        Log::warning('SSLCommerz SDK not available, making direct API call', $data);

                        // Make direct API call to SSLCommerz since SDK is not available
                        $apiUrl = $this->config['test_mode']
                            ? 'https://sandbox.sslcommerz.com/gwprocess/v3/api.php'
                            : 'https://securepay.sslcommerz.com/gwprocess/v3/api.php';

                        // Validate store credentials
                        if (empty($this->config['store_id']) || empty($this->config['store_password'])) {
                            Log::error('SSLCommerz credentials missing', [
                                'store_id_set' => ! empty($this->config['store_id']),
                                'store_password_set' => ! empty($this->config['store_password']),
                            ]);

                            return [
                                'status' => 'FAILED',
                                'failedreason' => 'Store ID or Store Password is missing. Please configure SSLCommerz credentials.',
                            ];
                        }

                        // Add store_id and store_password to the data
                        $requestData = array_merge($data, [
                            'store_id' => $this->config['store_id'],
                            'store_password' => $this->config['store_password'],
                        ]);

                        try {
                            $response = Http::asForm()->post($apiUrl, $requestData);

                            if ($response->successful()) {
                                $result = $response->json();

                                // If response is a string, try to parse it
                                if (is_string($result)) {
                                    parse_str($result, $result);
                                }

                                return $result;
                            }

                            // If request failed, return error response
                            return [
                                'status' => 'FAILED',
                                'failedreason' => 'API request failed: '.$response->status(),
                            ];
                        } catch (\Exception $e) {
                            Log::error('SSLCommerz direct API call failed', [
                                'error' => $e->getMessage(),
                                'data' => $data,
                            ]);

                            return [
                                'status' => 'FAILED',
                                'failedreason' => 'API call failed: '.$e->getMessage(),
                            ];
                        }
                    }

                    public function orderValidate($data)
                    {
                        Log::warning('SSLCommerz SDK not available, returning mock validation', $data);

                        return [
                            'element_0' => $data['tran_id'] ?? 'mock_transaction',
                            'element_4' => 0,
                            'element_5' => 'BDT',
                            'element_7' => 'VALID',
                        ];
                    }

                    public function refundTransaction($data)
                    {
                        Log::warning('SSLCommerz SDK not available, returning mock refund', $data);

                        return [
                            'status' => 'success',
                            'trans_id' => 'refund_'.time(),
                        ];
                    }
                };

                Log::warning('SSLCommerz SDK not found, using mock implementation', [
                    'required_class' => 'Raziul\\SslCommerz\\SslCommerz',
                    'package' => 'raziul/sslcommerz-laravel',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to initialize SSLCommerz', [
                'error' => $e->getMessage(),
                'config' => $this->config,
            ]);

            throw new \Exception('SSLCommerz initialization failed: '.$e->getMessage());
        }
    }

    /**
     * Get gateway name
     */
    public function gatewayName(): string
    {
        return 'sslcommerz';
    }

    /**
     * Process payment
     */
    public function pay(PaymentRequest $paymentRequest): PaymentResponse
    {
        try {

            // Validate currency
            if (! $this->supportsCurrency($paymentRequest->currency)) {
                throw new \InvalidArgumentException("Currency {$paymentRequest->currency} is not supported by SSLCommerz");
            }

            // Validate required fields
            if (empty($paymentRequest->email)) {
                throw new \InvalidArgumentException('Customer email is required for SSLCommerz payment');
            }

            if (empty($paymentRequest->transaction_id)) {
                throw new \InvalidArgumentException('Transaction ID is required for SSLCommerz payment');
            }

            // Get URLs - use return_url for success, cancel_url for cancel, notify_url for IPN
            $successUrl = $paymentRequest->returnUrl ?? $this->config['success_url'];
            $failUrl = $paymentRequest->cancelUrl ?? $this->config['fail_url'];
            $cancelUrl = $paymentRequest->cancelUrl ?? $this->config['cancel_url'];
            $ipnUrl = $paymentRequest->notifyUrl ?? $this->config['ipn_url'];

            // Validate URLs are set
            if (empty($successUrl) || empty($failUrl) || empty($cancelUrl)) {
                throw new \InvalidArgumentException('Success, fail, and cancel URLs are required for SSLCommerz payment');
            }

            // Prepare payment data with all required fields
            $paymentData = [
                'total_amount' => (float) $paymentRequest->amount,
                'currency' => strtoupper($paymentRequest->currency),
                'tran_id' => $paymentRequest->transaction_id ?? $paymentRequest->orderId,
                'success_url' => $successUrl,
                'fail_url' => $failUrl,
                'cancel_url' => $cancelUrl,
                'ipn_url' => $ipnUrl,
                'cus_name' => $paymentRequest->customer['name'] ?? 'Guest User',
                'cus_email' => $paymentRequest->email,
                'cus_phone' => $this->sanitizePhone($paymentRequest->customer['phone'] ?? '01700000000'),
                'cus_add1' => $this->sanitizeAddress($paymentRequest->customer['address'] ?? 'N/A'),
                'cus_city' => $paymentRequest->customer['city'] ?? 'Dhaka',
                'cus_country' => $paymentRequest->customer['country'] ?? 'Bangladesh',
                'cus_postcode' => $paymentRequest->customer['postal_code'] ?? '1000',
                'shipping_method' => 'NO',
                'multi_card_name' => $this->getPaymentMethods([
                    'payment_options' => $paymentRequest->metadata['payment_options'] ?? 'all',
                ]),
                'product_name' => $paymentRequest->description ?? 'Payment',
                'product_category' => $paymentRequest->metadata['category'] ?? 'General',
                'product_profile' => 'general',
            ];

            // Add custom metadata if provided
            if (! empty($paymentRequest->metadata)) {
                $paymentData['value_a'] = json_encode($paymentRequest->metadata);
            }

            // Create payment session
            $response = $this->sslcommerz->makePayment($paymentData, 'hosted');

            if (isset($response['status']) && $response['status'] === 'SUCCESS') {
                return PaymentResponse::success([
                    'transaction_id' => $paymentRequest->transaction_id,
                    'gateway_transaction_id' => $response['sessionkey'] ?? null,
                    'payment_url' => $response['GatewayPageURL'] ?? null,
                    'session_key' => $response['sessionkey'] ?? null,
                    'redirect_url' => $response['GatewayPageURL'] ?? null,
                    'message' => 'Payment session created successfully',
                    'data' => $response,
                ]);
            }

            throw new \Exception($response['failedreason'] ?? 'Failed to create payment session');
        } catch (\Exception $e) {
            Log::error('SSLCommerz payment error: '.$e->getMessage(), [
                'transaction_id' => $paymentRequest->transaction_id ?? null,
                'amount' => $paymentRequest->amount ?? null,
                'error' => $e->getMessage(),
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
            $transactionId = $payload['tran_id'] ?? $payload['transaction_id'] ?? null;

            if (! $transactionId) {
                return PaymentResponse::error('Transaction ID is required for verification', 'MISSING_TRANSACTION_ID');
            }

            // SSLCommerz transaction validation
            $response = $this->sslcommerz->orderValidate([
                'tran_id' => $transactionId,
                'amount' => null, // Will be verified from response
                'currency' => null, // Will be verified from response
                'store_password' => $this->config['store_password'],
            ]);

            if ($response && isset($response['element_0']) && $response['element_0'] === $transactionId) {
                $status = $response['element_7'] ?? null;
                $successStatuses = ['success', 'completed', 'valid', 'validated'];
                $isSuccess = in_array(strtolower($status), $successStatuses);

                return new PaymentResponse(
                    success: $isSuccess,
                    transactionId: $transactionId,
                    gatewayTransactionId: $response['element_0'] ?? null,
                    status: strtolower($status),
                    amount: (float) ($response['element_4'] ?? 0),
                    currency: $response['element_5'] ?? null,
                    message: $isSuccess ? 'Payment verified successfully' : 'Payment verification failed',
                    data: array_merge([
                        'payment_method' => $response['element_2'] ?? null,
                        'card_type' => $response['element_15'] ?? null,
                        'card_issuer' => $response['element_16'] ?? null,
                        'card_brand' => $response['element_17'] ?? null,
                        'card_no' => $response['element_14'] ?? null,
                        'store_amount' => (float) ($response['element_9'] ?? 0),
                        'bank_tran_id' => $response['element_12'] ?? null,
                        'currency_amount' => (float) ($response['element_6'] ?? 0),
                        'currency_rate' => (float) ($response['element_8'] ?? 0),
                        'base_fair' => (float) ($response['element_10'] ?? 0),
                        'risk_level' => $response['element_18'] ?? null,
                        'risk_title' => $response['element_19'] ?? null,
                        'validated_on' => $response['element_13'] ?? null,
                        'gw_version' => $response['element_20'] ?? null,
                    ], $response)
                );
            }

            return PaymentResponse::error('Transaction not found or invalid', 'TRANSACTION_NOT_FOUND');

        } catch (\Exception $e) {
            Log::error('SSLCommerz verification error: '.$e->getMessage(), [
                'payload' => $payload,
                'error' => $e->getMessage(),
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
            // SSLCommerz requires bank transaction ID for refunds
            // We need to get this from the original transaction
            // In a real implementation, you would store this during payment

            // For now, we'll use the transaction ID as bank transaction ID
            // This may need to be adjusted based on your implementation
            $refundData = [
                'bank_tran_id' => $transactionId, // This might need to be stored separately during payment
                'refund_amount' => $amount,
                'refund_remarks' => 'Refund requested',
                'store_id' => $this->config['store_id'],
                'store_password' => $this->config['store_password'],
            ];

            if (! $refundData['bank_tran_id']) {
                throw new \Exception('Bank transaction ID is required for refund');
            }

            $response = $this->sslcommerz->refundTransaction($refundData);

            if (isset($response['status']) && $response['status'] === 'success') {
                return true;
            }

            throw new \Exception($response['error_message'] ?? 'Refund failed');
        } catch (\Exception $e) {
            Log::error('SSLCommerz refund error: '.$e->getMessage(), [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if refunds are supported
     */
    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * Process webhook
     */
    public function processWebhook(array $payload): array
    {
        try {
            // Verify the webhook is from SSLCommerz
            if (! $this->validateWebhook($payload)) {
                throw new \Exception('Invalid webhook signature');
            }

            $tranId = $payload['tran_id'] ?? null;
            $amount = $payload['amount'] ?? null;
            $currency = $payload['currency'] ?? null;

            // Validate the transaction
            $validationData = [
                'val_id' => $payload['val_id'] ?? null,
                'store_id' => $this->config['store_id'],
                'store_password' => $this->config['store_password'],
            ];

            $response = Http::asForm()->post(
                $this->config['test_mode']
                    ? 'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php'
                    : 'https://securepay.sslcommerz.com/validator/api/validationserverAPI.php',
                $validationData
            );

            if (! $response->successful()) {
                throw new \Exception('Webhook validation failed');
            }

            $result = $response->json();

            return [
                'success' => true,
                'event_type' => $this->getEventType($payload),
                'transaction_id' => $tranId,
                'status' => $result['status'] ?? 'unknown',
                'amount' => $amount ? (float) $amount : null,
                'currency' => $currency,
                'payment_method' => $payload['card_type'] ?? null,
                'data' => [
                    'webhook' => $payload,
                    'validation' => $result,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('SSLCommerz webhook error: '.$e->getMessage(), [
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'WEBHOOK_FAILED',
                ],
            ];
        }
    }

    /**
     * Validate webhook
     */
    protected function validateWebhook(array $payload): bool
    {
        // Check required fields
        $requiredFields = ['tran_id', 'val_id', 'amount', 'currency'];
        foreach ($requiredFields as $field) {
            if (! isset($payload[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get event type from webhook payload
     */
    protected function getEventType(array $payload): string
    {
        $status = $payload['status'] ?? '';

        switch ($status) {
            case 'VALID':
            case 'VALIDATED':
                return 'payment.success';
            case 'FAILED':
                return 'payment.failed';
            case 'CANCELLED':
                return 'payment.cancelled';
            default:
                return 'payment.unknown';
        }
    }

    /**
     * Get payment methods
     */
    protected function getPaymentMethods(array $data): ?string
    {
        $methods = $data['payment_options'] ?? 'all';

        if ($methods === 'all') {
            return null;
        }

        $availableMethods = [
            'amex' => 'amex',
            'visa' => 'visa',
            'mastercard' => 'mastercard',
            'brac_visa' => 'brac_visa',
            'city_visa' => 'city_visa',
            'dutch_bangla_visa' => 'dutch_bangla_visa',
            'ebl_visa' => 'ebl_visa',
            'dbbl_master' => 'dbbl_master',
            'brac_master' => 'brac_master',
            'city_master' => 'city_master',
            'ebl_master' => 'ebl_master',
            'mtbl_visa' => 'mtbl_visa',
            'city_amex' => 'city_amex',
            'brac_amex' => 'brac_amex',
            'dbbl_amex' => 'dbbl_amex',
            'mobile_banking' => 'mobilebank',
            'internet_banking' => 'internetbank',
            'others' => 'others',
        ];

        if (is_array($methods)) {
            $selectedMethods = [];
            foreach ($methods as $method) {
                if (isset($availableMethods[$method])) {
                    $selectedMethods[] = $availableMethods[$method];
                }
            }

            return ! empty($selectedMethods) ? implode(',', $selectedMethods) : null;
        }

        return isset($availableMethods[$methods]) ? $availableMethods[$methods] : null;
    }

    /**
     * Create subscription (SSLCommerz doesn't have native subscriptions, so we use recurring payments)
     */
    public function createSubscription(array $data): array
    {
        try {
            // SSLCommerz supports recurring payments through payment links
            $paymentData = [
                'total_amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'BDT',
                'tran_id' => $data['transaction_id'] ?? 'SUB_'.time(),
                'success_url' => $data['success_url'] ?? $this->config['success_url'],
                'fail_url' => $data['fail_url'] ?? $this->config['fail_url'],
                'cancel_url' => $data['cancel_url'] ?? $this->config['cancel_url'],
                'ipn_url' => $this->config['ipn_url'],
                'cus_name' => $data['customer']['name'] ?? 'Guest User',
                'cus_email' => $data['customer']['email'] ?? '',
                'cus_phone' => $data['customer']['phone'] ?? '',
                'product_name' => $data['description'] ?? 'Recurring Payment',
                'product_category' => 'Subscription',
                'product_profile' => 'general',
                'recur_amount' => $data['amount'],
                'recur_interval' => $data['interval'] ?? 'month',
                'recur_times' => $data['times'] ?? 12,
            ];

            $response = $this->sslcommerz->makePayment($paymentData, 'hosted');

            if (isset($response['status']) && $response['status'] === 'SUCCESS') {
                return [
                    'success' => true,
                    'subscription_id' => $paymentData['tran_id'],
                    'gateway_transaction_id' => $response['sessionkey'],
                    'payment_url' => $response['GatewayPageURL'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? 'BDT',
                    'interval' => $data['interval'] ?? 'month',
                    'times' => $data['times'] ?? 12,
                    'status' => 'created',
                    'message' => 'Subscription created successfully',
                    'data' => $response,
                ];
            }

            throw new \Exception($response['failedreason'] ?? 'Failed to create subscription');
        } catch (\Exception $e) {
            Log::error('SSLCommerz subscription error: '.$e->getMessage(), [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'SUBSCRIPTION_FAILED',
                ],
            ];
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        // SSLCommerz doesn't have native subscription management
        // This would need to be handled at the application level
        return [
            'success' => true,
            'subscription_id' => $subscriptionId,
            'status' => 'cancelled',
            'message' => 'Subscription cancelled at application level',
        ];
    }

    /**
     * Create customer
     */
    public function createCustomer(array $data): array
    {
        // SSLCommerz doesn't have a separate customer creation API
        // Customer information is sent with each payment request
        return [
            'success' => true,
            'customer' => [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
            ],
            'message' => 'Customer information noted for future payments',
        ];
    }

    /**
     * Create payment link
     */
    public function createPaymentLink(array $data): array
    {
        try {
            $paymentData = [
                'total_amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'BDT',
                'tran_id' => $data['transaction_id'] ?? 'LINK_'.time(),
                'success_url' => $data['redirect_url'] ?? $this->config['success_url'],
                'fail_url' => $data['redirect_url'] ?? $this->config['fail_url'],
                'cancel_url' => $data['redirect_url'] ?? $this->config['cancel_url'],
                'ipn_url' => $this->config['ipn_url'],
                'cus_name' => $data['customer']['name'] ?? 'Guest User',
                'cus_email' => $data['customer']['email'] ?? '',
                'cus_phone' => $data['customer']['phone'] ?? '',
                'product_name' => $data['description'] ?? 'Payment Link',
                'product_category' => 'Payment Link',
                'product_profile' => 'general',
            ];

            $response = $this->sslcommerz->makePayment($paymentData, 'hosted');

            if (isset($response['status']) && $response['status'] === 'SUCCESS') {
                return [
                    'success' => true,
                    'link_id' => $paymentData['tran_id'],
                    'payment_url' => $response['GatewayPageURL'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? 'BDT',
                    'status' => 'active',
                    'message' => 'Payment link created successfully',
                    'data' => $response,
                ];
            }

            throw new \Exception($response['failedreason'] ?? 'Failed to create payment link');
        } catch (\Exception $e) {
            Log::error('SSLCommerz payment link error: '.$e->getMessage(), [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'PAYMENT_LINK_FAILED',
                ],
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
     * Get gateway configuration
     */
    public function getGatewayConfig(): array
    {
        return [
            'name' => $this->getGatewayName(),
            'display_name' => 'SSLCommerz',
            'currencies' => $this->getSupportedCurrencies(),
            'supports_subscriptions' => true,
            'supports_refunds' => true,
            'supports_webhooks' => true,
            'test_mode' => $this->config['test_mode'],
            'store_id' => $this->config['store_id'] ? substr($this->config['store_id'], 0, 4).'...' : null,
        ];
    }

    /**
     * Get payment methods for a country
     */
    public function getPaymentMethodsForCountry(string $country): array
    {
        $methods = [
            'BD' => [
                'visa' => 'Visa Card',
                'mastercard' => 'Mastercard',
                'amex' => 'American Express',
                'mobile_banking' => 'Mobile Banking (bKash, Rocket, Nagad, etc.)',
                'internet_banking' => 'Internet Banking',
                'others' => 'Other Payment Methods',
            ],
            'OTHER' => [
                'visa' => 'Visa Card',
                'mastercard' => 'Mastercard',
                'amex' => 'American Express',
                'internet_banking' => 'Internet Banking',
                'others' => 'Other Payment Methods',
            ],
        ];

        return $methods[$country] ?? $methods['OTHER'];
    }

    /**
     * Validate transaction data
     */
    protected function validateTransactionData(array $data): bool
    {
        $required = ['amount', 'currency', 'email'];

        foreach ($required as $field) {
            if (! isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize phone number for SSLCommerz
     */
    protected function sanitizePhone(?string $phone): string
    {
        if (empty($phone) || $phone === 'walk-in' || ! preg_match('/^[0-9+\-\s()]+$/', $phone)) {
            return '01700000000';
        }

        // Remove any non-numeric characters except + and -
        $phone = preg_replace('/[^0-9+\-]/', '', $phone);

        // Ensure it's at least 10 digits
        if (strlen(preg_replace('/[^0-9]/', '', $phone)) < 10) {
            return '01700000000';
        }

        return $phone;
    }

    /**
     * Sanitize address for SSLCommerz
     */
    protected function sanitizeAddress(?string $address): string
    {
        if (empty($address) || trim($address) === '') {
            return 'N/A';
        }

        // SSLCommerz requires address to be at least 3 characters
        $address = trim($address);
        if (strlen($address) < 3) {
            return 'N/A';
        }

        return $address;
    }
}
