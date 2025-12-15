<?php

namespace Mdiqbal\LaravelPayments\Gateways\Telr;

use Mdiqbal\LaravelPayments\DTOs\PaymentRequest;
use Mdiqbal\LaravelPayments\DTOs\PaymentResponse;
use Mdiqbal\LaravelPayments\Gateways\AbstractGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelrGateway extends AbstractGateway
{
    /**
     * Gateway name
     */
    protected string $name = 'telr';

    /**
     * Supported currencies by Telr
     */
    protected array $supportedCurrencies = [
        'AED', 'USD', 'EUR', 'GBP', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR',
        'JOD', 'EGP', 'LBP', 'SYP', 'IQD', 'LYD', 'TND', 'DZD', 'MAD',
        'INR', 'PKR', 'LKR', 'BDT', 'NPR', 'AFN', 'MVR'
    ];

    /**
     * API endpoints
     */
    private string $apiEndpoint;
    private string $storeId;
    private string $authKey;
    private bool $testMode;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->testMode = $this->config->get('test_mode', true);
        $this->storeId = $this->config->get('store_id');
        $this->authKey = $this->config->get('auth_key');

        $this->apiEndpoint = $this->testMode
            ? 'https://secure.telr.com/gateway/order.json'
            : 'https://secure.telr.com/gateway/order.json';
    }

    /**
     * Process payment through Telr
     */
    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            // Prepare order data
            $orderData = $this->prepareOrderData($request);

            // Create order/session with Telr
            $response = Http::asForm()->post($this->apiEndpoint, $orderData);

            $responseData = $response->json();

            Log::info('Telr payment request', [
                'request' => $orderData,
                'response' => $responseData
            ]);

            if ($responseData && isset($responseData['order']) && $responseData['order']['status'] === 'created') {
                $telrOrder = $responseData['order'];

                return new PaymentResponse(
                    success: true,
                    transactionId: $telrOrder['ref'] ?? null,
                    redirectUrl: $telrOrder['url'] ?? null,
                    message: 'Payment session created successfully',
                    data: [
                        'telr_ref' => $telrOrder['ref'] ?? null,
                        'telr_transaction_id' => $telrOrder['transaction_id'] ?? null,
                        'session_id' => $telrOrder['cart_id'] ?? null,
                        'payment_url' => $telrOrder['url'] ?? null,
                        'amount' => $request->amount,
                        'currency' => $request->currency,
                    ]
                );
            }

            // Handle error response
            $errorMessage = 'Failed to create payment session';
            $errorCode = $responseData['order']['error']['code'] ?? 'UNKNOWN_ERROR';

            if (isset($responseData['order']['error']['message'])) {
                $errorMessage = $responseData['order']['error']['message'];
            }

            return new PaymentResponse(
                success: false,
                message: $errorMessage,
                errorCode: $errorCode,
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Telr payment error', [
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
     * Verify payment status with Telr
     */
    public function verify(array $data): PaymentResponse
    {
        try {
            $transactionRef = $data['transaction_ref'] ?? null;
            $cartId = $data['cart_id'] ?? null;

            if (!$transactionRef && !$cartId) {
                return new PaymentResponse(
                    success: false,
                    message: 'Transaction reference or Cart ID is required'
                );
            }

            // Prepare query data
            $queryData = [
                'method' => 'check',
                'store_id' => $this->storeId,
                'auth_key' => $this->authKey,
            ];

            if ($transactionRef) {
                $queryData['ref'] = $transactionRef;
            }

            if ($cartId) {
                $queryData['cart_id'] = $cartId;
            }

            $response = Http::asForm()->post($this->apiEndpoint, $queryData);
            $responseData = $response->json();

            Log::info('Telr verification request', [
                'query' => $queryData,
                'response' => $responseData
            ]);

            if (isset($responseData['order'])) {
                $order = $responseData['order'];
                $status = $order['status']['code'] ?? null;

                $paymentStatus = $this->mapTelrStatus($status);
                $isSuccess = in_array($paymentStatus, ['completed', 'success']);

                return new PaymentResponse(
                    success: $isSuccess,
                    transactionId: $order['ref'] ?? null,
                    status: $paymentStatus,
                    message: $order['status']['text'] ?? 'Payment status retrieved',
                    data: [
                        'telr_ref' => $order['ref'] ?? null,
                        'telr_transaction_id' => $order['transaction_id'] ?? null,
                        'cart_id' => $order['cart_id'] ?? null,
                        'status_code' => $status,
                        'status_text' => $order['status']['text'] ?? null,
                        'amount' => $order['amount'] ?? 0,
                        'currency' => $order['currency'] ?? null,
                        'customer_email' => $order['customer']['email'] ?? null,
                        'payment_method' => $order['payment']['method'] ?? null,
                        'card_type' => $order['payment']['card']['type'] ?? null,
                        'card_last4' => $order['payment']['card']['last4'] ?? null,
                        'transaction_date' => $order['transaction']['date'] ?? null,
                        'authorization_code' => $order['transaction']['authorization'] ?? null,
                        'avs_response' => $order['transaction']['avs'] ?? null,
                        'cvv_response' => $order['transaction']['cvv'] ?? null,
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: 'Unable to verify payment status',
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Telr verification error', [
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
     * Process refund through Telr
     */
    public function refund(array $data): PaymentResponse
    {
        try {
            $transactionRef = $data['transaction_ref'] ?? null;
            $refundAmount = $data['amount'] ?? null;
            $refundReason = $data['reason'] ?? 'Customer requested refund';

            if (!$transactionRef || !$refundAmount) {
                return new PaymentResponse(
                    success: false,
                    message: 'Transaction reference and refund amount are required'
                );
            }

            // Prepare refund data
            $refundData = [
                'method' => 'refund',
                'store_id' => $this->storeId,
                'auth_key' => $this->authKey,
                'ref' => $transactionRef,
                'amount' => number_format($refundAmount, 2, '.', ''),
                'reason' => $refundReason
            ];

            $response = Http::asForm()->post($this->apiEndpoint, $refundData);
            $responseData = $response->json();

            Log::info('Telr refund request', [
                'request' => $refundData,
                'response' => $responseData
            ]);

            if (isset($responseData['order']) && $responseData['order']['status']['code'] == 3) {
                $order = $responseData['order'];

                return new PaymentResponse(
                    success: true,
                    transactionId: $order['ref'] ?? null,
                    message: 'Refund processed successfully',
                    data: [
                        'refund_ref' => $order['ref'] ?? null,
                        'refund_amount' => $order['amount'] ?? 0,
                        'refund_status' => 'processed',
                        'refund_transaction_id' => $order['transaction_id'] ?? null,
                    ]
                );
            }

            // Handle refund error
            $errorMessage = 'Refund failed';
            if (isset($responseData['order']['status']['text'])) {
                $errorMessage = $responseData['order']['status']['text'];
            }

            return new PaymentResponse(
                success: false,
                message: $errorMessage,
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Telr refund error', [
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
     * Process webhook from Telr
     */
    public function processWebhook(array $data): PaymentResponse
    {
        try {
            Log::info('Telr webhook received', ['data' => $data]);

            // Validate webhook data
            if (!isset($data['order']) || !isset($data['hash'])) {
                return new PaymentResponse(
                    success: false,
                    message: 'Invalid webhook data structure'
                );
            }

            $orderData = $data['order'];
            $receivedHash = $data['hash'];

            // Verify webhook signature
            $calculatedHash = $this->calculateWebhookHash($orderData);

            if (!hash_equals($calculatedHash, $receivedHash)) {
                Log::warning('Telr webhook signature mismatch', [
                    'received' => $receivedHash,
                    'calculated' => $calculatedHash
                ]);

                return new PaymentResponse(
                    success: false,
                    message: 'Invalid webhook signature'
                );
            }

            // Extract payment information
            $status = $orderData['status']['code'] ?? null;
            $paymentStatus = $this->mapTelrStatus($status);

            return new PaymentResponse(
                success: true,
                transactionId: $orderData['ref'] ?? null,
                status: $paymentStatus,
                message: 'Webhook processed successfully',
                data: [
                    'webhook_type' => 'payment_status',
                    'telr_ref' => $orderData['ref'] ?? null,
                    'telr_transaction_id' => $orderData['transaction_id'] ?? null,
                    'cart_id' => $orderData['cart_id'] ?? null,
                    'status_code' => $status,
                    'status_text' => $orderData['status']['text'] ?? null,
                    'amount' => $orderData['amount'] ?? 0,
                    'currency' => $orderData['currency'] ?? null,
                    'customer_email' => $orderData['customer']['email'] ?? null,
                    'payment_method' => $orderData['payment']['method'] ?? null,
                    'card_type' => $orderData['payment']['card']['type'] ?? null,
                    'card_last4' => $orderData['payment']['card']['last4'] ?? null,
                    'transaction_date' => $orderData['transaction']['date'] ?? null,
                    'authorization_code' => $orderData['transaction']['authorization'] ?? null,
                ]
            );

        } catch (\Exception $e) {
            Log::error('Telr webhook processing error', [
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
     * Create payment link for Telr
     */
    public function createPaymentLink(array $data): PaymentResponse
    {
        try {
            $paymentRequest = new PaymentRequest(
                amount: $data['amount'],
                currency: $data['currency'] ?? 'USD',
                orderId: $data['order_id'] ?? 'ORDER_' . time(),
                description: $data['description'] ?? 'Payment via Telr',
                customer: [
                    'name' => $data['customer_name'] ?? '',
                    'email' => $data['customer_email'] ?? '',
                    'phone' => $data['customer_phone'] ?? '',
                    'address' => $data['customer_address'] ?? '',
                    'city' => $data['customer_city'] ?? '',
                    'state' => $data['customer_state'] ?? '',
                    'country' => $data['customer_country'] ?? '',
                    'postal_code' => $data['customer_postal_code'] ?? '',
                ],
                returnUrl: $data['return_url'] ?? $this->config->get('return_url'),
                cancelUrl: $data['cancel_url'] ?? $this->config->get('cancel_url'),
                notifyUrl: $data['notify_url'] ?? $this->config->get('webhook_url'),
                metadata: $data['metadata'] ?? []
            );

            // Add payment method specific settings
            if (isset($data['payment_method'])) {
                $paymentRequest->metadata['payment_method'] = $data['payment_method'];
            }

            // Create payment session
            return $this->pay($paymentRequest);

        } catch (\Exception $e) {
            Log::error('Telr payment link creation error', [
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
     * Prepare order data for Telr API
     */
    private function prepareOrderData(PaymentRequest $request): array
    {
        // Generate unique cart ID
        $cartId = 'cart_' . time() . '_' . Str::random(8);

        $orderData = [
            'method' => 'create',
            'store_id' => $this->storeId,
            'auth_key' => $this->authKey,
            'cart_id' => $cartId,
            'cart_description' => $request->description ?? 'Payment via Telr',
            'cart_currency' => $request->currency,
            'cart_amount' => number_format($request->amount, 2, '.', ''),
            'return_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl,
        ];

        // Add customer information
        if ($request->customer) {
            $customer = $request->customer;

            $orderData['billing_name'] = $customer['name'] ?? '';
            $orderData['billing_email'] = $customer['email'] ?? '';
            $orderData['billing_phone'] = $customer['phone'] ?? '';

            if (!empty($customer['address'])) {
                $orderData['billing_address'] = $customer['address'];
            }

            if (!empty($customer['city'])) {
                $orderData['billing_city'] = $customer['city'];
            }

            if (!empty($customer['state'])) {
                $orderData['billing_state'] = $customer['state'];
            }

            if (!empty($customer['country'])) {
                $orderData['billing_country'] = $customer['country'];
            }

            if (!empty($customer['postal_code'])) {
                $orderData['billing_zip'] = $customer['postal_code'];
            }
        }

        // Add return parameters
        if ($request->orderId) {
            $orderData['return_url'] .= (strpos($orderData['return_url'], '?') === false ? '?' : '&') . 'order_id=' . urlencode($request->orderId);
        }

        // Add language preference
        $orderData['language'] = 'en';

        // Add frame option (iframe enabled)
        $orderData['frame'] = '0';

        return $orderData;
    }

    /**
     * Map Telr status codes to standard status
     */
    private function mapTelrStatus(?string $telrStatus): string
    {
        $statusMap = [
            '2' => 'authorized',  // Payment authorized
            '3' => 'completed',   // Payment completed
            '4' => 'pending',     // Payment pending
            '5' => 'refunded',    // Payment refunded
            '6' => 'voided',      // Payment voided
            '7' => 'failed',      // Payment failed
            '8' => 'cancelled',   // Payment cancelled
        ];

        return $statusMap[$telrStatus] ?? 'pending';
    }

    /**
     * Calculate webhook hash for verification
     */
    private function calculateWebhookHash(array $orderData): string
    {
        // Create hash string based on order data
        $hashString = $orderData['store_id'] ?? '';
        $hashString .= '|' . ($orderData['auth_key'] ?? '');
        $hashString .= '|' . ($orderData['ref'] ?? '');
        $hashString .= '|' . ($orderData['cart_id'] ?? '');
        $hashString .= '|' . ($orderData['amount'] ?? '');
        $hashString .= '|' . ($orderData['currency'] ?? '');
        $hashString .= '|' . ($orderData['status']['code'] ?? '');

        return hash('sha256', $hashString);
    }

    /**
     * Check if currency is supported
     */
    protected function isCurrencySupported(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supportedCurrencies);
    }

    /**
     * Convert amount to cents if needed
     */
    protected function convertAmount(float $amount): float
    {
        // Telr expects amount in decimal format (e.g., 100.00)
        return $amount;
    }

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods(): array
    {
        return [
            'card',
            'apple_pay',
            'samsung_pay',
            'sadad',  // Saudi Arabia
            'knet',   // Kuwait
            'fawry',  // Egypt
            'naps',   // Oman
        ];
    }

    /**
     * Get gateway configuration schema
     */
    public function getConfigSchema(): array
    {
        return [
            'store_id' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Store ID',
                'description' => 'Your Telr Store ID'
            ],
            'auth_key' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Authentication Key',
                'description' => 'Your Telr Authentication Key'
            ],
            'test_mode' => [
                'type' => 'boolean',
                'default' => true,
                'label' => 'Test Mode',
                'description' => 'Enable test mode for transactions'
            ],
            'return_url' => [
                'type' => 'url',
                'label' => 'Return URL',
                'description' => 'URL to redirect after successful payment'
            ],
            'cancel_url' => [
                'type' => 'url',
                'label' => 'Cancel URL',
                'description' => 'URL to redirect after cancelled payment'
            ],
            'webhook_url' => [
                'type' => 'url',
                'label' => 'Webhook URL',
                'description' => 'URL to receive payment notifications'
            ],
        ];
    }
}