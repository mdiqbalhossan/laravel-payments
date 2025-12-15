<?php

namespace Mdiqbal\LaravelPayments\Gateways\EasyPaisa;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface;
use Mdiqbal\LaravelPayments\DTOs\PaymentRequest;
use Mdiqbal\LaravelPayments\DTOs\PaymentResponse;
use Mdiqbal\LaravelPayments\Gateways\AbstractGateway;

class EasyPaisaGateway extends AbstractGateway implements PaymentGatewayInterface
{
    /**
     * Gateway name
     */
    protected string $name = 'easypaisa';

    /**
     * EasyPaisa API base URL
     */
    private string $baseUrl;

    /**
     * Merchant credentials
     */
    private string $merchantId;
    private string $merchantPassword;
    private string $storeId;
    private string $accountNumber;

    /**
     * Test mode flag
     */
    private bool $testMode;

    /**
     * Create a new gateway instance.
     */
    public function __construct(array $config = [])
    {
        $this->testMode = $config['test_mode'] ?? config('payments.gateways.easypaisa.test_mode', true);

        if ($this->testMode) {
            $this->baseUrl = 'https://sandbox.easypaisa.com.pk/epg';
        } else {
            $this->baseUrl = 'https://api.easypaisa.com.pk/epg';
        }

        $this->merchantId = $config['merchant_id'] ?? config('payments.gateways.easypaisa.merchant_id');
        $this->merchantPassword = $config['merchant_password'] ?? config('payments.gateways.easypaisa.merchant_password');
        $this->storeId = $config['store_id'] ?? config('payments.gateways.easypaisa.store_id');
        $this->accountNumber = $config['account_number'] ?? config('payments.gateways.easypaisa.account_number');

        parent::__construct($config);
    }

    /**
     * {@inheritdoc}
     */
    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            $this->validateCredentials();
            $this->validateRequest($request);

            $paymentType = $request->metadata['payment_type'] ?? 'mobile_account';

            switch ($paymentType) {
                case 'otc':
                    return $this->createOTCPayment($request);
                case 'bank_account':
                    return $this->createBankAccountPayment($request);
                default:
                    return $this->createMobileAccountPayment($request);
            }

        } catch (\Exception $e) {
            $this->logError('Payment initiation failed', [
                'error' => $e->getMessage(),
                'order_id' => $request->orderId ?? null,
            ]);

            return new PaymentResponse(
                success: false,
                message: $e->getMessage(),
                code: 500
            );
        }
    }

    /**
     * Create Mobile Account Payment
     */
    private function createMobileAccountPayment(PaymentRequest $request): PaymentResponse
    {
        $token = $this->generateToken();
        $transactionId = $request->orderId ?? 'TXN_' . time() . '_' . Str::random(6);

        $requestData = [
            'merchantId' => $this->merchantId,
            'storeId' => $this->storeId,
            'transactionId' => $transactionId,
            'transactionAmount' => number_format($request->amount, 2, '.', ''),
            'transactionType' => 'MA',
            'mobileAccountNumber' => $request->metadata['mobile_account'] ?? '',
            'emailAddress' => $request->customer['email'] ?? '',
            'msisdn' => $request->customer['phone'] ?? '',
            'token' => $token,
            'callbackUrl' => $request->notifyUrl ?? route('payment.easypaisa.callback'),
            'orderRefNum' => $request->orderId ?? '',
            'paymentDesc' => $request->description ?? 'Payment for Order #' . $request->orderId,
            'currencyCode' => $request->currency ?? 'PKR',
        ];

        $response = Http::asForm()
            ->timeout(60)
            ->post($this->baseUrl . '/mobile-account', $requestData);

        $responseData = $response->json();

        $this->logInfo('Mobile account payment initiated', [
            'request' => $requestData,
            'response' => $responseData,
            'status_code' => $response->status(),
        ]);

        if ($response->successful() && isset($responseData['pp_ResponseCode']) && $responseData['pp_ResponseCode'] === '000') {
            return new PaymentResponse(
                success: true,
                transaction_id: $responseData['pp_TxnRefNo'] ?? $transactionId,
                redirect_url: $responseData['pp_Links'] ?? null,
                message: 'Payment initiated successfully',
                data: $responseData
            );
        }

        return new PaymentResponse(
            success: false,
            message: $responseData['pp_ResponseMessage'] ?? 'Payment failed',
            code: $responseData['pp_ResponseCode'] ?? $response->status(),
            data: $responseData
        );
    }

    /**
     * Create OTC (Over The Counter) Payment
     */
    private function createOTCPayment(PaymentRequest $request): PaymentResponse
    {
        $token = $this->generateToken();
        $transactionId = $request->orderId ?? 'OTC_' . time() . '_' . Str::random(6);

        $requestData = [
            'merchantId' => $this->merchantId,
            'storeId' => $this->storeId,
            'transactionId' => $transactionId,
            'transactionAmount' => number_format($request->amount, 2, '.', ''),
            'transactionType' => 'OTC',
            'emailAddress' => $request->customer['email'] ?? '',
            'msisdn' => $request->customer['phone'] ?? '',
            'token' => $token,
            'callbackUrl' => $request->notifyUrl ?? route('payment.easypaisa.callback'),
            'orderRefNum' => $request->orderId ?? '',
            'paymentDesc' => $request->description ?? 'Payment for Order #' . $request->orderId,
            'currencyCode' => $request->currency ?? 'PKR',
            'expiryDate' => $request->metadata['expiry_date'] ?? date('Ymd', strtotime('+3 days')),
        ];

        $response = Http::asForm()
            ->timeout(60)
            ->post($this->baseUrl . '/otc', $requestData);

        $responseData = $response->json();

        $this->logInfo('OTC payment initiated', [
            'request' => $requestData,
            'response' => $responseData,
            'status_code' => $response->status(),
        ]);

        if ($response->successful() && isset($responseData['pp_ResponseCode']) && $responseData['pp_ResponseCode'] === '000') {
            return new PaymentResponse(
                success: true,
                transaction_id: $responseData['pp_TxnRefNo'] ?? $transactionId,
                redirect_url: null,
                message: 'OTC payment created successfully',
                data: array_merge($responseData, [
                    'payment_code' => $responseData['pp_PaymentId'] ?? null,
                    'expiry_date' => $responseData['pp_ExpiryDate'] ?? null,
                ])
            );
        }

        return new PaymentResponse(
            success: false,
            message: $responseData['pp_ResponseMessage'] ?? 'OTC payment failed',
            code: $responseData['pp_ResponseCode'] ?? $response->status(),
            data: $responseData
        );
    }

    /**
     * Create Bank Account Payment
     */
    private function createBankAccountPayment(PaymentRequest $request): PaymentResponse
    {
        $token = $this->generateToken();
        $transactionId = $request->orderId ?? 'BANK_' . time() . '_' . Str::random(6);

        $requestData = [
            'merchantId' => $this->merchantId,
            'storeId' => $this->storeId,
            'transactionId' => $transactionId,
            'transactionAmount' => number_format($request->amount, 2, '.', ''),
            'transactionType' => 'BANK',
            'bankAccountNumber' => $request->metadata['bank_account'] ?? '',
            'emailAddress' => $request->customer['email'] ?? '',
            'msisdn' => $request->customer['phone'] ?? '',
            'token' => $token,
            'callbackUrl' => $request->notifyUrl ?? route('payment.easypaisa.callback'),
            'orderRefNum' => $request->orderId ?? '',
            'paymentDesc' => $request->description ?? 'Payment for Order #' . $request->orderId,
            'currencyCode' => $request->currency ?? 'PKR',
        ];

        $response = Http::asForm()
            ->timeout(60)
            ->post($this->baseUrl . '/bank-account', $requestData);

        $responseData = $response->json();

        $this->logInfo('Bank account payment initiated', [
            'request' => $requestData,
            'response' => $responseData,
            'status_code' => $response->status(),
        ]);

        if ($response->successful() && isset($responseData['pp_ResponseCode']) && $responseData['pp_ResponseCode'] === '000') {
            return new PaymentResponse(
                success: true,
                transaction_id: $responseData['pp_TxnRefNo'] ?? $transactionId,
                redirect_url: $responseData['pp_Links'] ?? null,
                message: 'Bank account payment initiated successfully',
                data: $responseData
            );
        }

        return new PaymentResponse(
            success: false,
            message: $responseData['pp_ResponseMessage'] ?? 'Bank account payment failed',
            code: $responseData['pp_ResponseCode'] ?? $response->status(),
            data: $responseData
        );
    }

    /**
     * {@inheritdoc}
     */
    public function verify(array $data): PaymentResponse
    {
        try {
            $this->validateCredentials();

            $transactionId = $data['transaction_id'] ?? $data['pp_TxnRefNo'] ?? null;

            if (!$transactionId) {
                return new PaymentResponse(
                    success: false,
                    message: 'Transaction ID is required',
                    code: 400
                );
            }

            $requestData = [
                'merchantId' => $this->merchantId,
                'token' => $this->generateToken(),
                'transactionId' => $transactionId,
            ];

            $response = Http::asForm()
                ->timeout(30)
                ->post($this->baseUrl . '/status', $requestData);

            $responseData = $response->json();

            $this->logInfo('Payment status checked', [
                'transaction_id' => $transactionId,
                'response' => $responseData,
                'status_code' => $response->status(),
            ]);

            if ($response->successful()) {
                $isSuccess = ($responseData['pp_ResponseCode'] ?? '') === '000';
                $status = $this->mapResponseStatus($responseData['pp_ResponseCode'] ?? '');

                return new PaymentResponse(
                    success: $isSuccess,
                    transaction_id: $responseData['pp_TxnRefNo'] ?? $transactionId,
                    amount: $responseData['pp_Amount'] ?? null,
                    currency: $responseData['pp_Currency'] ?? 'PKR',
                    status: $status,
                    message: $responseData['pp_ResponseMessage'] ?? 'Status retrieved',
                    data: $responseData
                );
            }

            return new PaymentResponse(
                success: false,
                message: 'Unable to verify payment status',
                code: $response->status(),
                data: $responseData
            );

        } catch (\Exception $e) {
            $this->logError('Payment verification failed', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            return new PaymentResponse(
                success: false,
                message: $e->getMessage(),
                code: 500
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function refund(array $data): PaymentResponse
    {
        try {
            $this->validateCredentials();

            $transactionId = $data['transaction_id'] ?? $data['payment_id'] ?? null;
            $refundAmount = $data['amount'] ?? null;
            $reason = $data['reason'] ?? 'Customer requested refund';

            if (!$transactionId) {
                return new PaymentResponse(
                    success: false,
                    message: 'Transaction ID is required for refund',
                    code: 400
                );
            }

            $requestData = [
                'merchantId' => $this->merchantId,
                'token' => $this->generateToken(),
                'originalTransactionId' => $transactionId,
                'refundAmount' => $refundAmount ? number_format($refundAmount, 2, '.', '') : '',
                'refundReason' => $reason,
                'refundTransactionId' => 'REF_' . time() . '_' . Str::random(6),
            ];

            $response = Http::asForm()
                ->timeout(60)
                ->post($this->baseUrl . '/refund', $requestData);

            $responseData = $response->json();

            $this->logInfo('Refund processed', [
                'request' => $requestData,
                'response' => $responseData,
                'status_code' => $response->status(),
            ]);

            if ($response->successful() && isset($responseData['pp_ResponseCode']) && $responseData['pp_ResponseCode'] === '000') {
                return new PaymentResponse(
                    success: true,
                    transaction_id: $responseData['pp_RefundTxnRefNo'] ?? $responseData['refundTransactionId'],
                    message: 'Refund processed successfully',
                    data: $responseData
                );
            }

            return new PaymentResponse(
                success: false,
                message: $responseData['pp_ResponseMessage'] ?? 'Refund failed',
                code: $responseData['pp_ResponseCode'] ?? $response->status(),
                data: $responseData
            );

        } catch (\Exception $e) {
            $this->logError('Refund processing failed', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            return new PaymentResponse(
                success: false,
                message: $e->getMessage(),
                code: 500
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function webhook(Request $request): PaymentResponse
    {
        try {
            $data = $request->all();

            // Log webhook for debugging
            $this->logInfo('EasyPaisa webhook received', [
                'data' => $data,
                'ip' => $request->ip(),
            ]);

            // Verify webhook authenticity
            if (!$this->verifyWebhook($data)) {
                $this->logError('Webhook verification failed', ['data' => $data]);

                return new PaymentResponse(
                    success: false,
                    message: 'Invalid webhook signature',
                    code: 401
                );
            }

            $transactionId = $data['pp_TxnRefNo'] ?? null;
            $responseCode = $data['pp_ResponseCode'] ?? '';
            $amount = $data['pp_Amount'] ?? 0;

            if (!$transactionId) {
                return new PaymentResponse(
                    success: false,
                    message: 'Missing transaction ID',
                    code: 400
                );
            }

            $status = $this->mapResponseStatus($responseCode);
            $isSuccess = $responseCode === '000';

            return new PaymentResponse(
                success: true,
                status: $status,
                transaction_id: $transactionId,
                amount: $amount,
                currency: $data['pp_Currency'] ?? 'PKR',
                message: $data['pp_ResponseMessage'] ?? 'Webhook processed',
                data: array_merge($data, [
                    'payment_type' => $data['pp_TxnType'] ?? 'unknown',
                    'payment_method' => $this->getPaymentMethodFromType($data['pp_TxnType'] ?? ''),
                    'merchant_id' => $data['pp_MerchantId'] ?? '',
                    'store_id' => $data['pp_StoreId'] ?? '',
                ])
            );

        } catch (\Exception $e) {
            $this->logError('Webhook processing failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return new PaymentResponse(
                success: false,
                message: $e->getMessage(),
                code: 500
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function methods(?string $country = null): array
    {
        return [
            [
                'id' => 'mobile_account',
                'name' => 'EasyPaisa Mobile Account',
                'type' => 'wallet',
                'description' => 'Pay using EasyPaisa mobile account balance',
                'currencies' => ['PKR'],
                'countries' => ['PK'],
                'enabled' => true,
                'icon' => 'https://easypaisa.com.pk/images/logo.png',
                'fees' => [
                    'percentage' => 0,
                    'fixed' => 0,
                ],
            ],
            [
                'id' => 'otc',
                'name' => 'Over The Counter (OTC)',
                'type' => 'cash',
                'description' => 'Generate payment voucher and pay cash at any EasyPaisa shop',
                'currencies' => ['PKR'],
                'countries' => ['PK'],
                'enabled' => true,
                'icon' => 'https://easypaisa.com.pk/images/otc.png',
                'fees' => [
                    'percentage' => 0,
                    'fixed' => 0,
                ],
            ],
            [
                'id' => 'bank_account',
                'name' => 'Bank Account',
                'type' => 'bank_transfer',
                'description' => 'Pay using bank account through EasyPaisa',
                'currencies' => ['PKR'],
                'countries' => ['PK'],
                'enabled' => true,
                'icon' => 'https://easypaisa.com.pk/images/bank.png',
                'fees' => [
                    'percentage' => 0,
                    'fixed' => 0,
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsCurrency(string $currency): bool
    {
        return strtoupper($currency) === 'PKR';
    }

    /**
     * Generate authentication token
     */
    private function generateToken(): string
    {
        $timestamp = time();
        $stringToHash = $this->merchantId . ':' . $this->merchantPassword . ':' . $timestamp;
        $hash = hash('sha256', $stringToHash);

        return base64_encode($hash . ':' . $timestamp);
    }

    /**
     * Verify webhook authenticity
     */
    private function verifyWebhook(array $data): bool
    {
        $receivedToken = $data['token'] ?? '';
        $expectedToken = $this->generateToken();

        // For additional security, you can verify the merchant ID matches
        $merchantId = $data['pp_MerchantId'] ?? '';

        return $receivedToken === $expectedToken && $merchantId === $this->merchantId;
    }

    /**
     * Map response code to status
     */
    private function mapResponseStatus(string $responseCode): string
    {
        return match ($responseCode) {
            '000' => 'completed',
            '001' => 'pending',
            '002' => 'failed',
            '003' => 'cancelled',
            '096' => 'pending',
            '097' => 'failed',
            '098' => 'cancelled',
            '099' => 'failed',
            default => 'failed',
        };
    }

    /**
     * Get payment method from transaction type
     */
    private function getPaymentMethodFromType(string $type): string
    {
        return match ($type) {
            'MA' => 'mobile_account',
            'OTC' => 'otc',
            'BANK' => 'bank_account',
            default => 'unknown',
        };
    }

    /**
     * Validate merchant credentials
     */
    private function validateCredentials(): void
    {
        if (empty($this->merchantId) || empty($this->merchantPassword)) {
            throw new \InvalidArgumentException('EasyPaisa merchant credentials are required');
        }
    }

    /**
     * Validate payment request
     */
    private function validateRequest(PaymentRequest $request): void
    {
        if ($request->amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        if ($request->amount < 100) {
            throw new \InvalidArgumentException('Minimum amount is PKR 100');
        }

        if ($request->amount > 500000) {
            throw new \InvalidArgumentException('Maximum amount is PKR 500,000');
        }

        // Validate currency
        if ($request->currency && strtoupper($request->currency) !== 'PKR') {
            throw new \InvalidArgumentException('EasyPaisa only supports PKR currency');
        }
    }
}