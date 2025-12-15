<?php

namespace Mdiqbal\LaravelPayments\Gateways\Iyzico;

use Mdiqbal\LaravelPayments\DTOs\PaymentRequest;
use Mdiqbal\LaravelPayments\DTOs\PaymentResponse;
use Mdiqbal\LaravelPayments\Gateways\AbstractGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IyzicoGateway extends AbstractGateway
{
    /**
     * Gateway name
     */
    protected string $name = 'iyzico';

    /**
     * Supported currencies by Iyzico
     */
    protected array $supportedCurrencies = [
        'TRY', // Turkish Lira
        'USD', // US Dollar
        'EUR', // Euro
        'GBP', // British Pound
        'NOK', // Norwegian Krone
        'CHF', // Swiss Franc
    ];

    /**
     * API configuration
     */
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;
    private bool $testMode;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->apiKey = $this->config->get('api_key');
        $this->secretKey = $this->config->get('secret_key');
        $this->testMode = $this->config->get('test_mode', true);

        $this->baseUrl = $this->testMode
            ? 'https://sandbox-api.iyzipay.com'
            : 'https://api.iyzipay.com';
    }

    /**
     * Process payment through Iyzico
     */
    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            // Prepare payment data
            $paymentData = $this->preparePaymentData($request);

            // Make API request
            $response = Http::withHeaders([
                'Authorization' => $this->generateAuthorizationHeader(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/payment/auth', $paymentData);

            $responseData = $response->json();

            Log::info('Iyzico payment request', [
                'request' => $paymentData,
                'response' => $responseData
            ]);

            // Check if request was successful
            if ($response->successful() && isset($responseData['status']) && $responseData['status'] === 'success') {
                $paymentData = $responseData;

                return new PaymentResponse(
                    success: true,
                    transactionId: $paymentData['paymentId'] ?? null,
                    message: 'Payment processed successfully',
                    status: 'completed',
                    data: [
                        'payment_id' => $paymentData['paymentId'] ?? null,
                        'price' => $paymentData['price'] ?? 0,
                        'paid_price' => $paymentData['paidPrice'] ?? 0,
                        'currency' => $paymentData['currency'] ?? $request->currency,
                        'installment' => $paymentData['installment'] ?? 1,
                        'payment_status' => $paymentData['paymentStatus'] ?? null,
                        'auth_code' => $paymentData['authCode'] ?? null,
                        'host_reference' => $paymentData['hostReference'] ?? null,
                        'basket_id' => $paymentData['basketId'] ?? null,
                        'conversation_id' => $paymentData['conversationId'] ?? null,
                        'fraud_status' => $paymentData['fraudStatus'] ?? null,
                        'merchant_commission_rate' => $paymentData['merchantCommissionRate'] ?? 0,
                        'merchant_commission_amount' => $paymentData['merchantCommissionAmount'] ?? 0,
                        'iyzi_commission_rate' => $paymentData['iyziCommissionRate'] ?? 0,
                        'iyzi_commission_amount' => $paymentData['iyziCommissionAmount'] ?? 0,
                        'item_transactions' => $paymentData['itemTransactions'] ?? [],
                    ]
                );
            }

            // Handle error response
            $errorMessage = $responseData['errorMessage'] ?? 'Payment processing failed';
            $errorCode = $responseData['errorCode'] ?? 'PAYMENT_ERROR';

            return new PaymentResponse(
                success: false,
                message: $errorMessage,
                errorCode: $errorCode,
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Iyzico payment error', [
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
     * Create checkout form payment
     */
    public function createCheckoutForm(array $data): PaymentResponse
    {
        try {
            $checkoutData = [
                'locale' => $data['locale'] ?? 'tr',
                'conversationId' => $data['conversation_id'] ?? 'conv_' . uniqid(),
                'price' => $data['price'],
                'paidPrice' => $data['paid_price'] ?? $data['price'],
                'currency' => $data['currency'] ?? 'TRY',
                'basketId' => $data['basket_id'] ?? 'B' . uniqid(),
                'paymentGroup' => $data['payment_group'] ?? 'PRODUCT',
                'callbackUrl' => $data['callback_url'] ?? $this->config->get('callback_url'),
                'enabledInstallments' => $data['enabled_installments'] ?? [2, 3, 6, 9],
                'buyer' => [
                    'id' => $data['buyer']['id'] ?? 'BY' . uniqid(),
                    'name' => $data['buyer']['name'] ?? '',
                    'surname' => $data['buyer']['surname'] ?? '',
                    'identityNumber' => $data['buyer']['identity_number'] ?? '10000000000',
                    'email' => $data['buyer']['email'] ?? '',
                    'gsmNumber' => $data['buyer']['phone'] ?? '',
                    'registrationDate' => $data['buyer']['registration_date'] ?? '2021-01-01 00:00:00',
                    'lastLoginDate' => $data['buyer']['last_login_date'] ?? '2021-01-01 00:00:00',
                    'registrationAddress' => $data['buyer']['address'] ?? '',
                    'city' => $data['buyer']['city'] ?? '',
                    'country' => $data['buyer']['country'] ?? 'Turkey',
                    'zipCode' => $data['buyer']['zip_code'] ?? '',
                    'ip' => $data['buyer']['ip'] ?? request()->ip(),
                ],
                'shippingAddress' => [
                    'address' => $data['shipping_address']['address'] ?? '',
                    'zipCode' => $data['shipping_address']['zip_code'] ?? '',
                    'contactName' => $data['shipping_address']['contact_name'] ?? '',
                    'city' => $data['shipping_address']['city'] ?? '',
                    'country' => $data['shipping_address']['country'] ?? 'Turkey',
                ],
                'billingAddress' => [
                    'address' => $data['billing_address']['address'] ?? '',
                    'zipCode' => $data['billing_address']['zip_code'] ?? '',
                    'contactName' => $data['billing_address']['contact_name'] ?? '',
                    'city' => $data['billing_address']['city'] ?? '',
                    'country' => $data['billing_address']['country'] ?? 'Turkey',
                ],
                'basketItems' => $data['basket_items'] ?? [],
            ];

            $response = Http::withHeaders([
                'Authorization' => $this->generateAuthorizationHeader(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/payment/iyzipos/checkoutform/init/ecom', $checkoutData);

            $responseData = $response->json();

            Log::info('Iyzico checkout form request', [
                'request' => $checkoutData,
                'response' => $responseData
            ]);

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] === 'success') {
                $checkoutPageUrl = $responseData['checkoutFormContent'] ?? $responseData['paymentPageUrl'] ?? null;

                return new PaymentResponse(
                    success: true,
                    transactionId: $responseData['token'] ?? null,
                    redirectUrl: $checkoutPageUrl,
                    message: 'Checkout form created successfully',
                    data: [
                        'token' => $responseData['token'] ?? null,
                        'checkout_form_content' => $responseData['checkoutFormContent'] ?? null,
                        'payment_page_url' => $responseData['paymentPageUrl'] ?? null,
                        'conversation_id' => $responseData['conversationId'] ?? null,
                        'price' => $responseData['price'] ?? 0,
                        'paid_price' => $responseData['paidPrice'] ?? 0,
                        'currency' => $responseData['currency'] ?? 'TRY',
                    ]
                );
            }

            $errorMessage = $responseData['errorMessage'] ?? 'Failed to create checkout form';

            return new PaymentResponse(
                success: false,
                message: $errorMessage,
                errorCode: $responseData['errorCode'] ?? 'CHECKOUT_ERROR',
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Iyzico checkout form error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new PaymentResponse(
                success: false,
                message: 'Checkout form creation failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Verify payment status with Iyzico
     */
    public function verify(array $data): PaymentResponse
    {
        try {
            $paymentId = $data['payment_id'] ?? null;
            $conversationId = $data['conversation_id'] ?? null;

            if (!$paymentId && !$conversationId) {
                return new PaymentResponse(
                    success: false,
                    message: 'Payment ID or Conversation ID is required'
                );
            }

            $verifyData = [
                'locale' => $data['locale'] ?? 'tr',
                'conversationId' => $conversationId ?? 'conv_' . uniqid(),
            ];

            if ($paymentId) {
                $verifyData['paymentId'] = $paymentId;
                $endpoint = '/payment/detail';
            } else {
                $verifyData['conversationId'] = $conversationId;
                $endpoint = '/payment/detail';
            }

            $response = Http::withHeaders([
                'Authorization' => $this->generateAuthorizationHeader(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . $endpoint, $verifyData);

            $responseData = $response->json();

            Log::info('Iyzico verification request', [
                'query' => $verifyData,
                'response' => $responseData
            ]);

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] === 'success') {
                $paymentData = $responseData;
                $status = $this->mapIyzicoStatus($paymentData['paymentStatus'] ?? null);

                return new PaymentResponse(
                    success: $status === 'completed',
                    transactionId: $paymentData['paymentId'] ?? null,
                    status: $status,
                    message: $paymentData['paymentStatus'] ?? 'Payment status retrieved',
                    data: [
                        'payment_id' => $paymentData['paymentId'] ?? null,
                        'price' => $paymentData['price'] ?? 0,
                        'paid_price' => $paymentData['paidPrice'] ?? 0,
                        'currency' => $paymentData['currency'] ?? null,
                        'installment' => $paymentData['installment'] ?? 1,
                        'payment_status' => $paymentData['paymentStatus'] ?? null,
                        'auth_code' => $paymentData['authCode'] ?? null,
                        'basket_id' => $paymentData['basketId'] ?? null,
                        'fraud_status' => $paymentData['fraudStatus'] ?? null,
                        'item_transactions' => $paymentData['itemTransactions'] ?? [],
                        'paid_with_saved_card' => $paymentData['paidWithSavedCard'] ?? false,
                        'card_user_key' => $paymentData['cardUserKey'] ?? null,
                        'card_token' => $paymentData['cardToken'] ?? null,
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: 'Unable to verify payment status',
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Iyzico verification error', [
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
     * Process refund through Iyzico
     */
    public function refund(array $data): PaymentResponse
    {
        try {
            $paymentId = $data['payment_id'] ?? null;
            $refundReason = $data['reason'] ?? 'Customer requested refund';
            $currency = $data['currency'] ?? 'TRY';

            if (!$paymentId) {
                return new PaymentResponse(
                    success: false,
                    message: 'Payment ID is required for refund'
                );
            }

            // Prepare refund data
            $refundData = [
                'locale' => $data['locale'] ?? 'tr',
                'conversationId' => $data['conversation_id'] ?? 'conv_' . uniqid(),
                'paymentTransactionId' => $paymentId,
                'price' => $data['amount'] ?? null, // For partial refund
                'currency' => $currency,
            ];

            $endpoint = isset($refundData['price']) ? '/payment/iyzipos/refund/changepaymentamount' : '/payment/iyzipos/refund';

            $response = Http::withHeaders([
                'Authorization' => $this->generateAuthorizationHeader(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . $endpoint, $refundData);

            $responseData = $response->json();

            Log::info('Iyzico refund request', [
                'request' => $refundData,
                'response' => $responseData
            ]);

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] === 'success') {
                $refundData = $responseData;

                return new PaymentResponse(
                    success: true,
                    transactionId: $refundData['paymentId'] ?? null,
                    message: 'Refund processed successfully',
                    data: [
                        'payment_id' => $refundData['paymentId'] ?? null,
                        'price' => $refundData['price'] ?? 0,
                        'currency' => $refundData['currency'] ?? 'TRY',
                        'refund_status' => 'processed',
                        'refund_transaction_id' => $refundData['refundTransactionId'] ?? null,
                    ]
                );
            }

            $errorMessage = $responseData['errorMessage'] ?? 'Refund failed';

            return new PaymentResponse(
                success: false,
                message: $errorMessage,
                errorCode: $responseData['errorCode'] ?? 'REFUND_ERROR',
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Iyzico refund error', [
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
     * Process webhook from Iyzico
     */
    public function processWebhook(array $data): PaymentResponse
    {
        try {
            Log::info('Iyzico webhook received', ['data' => $data]);

            // Validate webhook data structure
            if (!isset($data['iyziEventType']) || !isset($data['paymentId'])) {
                return new PaymentResponse(
                    success: false,
                    message: 'Invalid webhook data structure'
                );
            }

            // Verify webhook signature if provided
            if (isset($data['iyziEventType']) && isset($data['paymentConversationId'])) {
                // Additional verification logic can be added here
                // Iyzico sends webhooks with specific event types
                $eventType = $data['iyziEventType'];
                $paymentId = $data['paymentId'];

                // Map event type to payment status
                $status = $this->mapWebhookEventToStatus($eventType);

                return new PaymentResponse(
                    success: true,
                    transactionId: $paymentId,
                    status: $status,
                    message: 'Webhook processed successfully',
                    data: [
                        'webhook_type' => $eventType,
                        'payment_id' => $paymentId,
                        'conversation_id' => $data['paymentConversationId'] ?? null,
                        'basket_id' => $data['basketId'] ?? null,
                        'currency' => $data['currency'] ?? null,
                        'paid_price' => $data['paidPrice'] ?? 0,
                        'payment_status' => $status,
                        'fraud_status' => $data['fraudStatus'] ?? null,
                        'iyzi_event_type' => $eventType,
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: 'Unable to process webhook'
            );

        } catch (\Exception $e) {
            Log::error('Iyzico webhook processing error', [
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
     * Save card for recurring payments
     */
    public function saveCard(array $data): PaymentResponse
    {
        try {
            $cardData = [
                'locale' => $data['locale'] ?? 'tr',
                'conversationId' => $data['conversation_id'] ?? 'conv_' . uniqid(),
                'email' => $data['email'] ?? '',
                'cardUserKey' => $data['card_user_key'] ?? null,
                'card' => [
                    'cardHolderName' => $data['card']['holder_name'],
                    'cardNumber' => $data['card']['number'],
                    'expireMonth' => $data['card']['expire_month'],
                    'expireYear' => $data['card']['expire_year'],
                    'cvc' => $data['card']['cvc'],
                ]
            ];

            $response = Http::withHeaders([
                'Authorization' => $this->generateAuthorizationHeader(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/card/add', $cardData);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] === 'success') {
                return new PaymentResponse(
                    success: true,
                    message: 'Card saved successfully',
                    data: [
                        'card_user_key' => $responseData['cardUserKey'] ?? null,
                        'card_token' => $responseData['cardToken'] ?? null,
                        'card_association' => $responseData['cardAssociation'] ?? null,
                        'card_family' => $responseData['cardFamily'] ?? null,
                        'card_bank_name' => $responseData['cardBankName'] ?? null,
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: $responseData['errorMessage'] ?? 'Failed to save card'
            );

        } catch (\Exception $e) {
            return new PaymentResponse(
                success: false,
                message: 'Card saving failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Prepare payment data for Iyzico API
     */
    private function preparePaymentData(PaymentRequest $request): array
    {
        $paymentData = [
            'locale' => $request->metadata['locale'] ?? 'tr',
            'conversationId' => $request->orderId ?? 'conv_' . uniqid(),
            'price' => $request->amount,
            'paidPrice' => $request->amount,
            'currency' => $request->currency,
            'installment' => $request->metadata['installment'] ?? 1,
            'basketId' => 'B' . uniqid(),
            'paymentGroup' => $request->metadata['payment_group'] ?? 'PRODUCT',
        ];

        // Add buyer information
        if ($request->customer) {
            $customer = $request->customer;

            $paymentData['buyer'] = [
                'id' => $customer['id'] ?? 'BY' . uniqid(),
                'name' => $this->extractFirstName($customer['name'] ?? ''),
                'surname' => $this->extractLastName($customer['name'] ?? ''),
                'identityNumber' => $customer['identity_number'] ?? '10000000000',
                'email' => $customer['email'] ?? '',
                'gsmNumber' => $customer['phone'] ?? '',
                'registrationDate' => $customer['registration_date'] ?? '2021-01-01 00:00:00',
                'lastLoginDate' => $customer['last_login_date'] ?? '2021-01-01 00:00:00',
                'registrationAddress' => $customer['address'] ?? '',
                'city' => $customer['city'] ?? '',
                'country' => $customer['country'] ?? 'Turkey',
                'zipCode' => $customer['postal_code'] ?? '',
                'ip' => $customer['ip'] ?? request()->ip(),
            ];

            // Add shipping address (required for physical products)
            if (!empty($customer['address'])) {
                $paymentData['shippingAddress'] = [
                    'address' => $customer['address'],
                    'zipCode' => $customer['postal_code'] ?? '',
                    'contactName' => $customer['name'] ?? '',
                    'city' => $customer['city'] ?? '',
                    'country' => $customer['country'] ?? 'Turkey',
                ];

                // Use shipping address as billing address if billing not provided
                $paymentData['billingAddress'] = $paymentData['shippingAddress'];
            }
        }

        // Add card information for direct payment
        if (isset($request->metadata['card'])) {
            $paymentData['paymentCard'] = [
                'cardHolderName' => $request->metadata['card']['holder_name'],
                'cardNumber' => $request->metadata['card']['number'],
                'expireMonth' => $request->metadata['card']['expire_month'],
                'expireYear' => $request->metadata['card']['expire_year'],
                'cvc' => $request->metadata['card']['cvc'],
                'registerCard' => $request->metadata['card']['register_card'] ?? 0,
            ];
        }

        // Add basket items
        if (isset($request->metadata['basket_items'])) {
            $paymentData['basketItems'] = $request->metadata['basket_items'];
        } else {
            // Create default basket item
            $paymentData['basketItems'] = [
                [
                    'id' => 'ITEM' . uniqid(),
                    'name' => $request->description ?? 'Payment',
                    'category1' => 'Payment',
                    'category2' => 'Online Payment',
                    'itemType' => 'VIRTUAL',
                    'price' => $request->amount,
                ]
            ];
        }

        return $paymentData;
    }

    /**
     * Generate IYZWSv2 authorization header
     */
    private function generateAuthorizationHeader(): string
    {
        $randomString = Str::random(8);
        $timestamp = time();

        $signature = $randomString . ':' . $timestamp;
        $hash = hash_hmac('sha256', $signature, $this->secretKey, true);
        $hashBase64 = base64_encode($hash);

        return 'IYZWSv2 ' . $this->apiKey . ':' . $hashBase64;
    }

    /**
     * Map Iyzico status to standard status
     */
    private function mapIyzicoStatus(?string $iyzicoStatus): string
    {
        $statusMap = [
            'SUCCESS' => 'completed',
            'FAILURE' => 'failed',
            'INIT' => 'pending',
            'AUTH_PENDING' => 'pending',
            'CANCELLED' => 'cancelled',
            'REFUND_INITIATED' => 'refunded',
            'REFUND_SUCCESS' => 'refunded',
            'REFUND_FAILED' => 'failed',
        ];

        return $statusMap[$iyzicoStatus] ?? 'pending';
    }

    /**
     * Map webhook event type to payment status
     */
    private function mapWebhookEventToStatus(string $eventType): string
    {
        $eventMap = [
            'PAYMENT_SUCCESS' => 'completed',
            'PAYMENT_FAILURE' => 'failed',
            'PAYMENT_PENDING' => 'pending',
            'REFUND_SUCCESS' => 'refunded',
            'REFUND_FAILURE' => 'failed',
        ];

        return $eventMap[$eventType] ?? 'pending';
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
        // Iyzico expects amounts in float format
        return $amount;
    }

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods(): array
    {
        return [
            'card',           // Credit/Debit Cards
            'bank_transfer',  // Bank Transfer
            'wallet',         // Digital Wallets
            'installment',    // Installment Payments
            'bkm_express',    // BKM Express (Turkey)
            'garanti_pay',    // Garanti Pay
            'paycell',        // Paycell
        ];
    }

    /**
     * Get gateway configuration schema
     */
    public function getConfigSchema(): array
    {
        return [
            'api_key' => [
                'type' => 'string',
                'required' => true,
                'label' => 'API Key',
                'description' => 'Your Iyzico API Key'
            ],
            'secret_key' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Secret Key',
                'description' => 'Your Iyzico Secret Key'
            ],
            'test_mode' => [
                'type' => 'boolean',
                'default' => true,
                'label' => 'Test Mode',
                'description' => 'Enable sandbox mode for testing'
            ],
            'callback_url' => [
                'type' => 'url',
                'label' => 'Callback URL',
                'description' => 'URL for payment notifications'
            ],
            'webhook_url' => [
                'type' => 'url',
                'label' => 'Webhook URL',
                'description' => 'URL to receive webhook notifications'
            ],
        ];
    }
}