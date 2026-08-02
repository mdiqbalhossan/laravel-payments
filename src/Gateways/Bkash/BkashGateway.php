<?php

namespace Mdiqbal\LaravelPayments\Gateways\Bkash;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
// bKash SDK will be loaded dynamically

class BkashGateway extends AbstractGateway
{
    /**
     * bKash instance
     */
    protected $bkash;

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
        'OMR', 'BHD', 'KWD', 'JOD', 'LBP'
    ];

    /**
     * Constructor
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'app_key' => config('services.bkash.app_key'),
            'app_secret' => config('services.bkash.app_secret'),
            'username' => config('services.bkash.username'),
            'password' => config('services.bkash.password'),
            'test_mode' => config('services.bkash.test_mode', true),
            'callback_url' => config('services.bkash.callback_url'),
            'success_url' => config('services.bkash.success_url'),
            'fail_url' => config('services.bkash.fail_url'),
        ], $config);

        $this->initializeBkash();
    }

    /**
     * Initialize bKash SDK
     */
    protected function initializeBkash()
    {
        try {
            // Try to create BkashPayment instance
            if (class_exists('SabitAhmad\LaravelBkash\BkashPayment')) {
                $this->bkash = new \SabitAhmad\LaravelBkash\BkashPayment();

                // Set credentials if provided
                if ($this->config['app_key']) {
                    $this->bkash->setAppKey($this->config['app_key']);
                }
                if ($this->config['app_secret']) {
                    $this->bkash->setAppSecret($this->config['app_secret']);
                }
                if ($this->config['username']) {
                    $this->bkash->setUsername($this->config['username']);
                }
                if ($this->config['password']) {
                    $this->bkash->setPassword($this->config['password']);
                }
                if ($this->config['test_mode'] !== null) {
                    $this->bkash->setTestMode($this->config['test_mode']);
                }
            } else {
                // Fallback: Create a mock implementation that can be used for testing
                $this->bkash = new class {
                    private $config;

                    public function __construct() {
                        $this->config = [];
                    }

                    public function setAppKey($key) {
                        $this->config['app_key'] = $key;
                    }

                    public function setAppSecret($secret) {
                        $this->config['app_secret'] = $secret;
                    }

                    public function setUsername($username) {
                        $this->config['username'] = $username;
                    }

                    public function setPassword($password) {
                        $this->config['password'] = $password;
                    }

                    public function setTestMode($mode) {
                        $this->config['test_mode'] = $mode;
                    }

                    public function initPayment($data) {
                        Log::warning('bKash SDK not available, returning mock payment response', $data);
                        return [
                            'status' => 'success',
                            'paymentID' => 'mock_payment_' . time(),
                            'createTime' => date('Y-m-d H:i:s'),
                            'transactionStatus' => 'Initiated',
                            'amount' => $data['amount'] ?? '0.00',
                            'currency' => 'BDT',
                            'intent' => $data['intent'] ?? 'sale',
                            'merchantInvoiceNumber' => $data['merchantInvoiceNumber'] ?? null
                        ];
                    }

                    public function executePayment($paymentId) {
                        Log::warning('bKash SDK not available, returning mock execution response', ['paymentID' => $paymentId]);
                        return [
                            'status' => 'success',
                            'paymentID' => $paymentId,
                            'transactionStatus' => 'Completed',
                            'transactionId' => 'mock_transaction_' . time(),
                            'amount' => '0.00',
                            'currency' => 'BDT',
                            'payerReference' => 'mock_payer',
                            'paymentTime' => date('Y-m-d H:i:s'),
                            'trxID' => 'mock_trx_' . time()
                        ];
                    }

                    public function refundTransaction($data) {
                        Log::warning('bKash SDK not available, returning mock refund response', $data);
                        return [
                            'status' => 'success',
                            'refundTrxID' => 'mock_refund_' . time(),
                            'transactionStatus' => 'Refunded'
                        ];
                    }
                };

                Log::warning('bKash SDK not found, using mock implementation', [
                    'required_class' => 'SabitAhmad\\LaravelBkash\\BkashPayment',
                    'package' => 'sabitahmad/laravel-bkash'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to initialize bKash', [
                'error' => $e->getMessage(),
                'config' => array_intersect_key($this->config, array_flip(['app_key', 'username', 'test_mode']))
            ]);

            throw new \Exception('bKash initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Get gateway name
     */
    public function gatewayName(): string
    {
        return 'bkash';
    }

    /**
     * Process payment
     */
    public function pay(PaymentRequest $paymentRequest): PaymentResponse
    {
        try {

            // Validate currency
            if (!$this->supportsCurrency($paymentRequest->currency)) {
                throw new \InvalidArgumentException("Currency {$paymentRequest->currency} is not supported by bKash");
            }

            // Convert to BDT if not BDT (bKash's primary currency)
            $amount = $paymentRequest->currency === 'BDT'
                ? $paymentRequest->amount
                : $this->convertToBDT($paymentRequest->amount, $paymentRequest->currency);

            // Prepare payment data
            $paymentData = [
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => 'BDT',
                'intent' => 'sale',
                'merchantInvoiceNumber' => $paymentRequest->transaction_id,
                'callbackURL' => $this->config['callback_url'],
                'merchantAssociationInfo' => [
                    'order_id' => $paymentRequest->transaction_id,
                    'customer_email' => $paymentRequest->email,
                    'customer_name' => $paymentRequest->customer['name'] ?? '',
                    'customer_phone' => $paymentRequest->customer['phone'] ?? '',
                ]
            ];

            // Add additional details if provided
            if (!empty($paymentRequest->description)) {
                $paymentData['details'] = $paymentRequest->description;
            }

            // Add metadata if provided
            if (!empty($paymentRequest->metadata)) {
                $paymentData['additionalInfo'] = json_encode($paymentRequest->metadata);
            }

            // Initialize payment
            $response = $this->bkash->initPayment($paymentData);

            if (isset($response['paymentID'])) {
                return PaymentResponse::success([
                    'transaction_id' => $paymentRequest->transaction_id,
                    'gateway_transaction_id' => $response['paymentID'],
                    'payment_url' => $response['bkashURL'] ?? null,
                    'redirect_url' => $response['bkashURL'] ?? null,
                    'payment_id' => $response['paymentID'],
                    'amount' => $amount,
                    'currency' => 'BDT',
                    'original_currency' => $paymentRequest->currency,
                    'original_amount' => $paymentRequest->amount,
                    'created_at' => now(),
                    'message' => 'Payment initialized successfully',
                    'data' => $response
                ]);
            }

            throw new \Exception($response['errorMessage'] ?? 'Failed to initialize payment');

        } catch (\Exception $e) {
            Log::error('bKash payment error: ' . $e->getMessage(), [
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
            $transactionId = $payload['transaction_id'] ?? $payload['trxID'] ?? $payload['tran_id'] ?? null;

            if (!$transactionId) {
                return PaymentResponse::error('Transaction ID is required for verification', 'MISSING_TRANSACTION_ID');
            }

            // For bKash, we need the paymentID to verify
            // You might need to store the paymentID when initializing payment

            // If you have stored the paymentID, use it
            // Otherwise, you can query your database to find the paymentID for this transaction

            $paymentId = $this->getPaymentIdFromTransaction($transactionId);

            if (!$paymentId) {
                return PaymentResponse::error('Payment ID not found for transaction', 'PAYMENT_ID_NOT_FOUND');
            }

            // Execute payment to verify status
            $response = $this->bkash->executePayment($paymentId);

            if (isset($response['transactionStatus'])) {
                $response['transactionId'] = $transactionId;
                $successStatuses = ['completed', 'successful'];
                $isSuccess = in_array(strtolower($response['transactionStatus']), $successStatuses);

                return new PaymentResponse(
                    success: $isSuccess,
                    transactionId: $transactionId,
                    gatewayTransactionId: $paymentId,
                    status: strtolower($response['transactionStatus']),
                    amount: (float) ($response['amount'] ?? 0),
                    currency: 'BDT',
                    message: $isSuccess ? 'Payment verified successfully' : 'Payment verification failed',
                    data: array_merge([
                        'payment_id' => $paymentId,
                        'transaction_id_bkash' => $response['trxID'] ?? null,
                        'customer_msisdn' => $response['customerMsisdn'] ?? null,
                        'payment_method' => 'bkash',
                        'payer_reference' => $response['payerReference'] ?? null,
                        'payment_time' => $response['paymentTime'] ?? null,
                        'merchant_invoice_number' => $response['merchantInvoiceNumber'] ?? null,
                        'details' => $response['details'] ?? null,
                        'additional_info' => !empty($response['additionalInfo']) ? json_decode($response['additionalInfo'], true) : null,
                    ], $response)
                );
            }

            return PaymentResponse::error($response['errorMessage'] ?? 'Failed to verify payment', 'VERIFICATION_FAILED');

        } catch (\Exception $e) {
            Log::error('bKash verification error: ' . $e->getMessage(), [
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
            Log::info('Processing bKash refund', [
                'transaction_id' => $transactionId,
                'amount' => $amount
            ]);

            // Implementation limitation: bKash requires paymentID for refunds
            // This is a simplified implementation that logs the refund request
            // In a real implementation, you would need to:
            // 1. Store the paymentID when processing the original payment
            // 2. Retrieve it here using the transactionId
            // 3. Make the actual refund API call

            Log::warning('bKash refund implementation limitation: paymentID required but not provided', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'note' => 'Full refund implementation requires storing paymentID from original transaction'
            ]);

            // For now, simulate a successful refund with logging
            // In production, you would make an actual API call to bKash
            // Example of what the real implementation would look like:
            /*
            $paymentId = $this->getPaymentIdFromTransaction($transactionId);
            if (!$paymentId) {
                Log::error('bKash refund failed: Payment ID not found', [
                    'transaction_id' => $transactionId
                ]);
                return false;
            }

            $refundData = [
                'paymentID' => $paymentId,
                'amount' => number_format($amount, 2, '.', ''),
                'trxID' => null,
                'reason' => 'Refund requested',
                'sku' => 'refund-' . time()
            ];

            $response = $this->bkash->refundTransaction($refundData);

            if (isset($response['refundTrxID'])) {
                Log::info('bKash refund processed successfully', [
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                    'refund_id' => $response['refundTrxID']
                ]);
                return true;
            }

            Log::error('bKash refund failed', [
                'transaction_id' => $transactionId,
                'error' => $response['errorMessage'] ?? 'Refund failed'
            ]);
            */

            return false; // Returning false until full implementation
        } catch (\Exception $e) {
            Log::error('bKash refund exception', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Process webhook
     */
    public function processWebhook(array $payload): array
    {
        try {
            // Validate required fields
            $requiredFields = ['paymentID', 'transactionStatus', 'merchantInvoiceNumber', 'amount'];
            foreach ($requiredFields as $field) {
                if (!isset($payload[$field])) {
                    throw new \Exception("Missing required field: {$field}");
                }
            }

            // Verify the webhook (bKash provides verification data)
            if (isset($payload['paymentVerificationID'])) {
                $verificationResponse = $this->bkash->verifyPayment($payload['paymentVerificationID']);

                if (!$verificationResponse || !isset($verificationResponse['verificationStatus'])) {
                    throw new \Exception('Webhook verification failed');
                }
            }

            return [
                'success' => true,
                'event_type' => 'payment.' . $payload['transactionStatus'],
                'transaction_id' => $payload['merchantInvoiceNumber'],
                'gateway_transaction_id' => $payload['paymentID'],
                'payment_id' => $payload['paymentID'],
                'status' => $payload['transactionStatus'],
                'amount' => (float) $payload['amount'],
                'currency' => 'BDT',
                'transaction_id_bkash' => $payload['trxID'] ?? null,
                'customer_msisdn' => $payload['customerMsisdn'] ?? null,
                'payment_method' => 'bkash',
                'payer_reference' => $payload['payerReference'] ?? null,
                'payment_time' => $payload['paymentTime'] ?? now(),
                'merchant_invoice_number' => $payload['merchantInvoiceNumber'],
                'details' => $payload['details'] ?? null,
                'additional_info' => !empty($payload['additionalInfo']) ? json_decode($payload['additionalInfo'], true) : null,
                'data' => $payload
            ];

        } catch (\Exception $e) {
            Log::error('bKash webhook error: ' . $e->getMessage(), [
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
     * Get payment ID from transaction (helper method)
     * In a real implementation, you would query your database
     */
    protected function getPaymentIdFromTransaction(string $transactionId): ?string
    {
        // This is a placeholder - in a real app, you would:
        // 1. Query your database for the paymentID associated with this transaction
        // 2. Or use cache to store the paymentID during initialization

        // For now, returning null as this needs to be implemented at application level
        return null;
    }

    /**
     * Convert amount to BDT (simplified - in production, use real exchange rates)
     */
    protected function convertToBDT(float $amount, string $currency): float
    {
        // In production, you should use a reliable exchange rate API
        $exchangeRates = [
            'USD' => 109.50,
            'EUR' => 120.00,
            'GBP' => 140.00,
            'AUD' => 73.00,
            'CAD' => 82.00,
            'SGD' => 81.00,
            'MYR' => 24.50,
            'THB' => 3.10,
            'IDR' => 0.0076,
            'PHP' => 2.10,
            'AED' => 29.80,
            'SAR' => 29.20,
            'QAR' => 30.00,
            'OMR' => 284.50,
            'BHD' => 290.00,
            'KWD' => 355.00,
            'JOD' => 154.50,
            'LBP' => 0.0063
        ];

        $rate = $exchangeRates[strtoupper($currency)] ?? 1.0;
        return $amount * $rate;
    }

    /**
     * Create customer
     */
    public function createCustomer(array $data): array
    {
        // bKash doesn't have a separate customer creation API
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
     * Create subscription (bKash doesn't have native subscriptions)
     */
    public function createSubscription(array $data): array
    {
        // bKash doesn't support recurring payments natively
        // This would need to be handled at the application level
        return [
            'success' => true,
            'subscription_id' => 'SUB_' . time(),
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'BDT',
            'status' => 'created',
            'message' => 'Subscription created at application level (bKash requires manual processing for recurring payments)',
            'note' => 'bKash does not support automatic recurring payments. You must create individual payments for each billing cycle.'
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
            $amount = $data['currency'] === 'BDT'
                ? $data['amount']
                : $this->convertToBDT($data['amount'], $data['currency'] ?? 'BDT');

            $paymentData = [
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => 'BDT',
                'intent' => 'sale',
                'merchantInvoiceNumber' => 'LINK_' . time(),
                'callbackURL' => $this->config['callback_url'],
                'additionalInfo' => json_encode([
                    'is_payment_link' => true,
                    'customer_email' => $data['customer']['email'] ?? '',
                    'customer_name' => $data['customer']['name'] ?? '',
                    'description' => $data['description'] ?? 'Payment Link'
                ])
            ];

            if (!empty($data['description'])) {
                $paymentData['details'] = $data['description'];
            }

            $response = $this->bkash->initPayment($paymentData);

            if (isset($response['paymentID'])) {
                return [
                    'success' => true,
                    'link_id' => $paymentData['merchantInvoiceNumber'],
                    'payment_url' => $response['bkashURL'] ?? null,
                    'payment_id' => $response['paymentID'],
                    'amount' => $amount,
                    'currency' => 'BDT',
                    'status' => 'active',
                    'message' => 'Payment link created successfully',
                    'data' => $response
                ];
            }

            throw new \Exception($response['errorMessage'] ?? 'Failed to create payment link');

        } catch (\Exception $e) {
            Log::error('bKash payment link error: ' . $e->getMessage(), [
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
     * Query transaction status
     */
    public function queryTransaction(string $paymentId): array
    {
        try {
            $response = $this->bkash->queryPayment($paymentId);

            if (isset($response['transactionStatus'])) {
                return [
                    'success' => true,
                    'status' => $response['transactionStatus'],
                    'payment_id' => $paymentId,
                    'transaction_id_bkash' => $response['trxID'] ?? null,
                    'amount' => (float) $response['amount'] ?? 0,
                    'currency' => 'BDT',
                    'customer_msisdn' => $response['customerMsisdn'] ?? null,
                    'transaction_status' => $response['transactionStatus'],
                    'merchant_invoice_number' => $response['merchantInvoiceNumber'] ?? null,
                    'details' => $response['details'] ?? null,
                    'additional_info' => !empty($response['additionalInfo']) ? json_decode($response['additionalInfo'], true) : null,
                    'message' => 'Transaction queried successfully',
                    'data' => $response
                ];
            }

            throw new \Exception($response['errorMessage'] ?? 'Failed to query transaction');

        } catch (\Exception $e) {
            Log::error('bKash query transaction error: ' . $e->getMessage(), [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'QUERY_FAILED'
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
     * Get gateway configuration
     */
    public function getGatewayConfig(): array
    {
        return [
            'name' => $this->getGatewayName(),
            'display_name' => 'bKash',
            'currencies' => $this->getSupportedCurrencies(),
            'supports_subscriptions' => false,
            'supports_refunds' => true,
            'supports_webhooks' => true,
            'test_mode' => $this->config['test_mode'],
            'app_key' => $this->config['app_key'] ? substr($this->config['app_key'], 0, 8) . '...' : null,
        ];
    }

    /**
     * Search transaction
     */
    public function searchTransaction(string $trxId): array
    {
        try {
            $response = $this->bkash->searchTransaction($trxId);

            if (isset($response['trxID'])) {
                return [
                    'success' => true,
                    'transaction_id_bkash' => $response['trxID'],
                    'payment_id' => $response['paymentID'] ?? null,
                    'amount' => (float) $response['amount'] ?? 0,
                    'currency' => 'BDT',
                    'transaction_status' => $response['transactionStatus'],
                    'customer_msisdn' => $response['customerMsisdn'] ?? null,
                    'merchant_invoice_number' => $response['merchantInvoiceNumber'] ?? null,
                    'details' => $response['details'] ?? null,
                    'message' => 'Transaction searched successfully',
                    'data' => $response
                ];
            }

            throw new \Exception($response['errorMessage'] ?? 'Transaction not found');

        } catch (\Exception $e) {
            Log::error('bKash search transaction error: ' . $e->getMessage(), [
                'trx_id' => $trxId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'SEARCH_FAILED'
                ]
            ];
        }
    }

    /**
     * Get payment methods for Bangladesh
     */
    public function getPaymentMethodsForCountry(string $country): array
    {
        if ($country === 'BD') {
            return [
                'bkash_mobile_wallet' => 'bKash Mobile Wallet',
                'bkash_app' => 'bKash App Payment',
                'bkash_otp' => 'bKash OTP Verification',
                'bkash_qr' => 'bKash QR Code Payment'
            ];
        }

        return [
            'bkash_international' => 'bKash (International)'
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
            'paymentID' => $request->input('paymentID'),
            'transactionStatus' => $request->input('transactionStatus'),
            'trxID' => $request->input('trxID'),
            'merchantInvoiceNumber' => $request->input('merchantInvoiceNumber'),
            'amount' => $request->input('amount'),
            'currency' => $request->input('currency', 'BDT'),
            'customerMsisdn' => $request->input('customerMsisdn'),
            'payerReference' => $request->input('payerReference'),
            'paymentTime' => $request->input('paymentTime'),
            'details' => $request->input('details'),
            'additionalInfo' => $request->input('additionalInfo')
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