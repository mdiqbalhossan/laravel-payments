<?php

namespace Mdiqbal\LaravelPayments\Gateways\Flutterwave;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use Flutterwave\Flutterwave as FlutterwaveSDK;
use Flutterwave\Services\Payments;
use Flutterwave\Services\Refunds;
use Flutterwave\Services\Subscriptions;
use Flutterwave\Services\PaymentPlan;
use Flutterwave\Services\Customers;

class FlutterwaveGateway extends AbstractGateway
{
    private ?FlutterwaveSDK $flutterwave = null;

    public function gatewayName(): string
    {
        return 'flutterwave';
    }

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->initializeFlutterwave();
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            $secretKey = $this->getSecretKey();

            if (!$secretKey) {
                throw new PaymentException('Flutterwave secret key not configured');
            }

            // Initialize payment
            $payment = $this->createPayment($request);

            $this->log('info', 'Flutterwave payment created', [
                'tx_ref' => $payment->data['tx_ref'] ?? null,
                'redirect_url' => $payment->data['link'] ?? null,
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency()
            ]);

            return PaymentResponse::redirect($payment->data['link'], [
                'transaction_id' => $payment->data['tx_ref'] ?? $payment->data['id'],
                'tx_ref' => $payment->data['tx_ref'] ?? null,
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency(),
                'status' => 'created',
                'message' => 'Payment created successfully'
            ]);

        } catch (\Exception $e) {
            $this->log('error', 'Flutterwave payment creation failed', [
                'error' => $e->getMessage(),
                'amount' => $request->getAmount()
            ]);
            throw new PaymentException('Flutterwave payment failed: ' . $e->getMessage());
        }
    }

    public function verify(array $payload): PaymentResponse
    {
        try {
            $transactionId = $payload['transaction_id'] ?? $payload['tx_ref'] ?? null;

            if (!$transactionId) {
                throw new PaymentException('No transaction ID found in payload');
            }

            // Verify transaction from Flutterwave
            $transaction = $this->getTransaction($transactionId);

            $status = match($transaction->data['status']) {
                'successful' => 'completed',
                'failed' => 'failed',
                'cancelled' => 'canceled',
                'pending' => 'pending',
                default => 'unknown'
            };

            $this->log('info', 'Flutterwave transaction verified', [
                'tx_ref' => $transaction->data['tx_ref'],
                'transaction_id' => $transaction->data['id'],
                'status' => $status,
                'amount' => $transaction->data['amount']
            ]);

            return new PaymentResponse(
                success: $transaction->data['status'] === 'successful',
                transactionId: $transaction->data['tx_ref'] ?? $transaction->data['id'],
                status: $status,
                data: [
                    'tx_ref' => $transaction->data['tx_ref'],
                    'transaction_id' => $transaction->data['id'],
                    'amount' => $transaction->data['amount'],
                    'currency' => $transaction->data['currency'] ?? $request->getCurrency(),
                    'payment_type' => $transaction->data['payment_type'],
                    'customer' => $transaction->data['customer'] ?? null,
                    'payment_method' => $transaction->data['payment_method'] ?? null,
                    'created_at' => $transaction->data['created_at'],
                    'charged_amount' => $transaction->data['amount'] ?? 0,
                    'app_fee' => $transaction->data['app_fee'] ?? 0,
                    'merchant_fee' => $transaction->data['merchant_fee'] ?? 0,
                    'processor_response' => $transaction->data['processor_response'] ?? null,
                    'verification_data' => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Flutterwave payment verification failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw new PaymentException('Flutterwave payment verification failed: ' . $e->getMessage());
        }
    }

    public function refund(string $transactionId, float $amount): bool
    {
        try {
            $refund = $this->createRefund($transactionId, $amount);

            $this->log('info', 'Flutterwave refund processed', [
                'refund_id' => $refund->data['id'] ?? null,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'status' => $refund->data['status'] ?? null
            ]);

            return true;

        } catch (\Exception $e) {
            $this->log('error', 'Flutterwave refund failed', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Flutterwave refund failed: ' . $e->getMessage());
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * Create a customer in Flutterwave
     */
    public function createCustomer(array $customerData): array
    {
        try {
            $response = $this->getFlutterwave()->customer()->create($customerData);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Flutterwave customer creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get customer details
     */
    public function getCustomer(string $email): array
    {
        try {
            $response = $this->getFlutterwave()->customer()->find($email);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Flutterwave customer: ' . $e->getMessage());
        }
    }

    /**
     * Create a payment plan
     */
    public function createPaymentPlan(array $planData): array
    {
        try {
            $response = $this->getFlutterwave()->paymentPlan()->create($planData);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Flutterwave payment plan creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get payment plans
     */
    public function getPaymentPlans(array $params = []): array
    {
        try {
            $response = $this->getFlutterwave()->paymentPlan()->list($params);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Flutterwave payment plans: ' . $e->getMessage());
        }
    }

    /**
     * Create a subscription
     */
    public function createSubscription(array $subscriptionData): PaymentResponse
    {
        try {
            $response = $this->getFlutterwave()->subscription()->create($subscriptionData);

            return new PaymentResponse(
                success: true,
                transactionId: $response->data['id'] ?? $response->data['tx_ref'],
                status: $response->data['status'] ?? 'created',
                data: [
                    'id' => $response->data['id'] ?? null,
                    'tx_ref' => $response->data['tx_ref'] ?? null,
                    'plan' => $response->data['plan'] ?? null,
                    'customer' => $response->data['customer'] ?? null,
                    'amount' => $response->data['amount'] ?? 0,
                    'currency' => $response->data['currency'] ?? null,
                    'status' => $response->data['status'],
                    'created_at' => $response->data['created_at'] ?? null,
                    'next_payment_date' => $response->data['next_payment_date'] ?? null
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Flutterwave subscription creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get subscription details
     */
    public function getSubscription(string $subscriptionId): array
    {
        try {
            $response = $this->getFlutterwave()->subscription()->find($subscriptionId);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Flutterwave subscription: ' . $e->getMessage());
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(string $subscriptionId): bool
    {
        try {
            $response = $this->getFlutterwave()->subscription()->cancel($subscriptionId);
            return isset($response->data['status']) && $response->data['status'] === 'cancelled';
        } catch (\Exception $e) {
            throw new PaymentException('Failed to cancel Flutterwave subscription: ' . $e->getMessage());
        }
    }

    /**
     * Get transaction details
     */
    public function getTransaction(string $transactionId): PaymentResponse
    {
        try {
            $response = $this->getFlutterwave()->payment()->find($transactionId);

            $status = match($response->data['status'] ?? '') {
                'successful' => 'completed',
                'failed' => 'failed',
                'cancelled' => 'canceled',
                'pending' => 'pending',
                default => 'unknown'
            };

            return new PaymentResponse(
                success: $response->data['status'] === 'successful',
                transactionId: $response->data['tx_ref'] ?? $response->data['id'],
                status: $status,
                data: [
                    'tx_ref' => $response->data['tx_ref'] ?? null,
                    'transaction_id' => $response->data['id'] ?? null,
                    'amount' => $response->data['amount'] ?? 0,
                    'currency' => $response->data['currency'] ?? null,
                    'status' => $response->data['status'],
                    'payment_type' => $response->data['payment_type'] ?? null,
                    'customer' => $response->data['customer'] ?? null,
                    'payment_method' => $response->data['payment_method'] ?? null,
                    'created_at' => $response->data['created_at'] ?? null,
                    'charged_amount' => $response->data['amount'] ?? 0,
                    'app_fee' => $response->data['app_fee'] ?? 0,
                    'merchant_fee' => $response->data['merchant_fee'] ?? 0,
                    'processor_response' => $response->data['processor_response'] ?? null
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Flutterwave transaction: ' . $e->getMessage());
        }
    }

    /**
     * Create payment link
     */
    public function createPaymentLink(PaymentRequest $request): PaymentResponse
    {
        try {
            $metadata = $request->getMetadata();

            $linkData = [
                'tx_ref' => $request->getTransactionId() ?? $this->generateOrderId(),
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency() ?? 'NGN',
                'description' => $request->getDescription() ?? 'Payment',
                'title' => $metadata['title'] ?? 'Payment',
                'redirect_url' => $request->getReturnUrl() ?? route('payment.success'),
            ];

            if (isset($metadata['customer'])) {
                $linkData['customer'] = $metadata['customer'];
            }

            $response = $this->getFlutterwave()->payment()->createPaymentLink($linkData);

            return PaymentResponse::redirect($response->data['link'], [
                'transaction_id' => $response->data['data']['link_ref'],
                'link_ref' => $response->data['data']['link_ref'],
                'payment_url' => $response->data['link'],
                'status' => 'created',
                'message' => 'Payment link created successfully'
            ]);

        } catch (\Exception $e) {
            throw new PaymentException('Failed to create Flutterwave payment link: ' . $e->getMessage());
        }
    }

    /**
     * Create payment link (alias method)
     */
    public function createPaymentLinkWithUrl(string $url, PaymentRequest $request): PaymentResponse
    {
        return $this->createPaymentLink($request);
    }

    /**
     * Get supported banks
     */
    public function getSupportedBanks(string $country = 'NG'): array
    {
        try {
            $response = $this->getFlutterwave()->miscellaneous()->getBanks($country);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Flutterwave banks: ' . $e->getMessage());
        }
    }

    /**
     * Validate card BIN
     */
    public function validateCardBin(string $bin): array
    {
        try {
            $response = $this->getFlutterwave()->miscellaneous()->validateBin($bin);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to validate card BIN: ' . $e->getMessage());
        }
    }

    /**
     * Process webhook event
     */
    public function processWebhook(array $payload): PaymentResponse
    {
        try {
            $eventType = $payload['event'] ?? '';
            $eventData = $payload['data'] ?? [];

            // Process different webhook events
            switch ($eventType) {
                case 'charge.completed':
                    $status = 'completed';
                    $success = true;
                    $transactionId = $eventData['tx_ref'] ?? $eventData['id'] ?? null;
                    break;

                case 'charge.failed':
                    $status = 'failed';
                    $success = false;
                    $transactionId = $eventData['tx_ref'] ?? $eventData['id'] ?? null;
                    break;

                case 'charge.successful':
                    $status = 'completed';
                    $success = true;
                    $transactionId = $eventData['tx_ref'] ?? $eventData['id'] ?? null;
                    break;

                case 'charge.failed.invalid':
                    $status = 'failed';
                    $success = false;
                    $transactionId = $eventData['tx_ref'] ?? $eventData['id'] ?? null;
                    break;

                case 'refund.completed':
                    $status = 'refunded';
                    $success = true;
                    $transactionId = $eventData['tx_ref'] ?? $eventData['id'] ?? null;
                    break;

                case 'subscription.created':
                    $status = 'created';
                    $success = true;
                    $transactionId = $eventData['tx_ref'] ?? $eventData['id'] ?? null;
                    break;

                case 'subscription.completed':
                    $status = 'completed';
                    $success = true;
                    $transactionId = $eventData['tx_ref'] ?? $eventData['id'] ?? null;
                    break;

                case 'subscription.cancelled':
                    $status = 'canceled';
                    $success = false;
                    $transactionId = $eventData['tx_ref'] ?? $eventData['id'] ?? null;
                    break;

                default:
                    $status = 'unknown';
                    $success = false;
                    $transactionId = $eventData['tx_ref'] ?? $eventData['id'] ?? null;
            }

            $amount = $eventData['amount'] ?? 0;
            $currency = $eventData['currency'] ?? 'NGN';

            $this->log('info', 'Flutterwave webhook processed', [
                'event_type' => $eventType,
                'transaction_id' => $transactionId,
                'status' => $status
            ]);

            return new PaymentResponse(
                success: $success,
                transactionId: $transactionId,
                status: $status,
                data: [
                    'event_type' => $eventType,
                    'amount' => $amount,
                    'currency' => $currency,
                    'customer' => $eventData['customer'] ?? null,
                    'payment_method' => $eventData['payment_method'] ?? null,
                    'payment_type' => $eventData['payment_type'] ?? null,
                    'flw_ref' => $eventData['flw_ref'] ?? null,
                    'webhook_data' => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Flutterwave webhook processing failed', [
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Flutterwave webhook processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Initialize Flutterwave SDK
     */
    private function initializeFlutterwave(): void
    {
        $secretKey = $this->getSecretKey();
        $publicKey = $this->getPublicKey();

        if ($secretKey) {
            $config = [
                'secret_key' => $secretKey,
            ];

            if ($publicKey) {
                $config['public_key'] = $publicKey;
            }

            $this->flutterwave = new FlutterwaveSDK($config);
        }
    }

    /**
     * Get Flutterwave SDK instance
     */
    private function getFlutterwave(): FlutterwaveSDK
    {
        if ($this->flutterwave === null) {
            $secretKey = $this->getSecretKey();

            if (!$secretKey) {
                throw new PaymentException('Flutterwave secret key not configured');
            }

            $config = ['secret_key' => $secretKey];
            $publicKey = $this->getPublicKey();
            if ($publicKey) {
                $config['public_key'] = $publicKey;
            }

            $this->flutterwave = new FlutterwaveSDK($config);
        }

        return $this->flutterwave;
    }

    /**
     * Get secret key based on mode
     */
    private function getSecretKey(): ?string
    {
        return $this->getModeConfig('secret_key');
    }

    /**
     * Get public key based on mode
     */
    private function getPublicKey(): ?string
    {
        return $this->getModeConfig('public_key');
    }

    /**
     * Get encryption key based on mode
     */
    private function getEncryptionKey(): ?string
    {
        return $this->getModeConfig('encryption_key');
    }

    /**
     * Create payment
     */
    private function createPayment(PaymentRequest $request): array
    {
        $amount = $request->getAmount();
        $currency = $request->getCurrency() ?? 'NGN';
        $metadata = $request->getMetadata();
        $txRef = $request->getTransactionId() ?? $this->generateOrderId();

        $paymentData = [
            'tx_ref' => $txRef,
            'amount' => $amount,
            'currency' => $currency,
            'redirect_url' => $request->getReturnUrl() ?? route('payment.callback'),
            'payment_options' => $metadata['payment_options'] ?? 'card,mobilemoney,ussd,banktransfer',
            'meta' => [
                'description' => $request->getDescription() ?? 'Payment',
                'order_id' => $txRef
            ]
        ];

        // Add customer details if provided
        if (isset($metadata['customer'])) {
            $paymentData['customer'] = $metadata['customer'];
        }

        // Add customizations if provided
        if (isset($metadata['customizations'])) {
            $paymentData['customizations'] = $metadata['customizations'];
        }

        // Add subaccount if provided
        if (isset($metadata['subaccount'])) {
            $paymentData['subaccount'] = $metadata['subaccount'];
        }

        // Add split information
        if (isset($metadata['split'])) {
            $paymentData['split'] = $metadata['split'];
        }

        // Add split_info (for multiple splits)
        if (isset($metadata['split_info'])) {
            $paymentData['split_info'] = $metadata['split_info'];
        }

        // Add payment plan for subscriptions
        if (isset($metadata['payment_plan'])) {
            $paymentData['payment_plan'] = $metadata['payment_plan'];
        }

        // Add other optional parameters
        if (isset($metadata['phone_number'])) {
            $paymentData['phone_number'] = $metadata['phone_number'];
        }

        if (isset($metadata['email'])) {
            $paymentData['email'] = $metadata['email'];
        }

        if (isset($metadata['client_ip'])) {
            $paymentData['client_ip'] = $metadata['client_ip'];
        }

        // Initialize payment (without redirect)
        $response = $this->getFlutterwave()->payment()->initiate($paymentData);

        // If we need to show a custom form, we can return the initialization response
        // Otherwise, we'll create a payment link for redirect
        if ($metadata['custom_form'] ?? false) {
            return $response;
        } else {
            // Create a payment link for redirect
            return $this->getFlutterwave()->payment()->createPaymentLink([
                'tx_ref' => $txRef,
                'amount' => $amount,
                'currency' => $currency,
                'description' => $request->getDescription() ?? 'Payment',
                'redirect_url' => $paymentData['redirect_url'],
                'customer' => $paymentData['customer'] ?? null
            ]);
        }
    }

    /**
     * Get transaction details
     */
    private function getTransaction(string $txRef): array
    {
        try {
            return $this->getFlutterwave()->payment()->find($txRef);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to get transaction details', [
                'tx_ref' => $txRef,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create refund
     */
    private function createRefund(string $transactionId, float $amount): array
    {
        $refundData = [
            'tx_ref' => $transactionId,
            'amount' => $amount,
            'reason' => 'Customer requested refund'
        ];

        return $this->getFlutterwave()->refund()->create($refundData);
    }

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods(): array
    {
        return [
            'card',
            'mobilemoney',
            'ussd',
            'bank_transfer',
            'qr',
            'applepay',
            'googlepay',
            'paypal',
            'mpesa',
            'mobilemoney_uganda',
            'mobilemoney_ghana',
            'mobile_money_francophone',
            'barter'
        ];
    }

    /**
     * Get exchange rates
     */
    public function getExchangeRates(string $currency = 'NGN'): array
    {
        try {
            $response = $this->getFlutterwave()->miscellaneous()->getExchangeRates($currency);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Flutterwave exchange rates: ' . $e->getMessage());
        }
    }

    /**
     * Get transfer rates
     */
    public function getTransferRates(string $sourceCurrency = 'NGN'): array
    {
        try {
            $response = $this->getFlutterwave()->miscellaneous()->getTransferRates($sourceCurrency);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Flutterwave transfer rates: ' . $e->getMessage());
        }
    }

    /**
     * Validate webhook signature
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        try {
            $secretHash = $this->getSecretKey();
            $computedHash = hash_hmac('sha512', $payload, $secretHash);

            return hash_equals($signature, $computedHash);
        } catch (\Exception $e) {
            $this->log('error', 'Webhook signature validation failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get settlement status
     */
    public function getSettlementStatus(array $params = []): array
    {
        try {
            $response = $this->getFlutterwave()->payment()->getSettlements($params);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Flutterwave settlement status: ' . $e->getMessage());
        }
    }

    /**
     * Get balance
     */
    public function getBalance(): array
    {
        try {
            $response = $this->getFlutterwave()->miscellaneous()->balance();
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Flutterwave balance: ' . $e->getMessage());
        }
    }

    /**
     * Create transfer
     */
    public function createTransfer(array $transferData): PaymentResponse
    {
        try {
            $response = $this->getFlutterwave()->transfer()->create($transferData);

            return new PaymentResponse(
                success: true,
                transactionId: $response->data['id'] ?? null,
                status: 'initiated',
                data: [
                    'transfer_id' => $response->data['id'] ?? null,
                    'reference' => $response->data['reference'] ?? null,
                    'amount' => $response->data['amount'] ?? 0,
                    'currency' => $response->data['currency'] ?? null,
                    'status' => $response->data['status'] ?? null,
                    'beneficiary_name' => $response->data['beneficiary_name'] ?? null,
                    'created_at' => $response->data['created_at'] ?? null
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to create Flutterwave transfer: ' . $e->getMessage());
        }
    }

    /**
     * Create beneficiary
     */
    public function createBeneficiary(array $beneficiaryData): array
    {
        try {
            $response = $this->getFlutterwave()->beneficiary()->create($beneficiaryData);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to create Flutterwave beneficiary: ' . $e->getMessage());
        }
    }

    /**
     * Get beneficiaries
     */
    public function getBeneficiaries(array $params = []): array
    {
        try {
            $response = $this->getFlutterwave()->beneficiary()->list($params);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Flutterwave beneficiaries: ' . $e->getMessage());
        }
    }

    /**
     * Create virtual account
     */
    public function createVirtualAccount(array $accountData): array
    {
        try {
            $response = $this->getFlutterwave()->virtualAccount()->create($accountData);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to create Flutterwave virtual account: ' . $e->getMessage());
        }
    }

    /**
     * Get virtual accounts
     */
    public function getVirtualAccounts(array $params = []): array
    {
        try {
            $response = $this->getFlutterwave()->virtualAccount()->list($params);
            return $response->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Flutterwave virtual accounts: ' . $e->getMessage());
        }
    }
}