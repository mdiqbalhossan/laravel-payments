<?php

namespace Mdiqbal\LaravelPayments\Gateways\Myfatoorah;

use Mdiqbal\LaravelPayments\DTOs\PaymentRequest;
use Mdiqbal\LaravelPayments\DTOs\PaymentResponse;
use Mdiqbal\LaravelPayments\Gateways\AbstractGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MyfatoorahGateway extends AbstractGateway
{
    /**
     * Gateway name
     */
    protected string $name = 'myfatoorah';

    /**
     * Supported currencies by MyFatoorah
     */
    protected array $supportedCurrencies = [
        'SAR', // Saudi Riyal
        'KWD', // Kuwaiti Dinar
        'BHD', // Bahraini Dinar
        'AED', // UAE Dirham
        'QAR', // Qatari Riyal
        'OMR', // Omani Rial
        'JOD', // Jordanian Dinar
        'EGP', // Egyptian Pound
        'USD', // US Dollar
        'EUR', // Euro
    ];

    /**
     * API configuration
     */
    private string $apiKey;
    private string $baseUrl;
    private bool $testMode;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->apiKey = $this->config->get('api_key');
        $this->testMode = $this->config->get('test_mode', true);

        $this->baseUrl = $this->testMode
            ? 'https://apitest.myfatoorah.com'
            : 'https://api.myfatoorah.com';
    }

    /**
     * Process payment through MyFatoorah
     */
    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            // Prepare invoice data
            $invoiceData = $this->prepareInvoiceData($request);

            // Create invoice
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/v2/SendPayment', $invoiceData);

            $responseData = $response->json();

            Log::info('MyFatoorah payment request', [
                'request' => $invoiceData,
                'response' => $responseData
            ]);

            if ($response->successful() && isset($responseData['Data']['InvoiceURL'])) {
                $invoiceData = $responseData['Data'];

                return new PaymentResponse(
                    success: true,
                    transactionId: $invoiceData['InvoiceId'] ?? null,
                    redirectUrl: $invoiceData['InvoiceURL'] ?? null,
                    message: 'Invoice created successfully',
                    data: [
                        'invoice_id' => $invoiceData['InvoiceId'] ?? null,
                        'invoice_url' => $invoiceData['InvoiceURL'] ?? null,
                        'customer_reference' => $invoiceData['CustomerReference'] ?? null,
                        'invoice_value' => $invoiceData['InvoiceValue'] ?? 0,
                        'currency' => $invoiceData['InvoiceCurrency'] ?? $request->currency,
                        'expiry_date' => $invoiceData['ExpiryDate'] ?? null,
                        'expiry_time' => $invoiceData['ExpiryTime'] ?? null,
                        'customer_email' => $invoiceData['CustomerEmail'] ?? null,
                        'customer_mobile' => $invoiceData['CustomerMobile'] ?? null,
                    ]
                );
            }

            // Handle error response
            $errorMessage = $responseData['ErrorMessage'] ?? 'Failed to create payment invoice';
            $errorCode = $responseData['ErrorCode'] ?? 'INVOICE_ERROR';

            return new PaymentResponse(
                success: false,
                message: $errorMessage,
                errorCode: $errorCode,
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('MyFatoorah payment error', [
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
     * Verify payment status with MyFatoorah
     */
    public function verify(array $data): PaymentResponse
    {
        try {
            $invoiceId = $data['invoice_id'] ?? null;
            $paymentId = $data['payment_id'] ?? null;

            if (!$invoiceId && !$paymentId) {
                return new PaymentResponse(
                    success: false,
                    message: 'Invoice ID or Payment ID is required'
                );
            }

            // Check payment status
            $endpoint = $paymentId ? "/v2/GetPaymentStatus?paymentId={$paymentId}" : "/v2/GetInvoiceStatus?InvoiceId={$invoiceId}";
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . $endpoint);

            $responseData = $response->json();

            Log::info('MyFatoorah verification request', [
                'invoice_id' => $invoiceId,
                'payment_id' => $paymentId,
                'response' => $responseData
            ]);

            if ($response->successful() && isset($responseData['Data'])) {
                $paymentData = $responseData['Data'];
                $status = $this->mapMyFatoorahStatus($paymentData['InvoiceStatus'] ?? '');
                $isSuccess = in_array($status, ['completed', 'success']);

                return new PaymentResponse(
                    success: $isSuccess,
                    transactionId: $paymentData['InvoiceId'] ?? null,
                    status: $status,
                    message: $paymentData['InvoiceStatus'] ?? 'Payment status retrieved',
                    data: [
                        'invoice_id' => $paymentData['InvoiceId'] ?? null,
                        'payment_id' => $paymentData['PaymentId'] ?? null,
                        'invoice_status' => $paymentData['InvoiceStatus'] ?? null,
                        'invoice_value' => $paymentData['InvoiceValue'] ?? 0,
                        'paid_currency' => $paymentData['PaidCurrency'] ?? null,
                        'paid_amount' => $paymentData['PaidAmount'] ?? 0,
                        'invoice_display_value' => $paymentData['InvoiceDisplayValue'] ?? null,
                        'transaction_date' => $paymentData['TransactionDate'] ?? null,
                        'transaction_time' => $paymentData['TransactionTime'] ?? null,
                        'payment_method' => $paymentData['PaymentMethod'] ?? null,
                        'customer_reference' => $paymentData['CustomerReference'] ?? null,
                        'customer_email' => $paymentData['CustomerEmail'] ?? null,
                        'customer_mobile' => $paymentData['CustomerMobile'] ?? null,
                        'customer_name' => $paymentData['CustomerName'] ?? null,
                        'authorization_id' => $paymentData['AuthorizationId'] ?? null,
                        'order_id' => $paymentData['OrderId'] ?? null,
                        'tracking_id' => $paymentData['TrackingId'] ?? null,
                        'error' => $paymentData['Error'] ?? null,
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: 'Unable to verify payment status',
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('MyFatoorah verification error', [
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
     * Process refund through MyFatoorah
     */
    public function refund(array $data): PaymentResponse
    {
        try {
            $paymentId = $data['payment_id'] ?? null;
            $refundAmount = $data['amount'] ?? null;
            $refundReason = $data['reason'] ?? 'Customer requested refund';
            $refundChargeOnCustomer = $data['charge_on_customer'] ?? false;

            if (!$paymentId || !$refundAmount) {
                return new PaymentResponse(
                    success: false,
                    message: 'Payment ID and refund amount are required'
                );
            }

            // Prepare refund data
            $refundData = [
                'PaymentId' => $paymentId,
                'RefundChargeOnCustomer' => $refundChargeOnCustomer ? 'true' : 'false',
                'ServiceChargeOnCustomer' => 0,
                'Amount' => number_format($refundAmount, 2, '.', ''),
                'Note' => $refundReason,
                'RefundReason' => $refundReason,
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/v2/MakeRefund', $refundData);

            $responseData = $response->json();

            Log::info('MyFatoorah refund request', [
                'request' => $refundData,
                'response' => $responseData
            ]);

            if ($response->successful() && isset($responseData['Data']['RefundId'])) {
                $refundData = $responseData['Data'];

                return new PaymentResponse(
                    success: true,
                    transactionId: $refundData['RefundId'] ?? null,
                    message: 'Refund processed successfully',
                    data: [
                        'refund_id' => $refundData['RefundId'] ?? null,
                        'refund_status' => 'processed',
                        'refund_amount' => $refundData['Amount'] ?? $refundAmount,
                        'refund_date' => $refundData['RefundDate'] ?? null,
                        'payment_id' => $refundData['PaymentId'] ?? $paymentId,
                    ]
                );
            }

            $errorMessage = $responseData['ErrorMessage'] ?? 'Refund failed';

            return new PaymentResponse(
                success: false,
                message: $errorMessage,
                errorCode: $responseData['ErrorCode'] ?? 'REFUND_ERROR',
                data: $responseData
            );

        } catch (\Exception $e) {
            Log::error('MyFatoorah refund error', [
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
     * Process webhook from MyFatoorah
     */
    public function processWebhook(array $data): PaymentResponse
    {
        try {
            Log::info('MyFatoorah webhook received', ['data' => $data]);

            // Validate webhook data structure
            if (!isset($data['EventType']) || !isset($data['Data'])) {
                return new PaymentResponse(
                    success: false,
                    message: 'Invalid webhook data structure'
                );
            }

            // Extract payment information
            $paymentData = $data['Data'];
            $eventType = $data['EventType'];
            $status = $this->mapMyFatoorahStatus($paymentData['InvoiceStatus'] ?? '');

            return new PaymentResponse(
                success: true,
                transactionId: $paymentData['InvoiceId'] ?? null,
                status: $status,
                message: 'Webhook processed successfully',
                data: [
                    'webhook_type' => $eventType,
                    'event_id' => $data['EventId'] ?? null,
                    'invoice_id' => $paymentData['InvoiceId'] ?? null,
                    'payment_id' => $paymentData['PaymentId'] ?? null,
                    'invoice_status' => $paymentData['InvoiceStatus'] ?? null,
                    'invoice_value' => $paymentData['InvoiceValue'] ?? 0,
                    'paid_amount' => $paymentData['PaidAmount'] ?? 0,
                    'payment_method' => $paymentData['PaymentMethod'] ?? null,
                    'customer_reference' => $paymentData['CustomerReference'] ?? null,
                    'customer_email' => $paymentData['CustomerEmail'] ?? null,
                    'customer_mobile' => $paymentData['CustomerMobile'] ?? null,
                    'customer_name' => $paymentData['CustomerName'] ?? null,
                    'transaction_date' => $paymentData['TransactionDate'] ?? null,
                    'transaction_time' => $paymentData['TransactionTime'] ?? null,
                ]
            );

        } catch (\Exception $e) {
            Log::error('MyFatoorah webhook processing error', [
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
     * Create payment link for MyFatoorah
     */
    public function createPaymentLink(array $data): PaymentResponse
    {
        try {
            // Prepare invoice data for payment link
            $linkData = [
                'CustomerName' => $data['customer_name'] ?? '',
                'NotificationOption' => $data['notification_option'] ?? 'Link', // Link, SMS, Email, All
                'CustomerEmail' => $data['customer_email'] ?? '',
                'CustomerMobile' => $data['customer_phone'] ?? '',
                'CustomerReference' => $data['customer_reference'] ?? uniqid(),
                'InvoiceValue' => $data['amount'],
                'DisplayCurrencyIso' => $data['currency'] ?? 'SAR',
                'InvoiceItems' => $data['items'] ?? [[
                    'ItemName' => $data['description'] ?? 'Payment via MyFatoorah',
                    'Quantity' => 1,
                    'UnitPrice' => $data['amount']
                ]],
                'CallBackUrl' => $data['callback_url'] ?? $this->config->get('webhook_url'),
                'ErrorUrl' => $data['error_url'] ?? $this->config->get('error_url'),
                'Language' => $data['language'] ?? 'en', // en or ar
                'ExpiryTime' => $data['expiry_time'] ?? 1440, // 24 hours in minutes
            ];

            // Create invoice
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/v2/SendPayment', $linkData);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['Data']['InvoiceURL'])) {
                $linkData = $responseData['Data'];

                return new PaymentResponse(
                    success: true,
                    transactionId: $linkData['InvoiceId'] ?? null,
                    redirectUrl: $linkData['InvoiceURL'] ?? null,
                    message: 'Payment link created successfully',
                    data: [
                        'invoice_id' => $linkData['InvoiceId'] ?? null,
                        'invoice_url' => $linkData['InvoiceURL'] ?? null,
                        'customer_reference' => $linkData['CustomerReference'] ?? null,
                        'expiry_date' => $linkData['ExpiryDate'] ?? null,
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: $responseData['ErrorMessage'] ?? 'Failed to create payment link'
            );

        } catch (\Exception $e) {
            Log::error('MyFatoorah payment link creation error', [
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
     * Initiate direct payment
     */
    public function initiatePayment(array $data): PaymentResponse
    {
        try {
            $paymentData = [
                'PaymentMethodId' => $data['payment_method_id'],
                'PaymentType' => $data['payment_type'],
                'Value' => $data['amount'],
                'CustomerName' => $data['customer_name'] ?? '',
                'NotificationOption' => 'ALL',
                'CustomerEmail' => $data['customer_email'] ?? '',
                'CustomerMobile' => $data['customer_phone'] ?? '',
                'CustomerReference' => $data['customer_reference'] ?? uniqid(),
                'DisplayCurrencyIso' => $data['currency'] ?? 'SAR',
                'CallBackUrl' => $data['callback_url'] ?? $this->config->get('webhook_url'),
                'ErrorUrl' => $data['error_url'] ?? $this->config->get('error_url'),
                'Language' => $data['language'] ?? 'en',
                'UserDefinedField' => $data['user_defined_field'] ?? '',
                'DirectPaymentURL' => $data['redirect_url'] ?? null,
            ];

            // Add card details if provided
            if (isset($data['card_details'])) {
                $paymentData['CardToken'] = $data['card_details']['token'];
                $paymentData['SaveToken'] = $data['card_details']['save_token'] ?? false;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/v2/DirectPayment', $paymentData);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['Data']['PaymentURL'])) {
                $paymentData = $responseData['Data'];

                return new PaymentResponse(
                    success: true,
                    transactionId: $paymentData['InvoiceId'] ?? null,
                    redirectUrl: $paymentData['PaymentURL'] ?? null,
                    message: 'Direct payment initiated successfully',
                    data: [
                        'invoice_id' => $paymentData['InvoiceId'] ?? null,
                        'payment_url' => $paymentData['PaymentURL'] ?? null,
                        'payment_id' => $paymentData['PaymentId'] ?? null,
                        'reference_id' => $paymentData['ReferenceId'] ?? null,
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: $responseData['ErrorMessage'] ?? 'Failed to initiate direct payment'
            );

        } catch (\Exception $e) {
            Log::error('MyFatoorah direct payment initiation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new PaymentResponse(
                success: false,
                message: 'Failed to initiate direct payment: ' . $e->getMessage()
            );
        }
    }

    /**
     * Prepare invoice data for MyFatoorah API
     */
    private function prepareInvoiceData(PaymentRequest $request): array
    {
        $invoiceData = [
            'CustomerName' => $request->customer['name'] ?? '',
            'NotificationOption' => 'Link',
            'CustomerEmail' => $request->customer['email'] ?? '',
            'CustomerMobile' => $request->customer['phone'] ?? '',
            'CustomerReference' => $request->orderId ?? 'INV-' . uniqid(),
            'InvoiceValue' => $request->amount,
            'DisplayCurrencyIso' => $request->currency,
            'CallBackUrl' => $request->notifyUrl ?? $this->config->get('webhook_url'),
            'ErrorUrl' => $request->cancelUrl ?? $this->config->get('error_url'),
            'Language' => $request->metadata['language'] ?? 'en', // en or ar
            'ExpiryTime' => $request->metadata['expiry_time'] ?? 1440, // 24 hours
        ];

        // Add order ID if provided
        if ($request->orderId) {
            $invoiceData['OrderId'] = $request->orderId;
        }

        // Add user defined field if provided
        if (isset($request->metadata['user_defined_field'])) {
            $invoiceData['UserDefinedField'] = $request->metadata['user_defined_field'];
        }

        // Add invoice items if provided
        if (isset($request->metadata['items'])) {
            $invoiceData['InvoiceItems'] = $request->metadata['items'];
        } else {
            // Create default item
            $invoiceData['InvoiceItems'] = [
                [
                    'ItemName' => $request->description ?? 'Payment via MyFatoorah',
                    'Quantity' => 1,
                    'UnitPrice' => $request->amount
                ]
            ];
        }

        // Add payment method if specified
        if (isset($request->metadata['payment_method_id'])) {
            $invoiceData['PaymentMethodId'] = $request->metadata['payment_method_id'];
        }

        // Add card token if provided
        if (isset($request->metadata['card_token'])) {
            $invoiceData['CardToken'] = $request->metadata['card_token'];
        }

        return $invoiceData;
    }

    /**
     * Map MyFatoorah status to standard status
     */
    private function mapMyFatoorahStatus(?string $myfatoorahStatus): string
    {
        $statusMap = [
            'Paid' => 'completed',
            'Pending' => 'pending',
            'Failed' => 'failed',
            'Expired' => 'expired',
            'Refunded' => 'refunded',
            'PartiallyRefunded' => 'partially_refunded',
            'Cancelled' => 'cancelled',
            'Voided' => 'voided',
            'New' => 'pending',
            'Draft' => 'draft',
        ];

        return $statusMap[$myfatoorahStatus] ?? 'unknown';
    }

    /**
     * Get payment methods
     */
    public function getPaymentMethods(): PaymentResponse
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . '/v2/InitiatePayment');

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['Data']['PaymentMethods'])) {
                $paymentMethods = $responseData['Data']['PaymentMethods'];

                return new PaymentResponse(
                    success: true,
                    message: 'Payment methods retrieved successfully',
                    data: [
                        'payment_methods' => $paymentMethods
                    ]
                );
            }

            return new PaymentResponse(
                success: false,
                message: $responseData['ErrorMessage'] ?? 'Failed to retrieve payment methods'
            );

        } catch (\Exception $e) {
            return new PaymentResponse(
                success: false,
                message: 'Failed to retrieve payment methods: ' . $e->getMessage()
            );
        }
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
        // MyFatoorah expects amounts in decimal format (e.g., 100.00)
        return $amount;
    }

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods(): array
    {
        return [
            'credit_card',     // Credit/Debit Cards
            'knet',            // KNET (Kuwait)
            'knetcredit',       // KNET Credit
            'cc',              // Credit Card (UAE)
            'sadad',           // SADAD (Saudi Arabia)
            'mada',            // MADA (Saudi Arabia)
            'visa',            // Visa (UAE)
            'mastercard',      // Mastercard (UAE)
            'amex',            // American Express
            'unionpay',        // UnionPay
            'benefit',         // Benefit (Bahrain)
            'naps',            // NAPS (Oman)
            'nbk',             // NBK (Kuwait)
            'knettoken',       // KNET Token
            'qpayid',          // QPay ID (Qatar)
            'qpay',            // QPay (Qatar)
            'qcard',           // QCard (Qatar)
            'omannet',         // OmanNet (Oman)
            'omannetcc',       // OmanNet CC (Oman)
            'stcpay',          // STC Pay (Saudi Arabia)
            'valu',            // ValU (UAE)
            'trustpay',        // Trust Pay (UAE)
            'applepay',        // Apple Pay
            'googlepay',       // Google Pay
            'wallet',          // General wallet
            'bank_transfer',   // Bank transfer
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
                'description' => 'Your MyFatoorah API Key'
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
            'error_url' => [
                'type' => 'url',
                'label' => 'Error URL',
                'description' => 'URL to redirect on payment error'
            ],
            'success_url' => [
                'type' => 'url',
                'label' => 'Success URL',
                'description' => 'URL to redirect after successful payment'
            ],
        ];
    }
}