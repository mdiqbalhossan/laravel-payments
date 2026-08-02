<?php

namespace Mdiqbal\LaravelPayments\Gateways\Midtrans;

use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class MidtransGateway extends AbstractGateway
{
    /**
     * Gateway name
     */
    protected string $name = 'midtrans';

    /**
     * Get gateway name
     */
    public function gatewayName(): string
    {
        return $this->name;
    }

    /**
     * Supported currencies by Midtrans
     */
    protected array $supportedCurrencies = [
        'IDR', // Indonesian Rupiah
        'USD', // US Dollar
        'SGD', // Singapore Dollar
        'MYR', // Malaysian Ringgit
        'VND', // Vietnamese Dong
        'PHP', // Philippine Peso
        'THB', // Thai Baht
        'CNY', // Chinese Yuan
    ];

    /**
     * API configuration
     */
    private ?string $serverKey;
    private ?string $clientKey;
    private bool $testMode;
    private array $enabledPayments;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->serverKey = $this->config['server_key'] ?? null;
        $this->clientKey = $this->config['client_key'] ?? null;
        $this->testMode = $this->config['test_mode'] ?? true;
        $this->enabledPayments = $this->config['enabled_payments'] ?? [];
    }

    /**
     * Process payment through Midtrans
     */
    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            // Prepare Snap transaction
            $transactionData = $this->prepareSnapTransaction($request);

            // Create Snap transaction
            $snapResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
            ])->post($this->getSnapUrl(), $transactionData);

            $responseData = $snapResponse->json();

            Log::info('Midtrans Snap transaction request', [
                'request' => $transactionData,
                'response' => $responseData
            ]);

            if ($snapResponse->successful() && isset($responseData['token'])) {
                return new PaymentResponse(
                    success: true,
                    transactionId: $responseData['transaction_id'] ?? $responseData['order_id'] ?? null,
                    redirectUrl: $responseData['redirect_url'] ?? null,
                    message: 'Snap transaction created successfully',
                    data: [
                        'snap_token' => $responseData['token'],
                        'transaction_id' => $responseData['transaction_id'] ?? null,
                        'order_id' => $responseData['order_id'] ?? null,
                        'redirect_url' => $responseData['redirect_url'] ?? null,
                        'currency' => $request->currency,
                        'amount' => $request->amount,
                    ]
                );
            }

            // Handle error response
            $errorMessage = $responseData['error_messages'][0] ?? 'Failed to create Snap transaction';

            return new PaymentResponse(
                success: false,
                message: $errorMessage,
                errorCode: $responseData['status_code'] ?? 'SNAP_ERROR',
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Midtrans payment error', [
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
     * Verify payment status with Midtrans
     */
    public function verify(array $data): PaymentResponse
    {
        try {
            $orderId = $data['order_id'] ?? null;
            $transactionId = $data['transaction_id'] ?? null;

            if (!$orderId && !$transactionId) {
                return new PaymentResponse(
                    success: false,
                    message: 'Order ID or Transaction ID is required'
                );
            }

            // Get transaction status
            $endpoint = $transactionId ? "/{$transactionId}/status" : "/{$orderId}/status";
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
            ])->get($this->getApiUrl() . $endpoint);

            $responseData = $response->json();

            Log::info('Midtrans status check request', [
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'response' => $responseData
            ]);

            if ($response->successful() && isset($responseData['status_code'])) {
                $status = $this->mapMidtransStatus($responseData['transaction_status'] ?? '');
                $isSuccess = in_array($status, ['completed', 'success']);

                return new PaymentResponse(
                    success: $isSuccess,
                    transactionId: $responseData['transaction_id'] ?? null,
                    status: $status,
                    message: $responseData['status_message'] ?? 'Payment status retrieved',
                    data: [
                        'order_id' => $responseData['order_id'] ?? null,
                        'transaction_id' => $responseData['transaction_id'] ?? null,
                        'transaction_status' => $responseData['transaction_status'] ?? null,
                        'payment_type' => $responseData['payment_type'] ?? null,
                        'gross_amount' => $responseData['gross_amount'] ?? 0,
                        'currency' => $responseData['currency'] ?? null,
                        'fraud_status' => $responseData['fraud_status'] ?? null,
                        'signature_key' => $responseData['signature_key'] ?? null,
                        'approval_code' => $responseData['approval_code'] ?? null,
                        'bank' => $responseData['bank'] ?? null,
                        'eci' => $responseData['eci'] ?? null,
                        'masked_card' => $responseData['masked_card'] ?? null,
                        'card_type' => $responseData['card_type'] ?? null,
                        'settlement_time' => $responseData['settlement_time'] ?? null,
                        'channel_response_code' => $responseData['channel_response_code'] ?? null,
                        'channel_response_message' => $responseData['channel_response_message'] ?? null,
                        'payment_code' => $responseData['payment_code'] ?? null,
                        'pdf_url' => $responseData['pdf_url'] ?? null,
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: 'Unable to verify payment status',
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('Midtrans verification error', [
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
     * Process refund through Midtrans
     * Note: Implementation limitations - Midtrans refund API requires additional parameters
     * and may not support direct refund by transaction ID and amount alone.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        try {
            if (!$transactionId) {
                Log::error('Midtrans refund failed: Transaction ID is required');
                return false;
            }

            if ($amount <= 0) {
                Log::error('Midtrans refund failed: Refund amount must be greater than 0');
                return false;
            }

            // Prepare refund data
            $refundData = [
                'refund_key' => Str::random(32),
                'amount' => $amount,
                'reason' => 'Customer requested refund',
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
            ])->post($this->getApiUrl() . "/{$transactionId}/refund", $refundData);

            $responseData = $response->json();

            Log::info('Midtrans refund request', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'request' => $refundData,
                'response' => $responseData
            ]);

            // Return true if refund was successful
            if ($response->successful() && ($responseData['status_code'] ?? null) === 200) {
                Log::info('Midtrans refund processed successfully', [
                    'transaction_id' => $transactionId,
                    'amount' => $amount
                ]);
                return true;
            }

            // Log error and return false
            $errorMessage = $responseData['error_messages'][0] ?? 'Refund failed';
            Log::error('Midtrans refund failed', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $errorMessage,
                'response' => $responseData
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('Midtrans refund error', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Check if gateway supports refunds
     */
    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * Process webhook from Midtrans
     */
    public function processWebhook(array $data): PaymentResponse
    {
        try {
            Log::info('Midtrans webhook received', ['data' => $data]);

            // Validate webhook data structure
            if (!isset($data['order_id']) || !isset($data['transaction_status'])) {
                return new PaymentResponse(
                    success: false,
                    message: 'Invalid webhook data structure'
                );
            }

            // Verify signature key if provided
            if (isset($data['signature_key'])) {
                $isValidSignature = $this->verifyWebhookSignature($data);

                if (!$isValidSignature) {
                    return new PaymentResponse(
                        success: false,
                        message: 'Invalid webhook signature'
                    );
                }
            }

            // Extract payment information
            $status = $this->mapMidtransStatus($data['transaction_status']);

            return new PaymentResponse(
                success: true,
                transactionId: $data['transaction_id'] ?? null,
                status: $status,
                message: 'Webhook processed successfully',
                data: [
                    'webhook_type' => 'payment_status',
                    'order_id' => $data['order_id'] ?? null,
                    'transaction_id' => $data['transaction_id'] ?? null,
                    'transaction_status' => $data['transaction_status'] ?? null,
                    'payment_type' => $data['payment_type'] ?? null,
                    'gross_amount' => $data['gross_amount'] ?? 0,
                    'currency' => $data['currency'] ?? null,
                    'fraud_status' => $data['fraud_status'] ?? null,
                    'approval_code' => $data['approval_code'] ?? null,
                    'bank' => $data['bank'] ?? null,
                    'masked_card' => $data['masked_card'] ?? null,
                    'card_type' => $data['card_type'] ?? null,
                    'settlement_time' => $data['settlement_time'] ?? null,
                    'status_message' => $data['status_message'] ?? null,
                    'signature_key' => $data['signature_key'] ?? null,
                ]
            );

        } catch (\Exception $e) {
            Log::error('Midtrans webhook processing error', [
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
     * Create payment link for Midtrans
     */
    public function createPaymentLink(array $data): PaymentResponse
    {
        try {
            // Prepare payment link data
            $linkData = [
                'payment_link' => [
                    'transaction_details' => [
                        'order_id' => $data['order_id'] ?? 'LINK-' . uniqid(),
                        'gross_amount' => $data['amount'],
                    ],
                    'usage' => 'Payment Link',
                    'customer_details' => [
                        'first_name' => $this->extractFirstName($data['customer_name'] ?? ''),
                        'last_name' => $this->extractLastName($data['customer_name'] ?? ''),
                        'email' => $data['customer_email'] ?? '',
                        'phone' => $data['customer_phone'] ?? '',
                    ],
                    'expiry' => [
                        'duration' => $data['expiry_duration'] ?? 24,
                        'unit' => 'hours',
                    ],
                ]
            ];

            if (isset($data['currency'])) {
                $linkData['payment_link']['transaction_details']['currency'] = $data['currency'];
            }

            if (isset($data['description'])) {
                $linkData['payment_link']['transaction_details']['description'] = $data['description'];
            }

            if (isset($data['customer_address'])) {
                $linkData['payment_link']['customer_details']['billing_address'] = $data['customer_address'];
            }

            if (isset($data['shipping_address'])) {
                $linkData['payment_link']['customer_details']['shipping_address'] = $data['shipping_address'];
            }

            // Add item details if provided
            if (isset($data['items'])) {
                $linkData['payment_link']['item_details'] = $data['items'];
            }

            // Create payment link
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
            ])->post($this->getApiUrl() . '/payment-links', $linkData);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['payment_url'])) {
                return new PaymentResponse(
                    success: true,
                    transactionId: $responseData['order_id'] ?? null,
                    redirectUrl: $responseData['payment_url'] ?? null,
                    message: 'Payment link created successfully',
                    data: [
                        'payment_url' => $responseData['payment_url'] ?? null,
                        'order_id' => $responseData['order_id'] ?? null,
                        'expiry_time' => $responseData['expiry_time'] ?? null,
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: $responseData['error_messages'][0] ?? 'Failed to create payment link'
            );

        } catch (\Exception $e) {
            Log::error('Midtrans payment link creation error', [
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
     * Prepare Snap transaction data
     */
    private function prepareSnapTransaction(PaymentRequest $request): array
    {
        $transactionData = [
            'transaction_details' => [
                'order_id' => $request->orderId ?? 'ORDER-' . uniqid(),
                'gross_amount' => $request->amount,
            ],
            'enabled_payments' => $this->enabledPayments ?: $this->getDefaultEnabledPayments(),
        ];

        // Add currency if specified
        if ($request->currency && $request->currency !== 'IDR') {
            $transactionData['transaction_details']['currency'] = $request->currency;
        }

        // Add customer details
        if ($request->customer) {
            $customer = $request->customer;
            $transactionData['customer_details'] = [
                'first_name' => $this->extractFirstName($customer['name'] ?? ''),
                'last_name' => $this->extractLastName($customer['name'] ?? ''),
                'email' => $customer['email'] ?? '',
                'phone' => $customer['phone'] ?? '',
            ];

            // Add billing address if provided
            if (!empty($customer['address'])) {
                $transactionData['customer_details']['billing_address'] = [
                    'address' => $customer['address'],
                    'city' => $customer['city'] ?? '',
                    'postal_code' => $customer['postal_code'] ?? '',
                    'country_code' => $customer['country'] ?? 'IDN',
                ];
            }
        }

        // Add item details
        if (isset($request->metadata['items'])) {
            $transactionData['item_details'] = $request->metadata['items'];
        } else {
            // Create default item
            $transactionData['item_details'] = [
                [
                    'id' => 'ITEM-' . uniqid(),
                    'price' => $request->amount,
                    'quantity' => 1,
                    'name' => $request->description ?? 'Payment',
                ]
            ];
        }

        // Add credit card options if specified
        if (isset($request->metadata['credit_card'])) {
            $transactionData['credit_card'] = $request->metadata['credit_card'];
        }

        // Add expiry time if specified
        if (isset($request->metadata['expiry'])) {
            $transactionData['expiry'] = $request->metadata['expiry'];
        }

        // Add callbacks
        if ($request->returnUrl) {
            $transactionData['callbacks'] = [
                'finish' => $request->returnUrl,
                'error' => $request->returnUrl,
                'pending' => $request->returnUrl,
            ];
        }

        return $transactionData;
    }

    /**
     * Get default enabled payments
     */
    private function getDefaultEnabledPayments(): array
    {
        return [
            'credit_card',
            'gopay',
            'shopeepay',
            'bank_transfer',
            'echannel',
            'qris',
            'cstore',
            'bca_klikpay',
            'bca_klikbca',
            'bri_epay',
            'cimb_clicks',
            'danamon_online',
            'akulaku',
            'indomaret',
            'alfamart',
        ];
    }

    /**
     * Verify webhook signature
     */
    private function verifyWebhookSignature(array $data): bool
    {
        if (!isset($data['order_id'], $data['status_code'], $data['gross_amount'],
                   $data['signature_key'])) {
            return false;
        }

        $input = $data['order_id'] . $data['status_code'] . $data['gross_amount'] . $this->serverKey;
        $signature = hash('sha512', $input);

        return hash_equals($signature, $data['signature_key']);
    }

    /**
     * Map Midtrans status to standard status
     */
    private function mapMidtransStatus(?string $midtransStatus): string
    {
        $statusMap = [
            'capture' => 'completed',
            'settlement' => 'completed',
            'pending' => 'pending',
            'deny' => 'failed',
            'cancel' => 'cancelled',
            'expire' => 'expired',
            'refund' => 'refunded',
            'partial_refund' => 'partially_refunded',
            'challenge' => 'pending',
            'authorize' => 'authorized',
        ];

        return $statusMap[$midtransStatus] ?? 'unknown';
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
     * Get Snap API URL
     */
    private function getSnapUrl(): string
    {
        return $this->testMode
            ? 'https://app.sandbox.midtrans.com/snap/v1/transactions'
            : 'https://app.midtrans.com/snap/v1/transactions';
    }

    /**
     * Get Core API URL
     */
    private function getApiUrl(): string
    {
        return $this->testMode
            ? 'https://api.sandbox.midtrans.com/v2'
            : 'https://api.midtrans.com/v2';
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
        // Midtrans expects amounts in integer format (no decimal points)
        return (int) round($amount);
    }

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods(): array
    {
        return [
            'credit_card',      // Credit/Debit Cards
            'gopay',           // GoPay
            'shopeepay',       // ShopeePay
            'bank_transfer',   // Bank Transfer
            'echannel',        // Mandiri Virtual Account
            'qris',            // QRIS
            'cstore',          // Convenience Store (Indomaret, Alfamart)
            'bca_klikpay',     // BCA KlikPay
            'bca_klikbca',     // BCA KlikBCA
            'bri_epay',        // BRI e-Pay
            'cimb_clicks',     // CIMB Clicks
            'danamon_online',  // Danamon Online
            'akulaku',         // Akulaku
            'indomaret',       // Indomaret
            'alfamart',        // Alfamart
        ];
    }

    /**
     * Get gateway configuration schema
     */
    public function getConfigSchema(): array
    {
        return [
            'server_key' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Server Key',
                'description' => 'Your Midtrans Server Key'
            ],
            'client_key' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Client Key',
                'description' => 'Your Midtrans Client Key'
            ],
            'test_mode' => [
                'type' => 'boolean',
                'default' => true,
                'label' => 'Test Mode',
                'description' => 'Enable sandbox mode for testing'
            ],
            'enabled_payments' => [
                'type' => 'array',
                'label' => 'Enabled Payment Methods',
                'description' => 'List of enabled payment methods (leave empty for all)',
            ],
            'webhook_url' => [
                'type' => 'url',
                'label' => 'Webhook URL',
                'description' => 'URL to receive payment notifications'
            ],
        ];
    }
}