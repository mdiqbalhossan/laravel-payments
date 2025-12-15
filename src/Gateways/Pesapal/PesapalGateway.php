<?php

namespace Mdiqbal\LaravelPayments\Gateways\Pesapal;

use Mdiqbal\LaravelPayments\DTOs\PaymentRequest;
use Mdiqbal\LaravelPayments\DTOs\PaymentResponse;
use Mdiqbal\LaravelPayments\Gateways\AbstractGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PesapalGateway extends AbstractGateway
{
    /**
     * Gateway name
     */
    protected string $name = 'pesapal';

    /**
     * Supported currencies by Pesapal
     */
    protected array $supportedCurrencies = [
        'KES', // Kenyan Shilling
        'UGX', // Ugandan Shilling
        'TZS', // Tanzanian Shilling
        'RWF', // Rwandan Franc
        'MWK', // Malawian Kwacha
        'ZMW', // Zambian Kwacha
        'USD', // US Dollar
        'EUR', // Euro
        'GBP', // British Pound
    ];

    /**
     * API configuration
     */
    private string $consumerKey;
    private string $consumerSecret;
    private string $baseUrl;
    private bool $testMode;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->consumerKey = $this->config->get('consumer_key');
        $this->consumerSecret = $this->config->get('consumer_secret');
        $this->testMode = $this->config->get('test_mode', true);

        $this->baseUrl = $this->testMode
            ? 'https://cybqa.pesapal.com/pesapalv3/api'
            : 'https://pay.pesapal.com/v3/api';
    }

    /**
     * Process payment through Pesapal
     */
    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            // Get access token first
            $token = $this->getAccessToken();

            if (!$token) {
                return new PaymentResponse(
                    success: false,
                    message: 'Failed to authenticate with Pesapal',
                    errorCode: 'AUTH_FAILED'
                );
            }

            // Prepare payment data
            $paymentData = $this->preparePaymentData($request);

            // Submit order to Pesapal
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/Transactions/SubmitOrderRequest', $paymentData);

            $responseData = $response->json();

            Log::info('Pesapal payment request', [
                'request' => $paymentData,
                'response' => $responseData
            ]);

            if ($response->successful() && isset($responseData['order_id'])) {
                return new PaymentResponse(
                    success: true,
                    transactionId: $responseData['order_tracking_id'] ?? null,
                    redirectUrl: $responseData['redirect_url'] ?? null,
                    message: 'Payment order created successfully',
                    data: [
                        'order_id' => $responseData['order_id'] ?? null,
                        'order_tracking_id' => $responseData['order_tracking_id'] ?? null,
                        'merchant_reference' => $responseData['merchant_reference'] ?? null,
                        'redirect_url' => $responseData['redirect_url'] ?? null,
                        'callback_url' => $responseData['callback_url'] ?? null,
                        'notification_id' => $responseData['notification_id'] ?? null,
                        'currency' => $request->currency,
                        'amount' => $request->amount,
                    ]
                );
            }

            // Handle error response
            $errorMessage = $responseData['error']['message'] ?? 'Failed to create payment order';
            $errorCode = $responseData['error']['code'] ?? 'PAYMENT_ERROR';

            return new PaymentResponse(
                success: false,
                message: $errorMessage,
                errorCode: $errorCode,
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Pesapal payment error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new PaymentResponse(
                success: false,
                message: 'Payment processing failed: ' . $e->getMessage(),
                errorCode: 'PROCESSING_ERROR'
            );
        }
    }

    /**
     * Verify payment status with Pesapal
     */
    public function verify(array $data): PaymentResponse
    {
        try {
            $orderTrackingId = $data['order_tracking_id'] ?? null;

            if (!$orderTrackingId) {
                return new PaymentResponse(
                    success: false,
                    message: 'Order tracking ID is required'
                );
            }

            // Get access token
            $token = $this->getAccessToken();

            if (!$token) {
                return new PaymentResponse(
                    success: false,
                    message: 'Failed to authenticate with Pesapal'
                );
            }

            // Check transaction status
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/Transactions/GetTransactionStatus?orderTrackingId=' . $orderTrackingId);

            $responseData = $response->json();

            Log::info('Pesapal verification request', [
                'order_tracking_id' => $orderTrackingId,
                'response' => $responseData
            ]);

            if ($response->successful() && isset($responseData['status_code'])) {
                $status = $this->mapPesapalStatus($responseData['status_code']);
                $isSuccess = in_array($status, ['completed', 'success']);

                return new PaymentResponse(
                    success: $isSuccess,
                    transactionId: $responseData['order_tracking_id'] ?? null,
                    status: $status,
                    message: $responseData['status'] ?? 'Payment status retrieved',
                    data: [
                        'order_id' => $responseData['order_id'] ?? null,
                        'order_tracking_id' => $responseData['order_tracking_id'] ?? null,
                        'merchant_reference' => $responseData['merchant_reference'] ?? null,
                        'status_code' => $responseData['status_code'] ?? null,
                        'status' => $responseData['status'] ?? null,
                        'payment_method' => $responseData['payment_method'] ?? null,
                        'currency' => $responseData['currency'] ?? null,
                        'amount' => $responseData['amount'] ?? 0,
                        'confirmation_code' => $responseData['confirmation_code'] ?? null,
                        'payment_account' => $responseData['payment_account'] ?? null,
                        'call_back_url' => $responseData['call_back_url'] ?? null,
                        'created_date' => $responseData['created_date'] ?? null,
                        'payment_date' => $responseData['payment_date'] ?? null,
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: 'Unable to verify payment status',
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Pesapal verification error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new PaymentResponse(
                success: false,
                message: 'Verification failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Process refund through Pesapal
     */
    public function refund(array $data): PaymentResponse
    {
        try {
            $orderTrackingId = $data['order_tracking_id'] ?? null;
            $refundAmount = $data['amount'] ?? null;
            $refundReason = $data['reason'] ?? 'Customer requested refund';

            if (!$orderTrackingId || !$refundAmount) {
                return new PaymentResponse(
                    success: false,
                    message: 'Order tracking ID and refund amount are required'
                );
            }

            // Get access token
            $token = $this->getAccessToken();

            if (!$token) {
                return new PaymentResponse(
                    success: false,
                    message: 'Failed to authenticate with Pesapal'
                );
            }

            // Prepare refund data
            $refundData = [
                'order_tracking_id' => $orderTrackingId,
                'amount' => $refundAmount,
                'username' => $this->config->get('username', ''),
                'reason' => $refundReason,
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/Transactions/RefundOrder', $refundData);

            $responseData = $response->json();

            Log::info('Pesapal refund request', [
                'request' => $refundData,
                'response' => $responseData
            ]);

            if ($response->successful() && isset($responseData['status_code']) && $responseData['status_code'] == 1) {
                return new PaymentResponse(
                    success: true,
                    transactionId: $responseData['order_tracking_id'] ?? null,
                    message: 'Refund processed successfully',
                    data: [
                        'refund_status' => 'processed',
                        'refund_amount' => $responseData['amount'] ?? 0,
                        'refund_reference' => $responseData['refund_reference'] ?? null,
                    ]
                );
            }

            $errorMessage = $responseData['error']['message'] ?? 'Refund failed';

            return new PaymentResponse(
                success: false,
                message: $errorMessage,
                errorCode: $responseData['error']['code'] ?? 'REFUND_ERROR',
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Pesapal refund error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new PaymentResponse(
                success: false,
                message: 'Refund processing failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Process webhook from Pesapal
     */
    public function processWebhook(array $data): PaymentResponse
    {
        try {
            Log::info('Pesapal webhook received', ['data' => $data]);

            // Validate webhook data structure
            if (!isset($data['order_tracking_id']) || !isset($data['status_code'])) {
                return new PaymentResponse(
                    success: false,
                    message: 'Invalid webhook data structure'
                );
            }

            // Extract payment information
            $orderTrackingId = $data['order_tracking_id'];
            $statusCode = $data['status_code'];
            $status = $this->mapPesapalStatus($statusCode);

            return new PaymentResponse(
                success: true,
                transactionId: $orderTrackingId,
                status: $status,
                message: 'Webhook processed successfully',
                data: [
                    'webhook_type' => 'payment_status',
                    'order_tracking_id' => $orderTrackingId,
                    'order_id' => $data['order_id'] ?? null,
                    'merchant_reference' => $data['merchant_reference'] ?? null,
                    'status_code' => $statusCode,
                    'status' => $data['status'] ?? null,
                    'payment_method' => $data['payment_method'] ?? null,
                    'currency' => $data['currency'] ?? null,
                    'amount' => $data['amount'] ?? 0,
                    'confirmation_code' => $data['confirmation_code'] ?? null,
                    'payment_account' => $data['payment_account'] ?? null,
                    'call_back_url' => $data['call_back_url'] ?? null,
                    'notification_id' => $data['notification_id'] ?? null,
                    'payment_date' => $data['payment_date'] ?? null,
                ]
            );

        } catch (\Exception $e) {
            Log::error('Pesapal webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new PaymentResponse(
                success: false,
                message: 'Webhook processing failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Create payment link for Pesapal
     */
    public function createPaymentLink(array $data): PaymentResponse
    {
        try {
            // Get access token
            $token = $this->getAccessToken();

            if (!$token) {
                return new PaymentResponse(
                    success: false,
                    message: 'Failed to authenticate with Pesapal',
                    errorCode: 'AUTH_FAILED'
                );
            }

            // Prepare payment link data
            $linkData = [
                'id' => $data['id'] ?? 'LINK_' . time(),
                'currency' => $data['currency'] ?? 'KES',
                'amount' => $data['amount'],
                'description' => $data['description'] ?? 'Payment via Pesapal',
                'redirect_url' => $data['redirect_url'] ?? $this->config->get('return_url'),
                'callback_url' => $data['callback_url'] ?? $this->config->get('webhook_url'),
                'customer_email' => $data['customer_email'] ?? '',
                'customer_phone' => $data['customer_phone'] ?? '',
                'customer_name' => $data['customer_name'] ?? '',
            ];

            // Submit order to Pesapal
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/Transactions/SubmitOrderRequest', $linkData);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['redirect_url'])) {
                return new PaymentResponse(
                    success: true,
                    transactionId: $responseData['order_tracking_id'] ?? null,
                    redirectUrl: $responseData['redirect_url'] ?? null,
                    message: 'Payment link created successfully',
                    data: [
                        'order_id' => $responseData['order_id'] ?? null,
                        'order_tracking_id' => $responseData['order_tracking_id'] ?? null,
                        'payment_url' => $responseData['redirect_url'] ?? null,
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: $responseData['error']['message'] ?? 'Failed to create payment link'
            );

        } catch (\Exception $e) {
            Log::error('Pesapal payment link creation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new PaymentResponse(
                success: false,
                message: 'Failed to create payment link: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get access token from Pesapal
     */
    private function getAccessToken(): ?string
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/Auth/GetToken', [
                'consumer_key' => $this->consumerKey,
                'consumer_secret' => $this->consumerSecret,
            ]);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['token'])) {
                return $responseData['token'];
            }

            Log::error('Pesapal authentication failed', [
                'response' => $responseData
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Pesapal authentication error', [
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Prepare payment data for Pesapal API
     */
    private function preparePaymentData(PaymentRequest $request): array
    {
        $paymentData = [
            'id' => $request->orderId ?? 'ORDER_' . uniqid(),
            'currency' => $request->currency,
            'amount' => $request->amount,
            'description' => $request->description ?? 'Payment via Pesapal',
            'callback_url' => $request->notifyUrl ?? $this->config->get('webhook_url'),
            'redirect_mode' => 'REDIRECT', // or POPUP
            'branch' => $this->config->get('branch', ''),
            'language' => $request->metadata['language'] ?? 'EN', // EN or SW for Swahili
        ];

        // Add customer information if available
        if ($request->customer) {
            $customer = $request->customer;
            $paymentData['first_name'] = $this->extractFirstName($customer['name'] ?? '');
            $paymentData['last_name'] = $this->extractLastName($customer['name'] ?? '');
            $paymentData['email_address'] = $customer['email'] ?? '';
            $paymentData['phonenumber'] = $customer['phone'] ?? '';
        }

        // Add billing address for card payments
        if ($request->customer && !empty($customer['address'])) {
            $paymentData['billing_address'] = $customer['address'];
            $paymentData['billing_city'] = $customer['city'] ?? '';
            $paymentData['billing_country'] = $customer['country'] ?? '';
            $paymentData['billing_zip'] = $customer['postal_code'] ?? '';
        }

        // Add return URL
        if ($request->returnUrl) {
            $paymentData['result_url'] = $request->returnUrl;
        }

        // Add payment method preference if specified
        if (isset($request->metadata['payment_method'])) {
            $paymentData['payment_methods'] = $request->metadata['payment_method'];
        }

        return $paymentData;
    }

    /**
     * Map Pesapal status codes to standard status
     */
    private function mapPesapalStatus(?string $pesapalStatus): string
    {
        $statusMap = [
            'PENDING' => 'pending',
            'COMPLETED' => 'completed',
            'FAILED' => 'failed',
            'CANCELLED' => 'cancelled',
            'REFUNDED' => 'refunded',
            '0' => 'pending',
            '1' => 'completed',
            '2' => 'failed',
            '3' => 'cancelled',
        ];

        return $statusMap[$pesapalStatus] ?? 'pending';
    }

    /**
     * Extract first name from full name
     */
    private function extractFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }

    /**
     * Extract last name from full name
     */
    private function extractLastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
    }

    /**
     * Check if currency is supported
     */
    protected function isCurrencySupported(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supportedCurrencies);
    }

    /**
     * Convert amount to smallest unit if needed
     */
    protected function convertAmount(float $amount): float
    {
        // Pesapal expects amounts in decimal format (e.g., 100.00)
        return $amount;
    }

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods(): array
    {
        return [
            'card',           // Credit/Debit Cards (Visa, Mastercard)
            'mobile',        // Mobile Money (M-Pesa, Airtel Money, Tigo Pesa)
            'bank_transfer', // Bank Transfer
            'pesapal_wallet', // Pesapal Wallet
        ];
    }

    /**
     * Get gateway configuration schema
     */
    public function getConfigSchema(): array
    {
        return [
            'consumer_key' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Consumer Key',
                'description' => 'Your Pesapal Consumer Key'
            ],
            'consumer_secret' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Consumer Secret',
                'description' => 'Your Pesapal Consumer Secret'
            ],
            'test_mode' => [
                'type' => 'boolean',
                'default' => true,
                'label' => 'Test Mode',
                'description' => 'Enable sandbox mode for testing'
            ],
            'webhook_url' => [
                'type' => 'url',
                'label' => 'Webhook URL',
                'description' => 'URL to receive payment notifications'
            ],
            'return_url' => [
                'type' => 'url',
                'label' => 'Return URL',
                'description' => 'URL to redirect after payment'
            ],
            'branch' => [
                'type' => 'string',
                'label' => 'Branch',
                'description' => 'Branch identifier for your business'
            ],
        ];
    }
}