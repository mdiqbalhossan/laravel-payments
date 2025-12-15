<?php

namespace Mdiqbal\LaravelPayments\Gateways\Paytm;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use TechTailor\Paytm\Paytm as PaytmSDK;
use TechTailor\Paytm\Transaction as PaytmTransaction;
use TechTailor\Paytm\Refund as PaytmRefund;
use TechTailor\Paytm\Status as PaytmStatus;

class PaytmGateway extends AbstractGateway
{
    private ?PaytmSDK $paytm = null;

    public function gatewayName(): string
    {
        return 'paytm';
    }

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->initializePaytm();
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            $merchantId = $this->getMerchantId();
            $merchantKey = $this->getMerchantKey();

            if (!$merchantId || !$merchantKey) {
                throw new PaymentException('Paytm merchant credentials not configured');
            }

            // Initialize Paytm transaction
            $transaction = $this->createTransaction($request);

            $this->log('info', 'Paytm transaction created', [
                'order_id' => $transaction->getOrderId(),
                'txn_token' => $transaction->getTxnToken(),
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency()
            ]);

            // Generate payment URL
            $paymentUrl = $this->getPaymentUrl($transaction);

            return PaymentResponse::redirect($paymentUrl, [
                'transaction_id' => $transaction->getOrderId(),
                'order_id' => $transaction->getOrderId(),
                'txn_token' => $transaction->getTxnToken(),
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency(),
                'status' => 'created',
                'message' => 'Transaction created successfully'
            ]);

        } catch (\Exception $e) {
            $this->log('error', 'Paytm payment creation failed', [
                'error' => $e->getMessage(),
                'amount' => $request->getAmount()
            ]);
            throw new PaymentException('Paytm payment failed: ' . $e->getMessage());
        }
    }

    public function verify(array $payload): PaymentResponse
    {
        try {
            // Verify the checksum
            if (!$this->verifyChecksum($payload)) {
                throw new PaymentException('Invalid Paytm checksum');
            }

            $orderId = $payload['ORDERID'] ?? null;
            $transactionId = $payload['TXNID'] ?? null;
            $status = $payload['STATUS'] ?? null;

            if (!$orderId || !$transactionId) {
                throw new PaymentException('Invalid transaction response');
            }

            // Get transaction status from Paytm
            $statusResponse = $this->getTransactionStatus($orderId);

            $paymentStatus = match($status) {
                'TXN_SUCCESS', 'SUCCESS' => 'completed',
                'TXN_FAILURE', 'FAILURE' => 'failed',
                'PENDING' => 'pending',
                'OPEN' => 'pending',
                default => 'unknown'
            };

            $this->log('info', 'Paytm transaction verified', [
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'status' => $paymentStatus
            ]);

            return new PaymentResponse(
                success: $status === 'TXN_SUCCESS',
                transactionId: $transactionId,
                status: $paymentStatus,
                data: [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'bank_transaction_id' => $payload['BANKTXNID'] ?? null,
                    'bank_name' => $payload['BANKNAME'] ?? null,
                    'payment_mode' => $payload['PAYMENTMODE'] ?? null,
                    'gateway_name' => $payload['GATEWAYNAME'] ?? null,
                    'response_code' => $payload['RESPCODE'] ?? null,
                    'response_message' => $payload['RESPMSG'] ?? null,
                    'transaction_date' => $payload['TXNDATE'] ?? null,
                    'amount' => ($payload['TXNAMOUNT'] ?? 0) / 100, // Convert from paise
                    'currency' => 'INR',
                    'checksum_hash' => $payload['CHECKSUMHASH'] ?? null,
                    'mid' => $payload['MID'] ?? null,
                    'verification_data' => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Paytm payment verification failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw new PaymentException('Paytm payment verification failed: ' . $e->getMessage());
        }
    }

    public function refund(string $transactionId, float $amount): bool
    {
        try {
            $refund = $this->createRefund($transactionId, $amount);

            $this->log('info', 'Paytm refund processed', [
                'refund_id' => $refund->getRefundId(),
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'status' => $refund->getStatus()
            ]);

            return true;

        } catch (\Exception $e) {
            $this->log('error', 'Paytm refund failed', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Paytm refund failed: ' . $e->getMessage());
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * Get transaction status
     */
    public function getTransactionStatus(string $orderId): PaymentResponse
    {
        try {
            $status = $this->getPaytm()->getStatus($orderId);

            return new PaymentResponse(
                success: $status->getStatus() === 'TXN_SUCCESS',
                transactionId: $status->getTransactionId(),
                status: $status->getStatus() === 'TXN_SUCCESS' ? 'completed' : $status->getStatus(),
                data: [
                    'order_id' => $orderId,
                    'transaction_id' => $status->getTransactionId(),
                    'amount' => $status->getAmount() / 100,
                    'currency' => 'INR',
                    'status' => $status->getStatus(),
                    'response_code' => $status->getResponseCode(),
                    'response_message' => $status->getResponseMessage(),
                    'bank_transaction_id' => $status->getBankTransactionId(),
                    'bank_name' => $status->getBankName(),
                    'payment_mode' => $status->getPaymentMode()
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to get Paytm transaction status: ' . $e->getMessage());
        }
    }

    /**
     * Initiate refund
     */
    public function initiateRefund(array $refundData): PaymentResponse
    {
        try {
            $refund = $this->getPaytm()->initiateRefund($refundData);

            return new PaymentResponse(
                success: true,
                transactionId: $refund->getRefundId(),
                status: 'initiated',
                data: [
                    'refund_id' => $refund->getRefundId(),
                    'order_id' => $refund->getOrderId(),
                    'transaction_id' => $refund->getTransactionId(),
                    'refund_amount' => $refund->getRefundAmount() / 100,
                    'status' => $refund->getStatus()
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to initiate Paytm refund: ' . $e->getMessage());
        }
    }

    /**
     * Check refund status
     */
    public function checkRefundStatus(string $refundId): PaymentResponse
    {
        try {
            $refund = $this->getPaytm()->getRefundStatus($refundId);

            $status = match($refund->getStatus()) {
                'SUCCESS' => 'completed',
                'PENDING' => 'pending',
                'FAILURE' => 'failed',
                default => 'unknown'
            };

            return new PaymentResponse(
                success: $refund->getStatus() === 'SUCCESS',
                transactionId: $refund->getRefundId(),
                status: $status,
                data: [
                    'refund_id' => $refund->getRefundId(),
                    'order_id' => $refund->getOrderId(),
                    'transaction_id' => $refund->getTransactionId(),
                    'refund_amount' => $refund->getRefundAmount() / 100,
                    'status' => $refund->getStatus(),
                    'refunded_at' => $refund->getRefundedAt()
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to check Paytm refund status: ' . $e->getMessage());
        }
    }

    /**
     * Validate Paytm checksum
     */
    public function validateChecksum(array $params, string $checksum): bool
    {
        try {
            return $this->getPaytm()->verifyChecksum($params, $checksum);
        } catch (\Exception $e) {
            $this->log('error', 'Checksum validation failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Process webhook event
     */
    public function processWebhook(array $payload): PaymentResponse
    {
        try {
            // Verify webhook checksum
            if (!$this->verifyChecksum($payload)) {
                throw new PaymentException('Invalid Paytm webhook checksum');
            }

            $eventType = $payload['eventType'] ?? 'transaction';
            $orderId = $payload['ORDERID'] ?? null;
            $transactionId = $payload['TXNID'] ?? null;
            $status = $payload['STATUS'] ?? null;

            $paymentStatus = match($status) {
                'TXN_SUCCESS', 'SUCCESS' => 'completed',
                'TXN_FAILURE', 'FAILURE' => 'failed',
                'PENDING' => 'pending',
                'REFUND' => 'refunded',
                default => 'unknown'
            };

            $this->log('info', 'Paytm webhook processed', [
                'event_type' => $eventType,
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'status' => $paymentStatus
            ]);

            return new PaymentResponse(
                success: $status === 'TXN_SUCCESS',
                transactionId: $transactionId,
                status: $paymentStatus,
                data: [
                    'event_type' => $eventType,
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'bank_transaction_id' => $payload['BANKTXNID'] ?? null,
                    'payment_mode' => $payload['PAYMENTMODE'] ?? null,
                    'amount' => ($payload['TXNAMOUNT'] ?? 0) / 100,
                    'currency' => 'INR',
                    'response_code' => $payload['RESPCODE'] ?? null,
                    'response_message' => $payload['RESPMSG'] ?? null,
                    'webhook_data' => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Paytm webhook processing failed', [
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Paytm webhook processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Initialize Paytm SDK
     */
    private function initializePaytm(): void
    {
        $merchantId = $this->getMerchantId();
        $merchantKey = $this->getMerchantKey();
        $environment = $this->isSandbox() ? 'test' : 'production';

        if ($merchantId && $merchantKey) {
            $this->paytm = new PaytmSDK($merchantId, $merchantKey, $environment);
        }
    }

    /**
     * Get Paytm SDK instance
     */
    private function getPaytm(): PaytmSDK
    {
        if ($this->paytm === null) {
            $merchantId = $this->getMerchantId();
            $merchantKey = $this->getMerchantKey();
            $environment = $this->isSandbox() ? 'test' : 'production';

            if (!$merchantId || !$merchantKey) {
                throw new PaymentException('Paytm merchant credentials not configured');
            }

            $this->paytm = new PaytmSDK($merchantId, $merchantKey, $environment);
        }

        return $this->paytm;
    }

    /**
     * Get merchant ID based on mode
     */
    private function getMerchantId(): ?string
    {
        return $this->getModeConfig('merchant_id');
    }

    /**
     * Get merchant key based on mode
     */
    private function getMerchantKey(): ?string
    {
        return $this->getModeConfig('merchant_key');
    }

    /**
     * Get website based on mode
     */
    private function getWebsite(): string
    {
        return $this->isSandbox() ? 'WEBSTAGING' : 'DEFAULT';
    }

    /**
     * Get industry type
     */
    private function getIndustryType(): string
    {
        return 'Retail';
    }

    /**
     * Create Paytm transaction
     */
    private function createTransaction(PaymentRequest $request): PaytmTransaction
    {
        $amountInPaise = (int) round($request->getAmount() * 100);
        $currency = $request->getCurrency() ?? 'INR';
        $metadata = $request->getMetadata();
        $orderId = $request->getTransactionId() ?? $this->generateOrderId();

        $transactionData = [
            'order_id' => $orderId,
            'amount' => $amountInPaise,
            'currency' => $currency,
            'customer_id' => $metadata['customer_id'] ?? null,
            'customer_email' => $metadata['email'] ?? 'customer@example.com',
            'customer_phone' => $metadata['phone'] ?? null,
            'callback_url' => $request->getReturnUrl() ?? route('payment.callback'),
            'website' => $this->getWebsite(),
            'industry_type' => $this->getIndustryType(),
            'channel_id' => 'WEB',
            'description' => $request->getDescription() ?? 'Payment',
        ];

        // Add additional parameters
        if (isset($metadata['custid'])) {
            $transactionData['custid'] = $metadata['custid'];
        }

        if (isset($metadata['mobile_no'])) {
            $transactionData['mobile_no'] = $metadata['mobile_no'];
        }

        if (isset($metadata['email'])) {
            $transactionData['email'] = $metadata['email'];
        }

        if (isset($metadata['theme'])) {
            $transactionData['theme'] = $metadata['theme'];
        }

        if (isset($metadata['billing_address'])) {
            $transactionData['billing_address'] = $metadata['billing_address'];
        }

        if (isset($metadata['shipping_address'])) {
            $transactionData['shipping_address'] = $metadata['shipping_address'];
        }

        if (isset($metadata['ui_mode'])) {
            $transactionData['ui_mode'] = $metadata['ui_mode'];
        }

        // Add custom parameters as notes
        if (isset($metadata['notes']) && is_array($metadata['notes'])) {
            $transactionData['notes'] = $metadata['notes'];
        }

        return $this->getPaytm()->transaction($transactionData);
    }

    /**
     * Get payment URL
     */
    private function getPaymentUrl(PaytmTransaction $transaction): string
    {
        $baseUrl = $this->isSandbox()
            ? 'https://securegw-stage.paytm.in/theia/processTransaction'
            : 'https://securegw.paytm.in/theia/processTransaction';

        $params = [
            'orderid' => $transaction->getOrderId(),
            'mid' => $this->getMerchantId(),
            'txnToken' => $transaction->getTxnToken(),
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
            'theme' => 'merchandise',
            'loadingText' => 'Processing Payment...',
        ];

        return $baseUrl . '?' . http_build_query($params);
    }

    /**
     * Get transaction status
     */
    private function getTransactionStatus(string $orderId): string
    {
        try {
            $status = $this->getPaytm()->getStatus($orderId);
            return $status->getStatus() ?? 'PENDING';
        } catch (\Exception $e) {
            $this->log('error', 'Failed to get transaction status', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            return 'FAILURE';
        }
    }

    /**
     * Create refund
     */
    private function createRefund(string $transactionId, float $amount): PaytmRefund
    {
        $refundData = [
            'transaction_id' => $transactionId,
            'refund_amount' => (int) round($amount * 100),
            'refund_reason' => 'Customer requested refund',
        ];

        return $this->getPaytm()->refund($refundData);
    }

    /**
     * Verify checksum
     */
    private function verifyChecksum(array $payload): bool
    {
        try {
            // Extract checksum from payload
            $checksum = $payload['CHECKSUMHASH'] ?? '';
            unset($payload['CHECKSUMHASH']);

            // Remove any empty values
            $payload = array_filter($payload, function($value) {
                return $value !== '';
            });

            return $this->getPaytm()->verifyChecksum($payload, $checksum);
        } catch (\Exception $e) {
            $this->log('error', 'Checksum verification failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods(): array
    {
        return [
            'credit_card',
            'debit_card',
            'net_banking',
            'upi',
            'paytm_wallet',
            'emi',
            'cash_card'
        ];
    }

    /**
     * Create subscription
     */
    public function createSubscription(array $subscriptionData): PaymentResponse
    {
        try {
            $subscription = $this->getPaytm()->createSubscription($subscriptionData);

            return new PaymentResponse(
                success: true,
                transactionId: $subscription->getSubscriptionId(),
                status: 'created',
                data: [
                    'subscription_id' => $subscription->getSubscriptionId(),
                    'order_id' => $subscription->getOrderId(),
                    'transaction_id' => $subscription->getTransactionId(),
                    'amount' => $subscription->getAmount() / 100,
                    'status' => $subscription->getStatus(),
                    'subscription_status' => $subscription->getSubscriptionStatus()
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to create Paytm subscription: ' . $e->getMessage());
        }
    }

    /**
     * Get subscription status
     */
    public function getSubscriptionStatus(string $subscriptionId): PaymentResponse
    {
        try {
            $subscription = $this->getPaytm()->getSubscriptionStatus($subscriptionId);

            return new PaymentResponse(
                success: in_array($subscription->getStatus(), ['ACTIVE', 'SUCCESS']),
                transactionId: $subscriptionId,
                status: $subscription->getStatus(),
                data: [
                    'subscription_id' => $subscriptionId,
                    'order_id' => $subscription->getOrderId(),
                    'status' => $subscription->getStatus(),
                    'amount' => $subscription->getAmount() / 100,
                    'next_billing_date' => $subscription->getNextBillingDate(),
                    'created_at' => $subscription->getCreatedAt()
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to get Paytm subscription status: ' . $e->getMessage());
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(string $subscriptionId): bool
    {
        try {
            $this->getPaytm()->cancelSubscription($subscriptionId);
            return true;
        } catch (\Exception $e) {
            throw new PaymentException('Failed to cancel Paytm subscription: ' . $e->getMessage());
        }
    }

    /**
     * Validate transaction parameters
     */
    private function validateTransactionParams(array $params): array
    {
        // Required parameters
        $required = ['MID', 'ORDERID', 'TXNAMOUNT', 'CURRENCY', 'CHECKSUMHASH'];

        foreach ($required as $param) {
            if (!isset($params[$param])) {
                throw new PaymentException("Missing required parameter: {$param}");
            }
        }

        // Validate amount
        if ($params['TXNAMOUNT'] <= 0) {
            throw new PaymentException('Invalid transaction amount');
        }

        // Validate currency
        if ($params['CURRENCY'] !== 'INR') {
            throw new PaymentException('Only INR currency is supported');
        }

        return $params;
    }

    /**
     * Generate checksum
     */
    public function generateChecksum(array $params): string
    {
        try {
            return $this->getPaytm()->generateChecksum($params);
        } catch (\Exception $e) {
            throw new PaymentException('Failed to generate Paytm checksum: ' . $e->getMessage());
        }
    }

    /**
     * Get payment modes
     */
    public function getPaymentModes(): array
    {
        try {
            $modes = $this->getPaytm()->getPaymentModes();
            return $modes->getModes() ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to get Paytm payment modes: ' . $e->getMessage());
        }
    }

    /**
     * Create recurring payment
     */
    public function createRecurringPayment(array $recurringData): PaymentResponse
    {
        try {
            $recurring = $this->getPaytm()->createRecurring($recurringData);

            return new PaymentResponse(
                success: true,
                transactionId: $recurring->getOrderId(),
                status: 'initiated',
                data: [
                    'order_id' => $recurring->getOrderId(),
                    'transaction_id' => $recurring->getTransactionId(),
                    'amount' => $recurring->getAmount() / 100,
                    'frequency' => $recurring->getFrequency(),
                    'start_date' => $recurring->getStartDate(),
                    'end_date' => $recurring->getEndDate()
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to create Paytm recurring payment: ' . $e->getMessage());
        }
    }
}