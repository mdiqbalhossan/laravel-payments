<?php

namespace Mdiqbal\LaravelPayments\Gateways\Bkash;

use Mdiqbal\LaravelPayments\AbstractGateway;
use Mdiqbal\LaravelPayments\DTOs\PaymentRequest;
use Mdiqbal\LaravelPayments\DTOs\PaymentResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use SabitAhmad\LaravelBkash\BkashPayment;

class BkashGateway extends AbstractGateway
{
    /**
     * bKash instance
     */
    protected $bkash;

    /**
     * Gateway configuration
     */
    protected $config;

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
        $this->bkash = new BkashPayment();

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
    }

    /**
     * Get gateway name
     */
    public function getGatewayName(): string
    {
        return 'bkash';
    }

    /**
     * Process payment
     */
    public function pay(array $data): array
    {
        try {
            $paymentRequest = new PaymentRequest($data);

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
                return [
                    'success' => true,
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
                ];
            }

            throw new \Exception($response['errorMessage'] ?? 'Failed to initialize payment');

        } catch (\Exception $e) {
            Log::error('bKash payment error: ' . $e->getMessage(), [
                'transaction_id' => $paymentRequest->transaction_id ?? null,
                'amount' => $paymentRequest->amount ?? null,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'PAYMENT_FAILED'
                ]
            ];
        }
    }

    /**
     * Verify payment
     */
    public function verify(string $transactionId): array
    {
        try {
            // For bKash, we need the paymentID to verify
            // You might need to store the paymentID when initializing payment

            // If you have stored the paymentID, use it
            // Otherwise, you can query your database to find the paymentID for this transaction

            $paymentId = $this->getPaymentIdFromTransaction($transactionId);

            if (!$paymentId) {
                throw new \Exception('Payment ID not found for transaction');
            }

            // Execute payment to verify status
            $response = $this->bkash->executePayment($paymentId);

            if (isset($response['transactionStatus'])) {
                $response['transactionId'] = $transactionId;

                return [
                    'success' => true,
                    'status' => $response['transactionStatus'],
                    'transaction_id' => $transactionId,
                    'gateway_transaction_id' => $paymentId,
                    'payment_id' => $paymentId,
                    'amount' => (float) $response['amount'] ?? 0,
                    'currency' => 'BDT',
                    'transaction_id_bkash' => $response['trxID'] ?? null,
                    'customer_msisdn' => $response['customerMsisdn'] ?? null,
                    'payment_method' => 'bkash',
                    'payer_reference' => $response['payerReference'] ?? null,
                    'payment_time' => $response['paymentTime'] ?? null,
                    'transaction_status' => $response['transactionStatus'],
                    'merchant_invoice_number' => $response['merchantInvoiceNumber'] ?? null,
                    'details' => $response['details'] ?? null,
                    'additional_info' => !empty($response['additionalInfo']) ? json_decode($response['additionalInfo'], true) : null,
                    'message' => 'Payment retrieved successfully',
                    'data' => $response
                ];
            }

            throw new \Exception($response['errorMessage'] ?? 'Failed to verify payment');

        } catch (\Exception $e) {
            Log::error('bKash verification error: ' . $e->getMessage(), [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'VERIFICATION_FAILED'
                ]
            ];
        }
    }

    /**
     * Process refund
     */
    public function refund(array $data): array
    {
        try {
            if (!isset($data['payment_id']) || !isset($data['amount'])) {
                throw new \InvalidArgumentException('Payment ID and amount are required for refund');
            }

            $refundData = [
                'paymentID' => $data['payment_id'],
                'amount' => number_format($data['amount'], 2, '.', ''),
                'trxID' => $data['trx_id'] ?? null,
                'reason' => $data['reason'] ?? 'Refund requested',
                'sku' => $data['sku'] ?? 'refund-' . time()
            ];

            // Process refund
            $response = $this->bkash->refundTransaction($refundData);

            if (isset($response['refundTrxID'])) {
                return [
                    'success' => true,
                    'refund_id' => $response['refundTrxID'] ?? null,
                    'payment_id' => $data['payment_id'],
                    'amount' => (float) $data['amount'],
                    'currency' => 'BDT',
                    'status' => 'processed',
                    'transaction_id' => $response['originalTrxID'] ?? null,
                    'refund_time' => $response['completedOn'] ?? now(),
                    'message' => 'Refund processed successfully',
                    'data' => $response
                ];
            }

            throw new \Exception($response['errorMessage'] ?? 'Refund failed');

        } catch (\Exception $e) {
            Log::error('bKash refund error: ' . $e->getMessage(), [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'REFUND_FAILED'
                ]
            ];
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
}