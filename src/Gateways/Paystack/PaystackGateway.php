<?php

namespace Mdiqbal\LaravelPayments\Gateways\Paystack;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use Oladejo\LaravelPaystack\Paystack;
use Oladejo\LaravelPaystack\Transaction as PaystackTransaction;
use Oladejo\LaravelPaystack\Customer as PaystackCustomer;
use Oladejo\LaravelPaystack\Refund as PaystackRefund;
use Oladejo\LaravelPaystack\Plan as PaystackPlan;
use Oladejo\LaravelPaystack\Subscription as PaystackSubscription;

class PaystackGateway extends AbstractGateway
{
    private ?Paystack $paystack = null;

    public function gatewayName(): string
    {
        return 'paystack';
    }

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->initializePaystack();
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            $secretKey = $this->getSecretKey();

            if (!$secretKey) {
                throw new PaymentException('Paystack secret key not configured');
            }

            // Initialize Paystack transaction
            $transaction = $this->createTransaction($request);

            $this->log('info', 'Paystack transaction created', [
                'reference' => $transaction->reference,
                'authorization_url' => $transaction->authorization_url,
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency()
            ]);

            return PaymentResponse::redirect($transaction->authorization_url, [
                'transaction_id' => $transaction->reference,
                'reference' => $transaction->reference,
                'access_code' => $transaction->access_code,
                'authorization_url' => $transaction->authorization_url,
                'status' => 'created',
                'message' => 'Transaction created successfully'
            ]);

        } catch (\Exception $e) {
            $this->log('error', 'Paystack payment creation failed', [
                'error' => $e->getMessage(),
                'amount' => $request->getAmount()
            ]);
            throw new PaymentException('Paystack payment failed: ' . $e->getMessage());
        }
    }

    public function verify(array $payload): PaymentResponse
    {
        try {
            // Handle webhook verification
            $reference = $payload['data']['reference'] ?? $payload['reference'] ?? null;

            if (!$reference) {
                throw new PaymentException('No reference found in payload');
            }

            // Verify transaction from Paystack
            $transaction = $this->getPaystack()->transaction->verify($reference);

            if (!$transaction->status) {
                throw new PaymentException('Transaction verification failed');
            }

            $status = match($transaction->status) {
                'success' => 'completed',
                'failed' => 'failed',
                'abandoned' => 'canceled',
                'reversed' => 'refunded',
                default => 'unknown'
            };

            $this->log('info', 'Paystack transaction verified', [
                'reference' => $reference,
                'status' => $status,
                'amount' => $transaction->amount / 100,
                'currency' => $transaction->currency
            ]);

            return new PaymentResponse(
                success: $transaction->status === 'success',
                transactionId: $transaction->reference,
                status: $status,
                data: [
                    'reference' => $transaction->reference,
                    'amount' => $transaction->amount / 100, // Convert from kobo/cents
                    'currency' => $transaction->currency,
                    'gateway_response' => $transaction->gateway_response,
                    'paid_at' => $transaction->paid_at,
                    'channel' => $transaction->channel,
                    'ip_address' => $transaction->ip_address,
                    'plan' => $transaction->plan,
                    'plan_object' => $transaction->plan_object,
                    'customer' => $transaction->customer,
                    'authorization' => $transaction->authorization,
                    'fees' => $transaction->fees / 100,
                    'log' => $transaction->log,
                    'requested_amount' => $transaction->requested_amount / 100,
                    'fees_split' => $transaction->fees_split ?? [],
                    'verification_data' => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Paystack payment verification failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw new PaymentException('Paystack payment verification failed: ' . $e->getMessage());
        }
    }

    public function refund(string $transactionId, float $amount): bool
    {
        try {
            $refund = $this->createRefund($transactionId, $amount);

            $this->log('info', 'Paystack refund processed', [
                'refund_id' => $refund->id,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'status' => $refund->status
            ]);

            return true;

        } catch (\Exception $e) {
            $this->log('error', 'Paystack refund failed', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Paystack refund failed: ' . $e->getMessage());
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * Create a customer in Paystack
     */
    public function createCustomer(array $customerData): string
    {
        try {
            $customer = $this->getPaystack()->customer->create($customerData);
            return $customer->id;
        } catch (\Exception $e) {
            throw new PaymentException('Paystack customer creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a subscription plan
     */
    public function createPlan(array $planData): string
    {
        try {
            $plan = $this->getPaystack()->plan->create($planData);
            return $plan->id;
        } catch (\Exception $e) {
            throw new PaymentException('Paystack plan creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a subscription
     */
    public function createSubscription(array $subscriptionData): PaymentResponse
    {
        try {
            $subscription = $this->getPaystack()->subscription->create($subscriptionData);

            return new PaymentResponse(
                success: true,
                transactionId: $subscription->subscription_code,
                status: $subscription->status,
                data: [
                    'subscription_code' => $subscription->subscription_code,
                    'email_token' => $subscription->email_token,
                    'customer' => $subscription->customer,
                    'plan' => $subscription->plan,
                    'authorization' => $subscription->authorization,
                    'start_date' => $subscription->start_date,
                    'next_payment_date' => $subscription->next_payment_date,
                    'invoice_url' => $subscription->invoice_url ?? null,
                    'subscription_code' => $subscription->subscription_code
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Paystack subscription creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch a transaction
     */
    public function fetchTransaction(string $reference): PaymentResponse
    {
        try {
            $transaction = $this->getPaystack()->transaction->fetch($reference);

            $status = match($transaction->status) {
                'success' => 'completed',
                'failed' => 'failed',
                'abandoned' => 'canceled',
                'reversed' => 'refunded',
                default => 'unknown'
            };

            return new PaymentResponse(
                success: $transaction->status === 'success',
                transactionId: $transaction->reference,
                status: $status,
                data: [
                    'reference' => $transaction->reference,
                    'amount' => $transaction->amount / 100,
                    'currency' => $transaction->currency,
                    'gateway_response' => $transaction->gateway_response,
                    'paid_at' => $transaction->paid_at,
                    'channel' => $transaction->channel,
                    'ip_address' => $transaction->ip_address,
                    'customer' => $transaction->customer,
                    'authorization' => $transaction->authorization
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Paystack transaction: ' . $e->getMessage());
        }
    }

    /**
     * Charge authorization (for recurring payments)
     */
    public function chargeAuthorization(array $chargeData): PaymentResponse
    {
        try {
            $charge = $this->getPaystack()->transaction->chargeAuthorization($chargeData);

            return new PaymentResponse(
                success: $charge->status,
                transactionId: $charge->reference,
                status: $charge->status ? 'completed' : 'pending',
                data: [
                    'reference' => $charge->reference,
                    'amount' => $charge->amount / 100,
                    'currency' => $charge->currency,
                    'customer' => $charge->customer,
                    'authorization' => $charge->authorization
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to charge Paystack authorization: ' . $e->getMessage());
        }
    }

    /**
     * Create transfer recipient
     */
    public function createTransferRecipient(array $recipientData): string
    {
        try {
            $recipient = $this->getPaystack()->transferRecipient->create($recipientData);
            return $recipient->recipient_code;
        } catch (\Exception $e) {
            throw new PaymentException('Failed to create Paystack transfer recipient: ' . $e->getMessage());
        }
    }

    /**
     * Initialize transfer
     */
    public function initializeTransfer(array $transferData): PaymentResponse
    {
        try {
            $transfer = $this->getPaystack()->transfer->initialize($transferData);

            return new PaymentResponse(
                success: true,
                transactionId: $transfer->transfer_code,
                status: 'initialized',
                data: [
                    'transfer_code' => $transfer->transfer_code,
                    'amount' => $transfer->amount / 100,
                    'currency' => $transfer->currency,
                    'recipient' => $transfer->recipient,
                    'status' => $transfer->status
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to initialize Paystack transfer: ' . $e->getMessage());
        }
    }

    /**
     * Process webhook event
     */
    public function processWebhook(array $payload): PaymentResponse
    {
        try {
            $event = $payload;
            $eventType = $event['event'] ?? '';
            $eventData = $event['data'] ?? [];

            // Process different webhook events
            switch ($eventType) {
                case 'charge.success':
                    $status = 'completed';
                    $success = true;
                    $transactionId = $eventData['reference'] ?? null;
                    break;

                case 'charge.failed':
                    $status = 'failed';
                    $success = false;
                    $transactionId = $eventData['reference'] ?? null;
                    break;

                case 'transfer.success':
                    $status = 'completed';
                    $success = true;
                    $transactionId = $eventData['reference'] ?? null;
                    break;

                case 'transfer.failed':
                    $status = 'failed';
                    $success = false;
                    $transactionId = $eventData['reference'] ?? null;
                    break;

                case 'invoice.create':
                    $status = 'created';
                    $success = true;
                    $transactionId = $eventData['reference'] ?? null;
                    break;

                case 'subscription.disable':
                    $status = 'canceled';
                    $success = true;
                    $transactionId = $eventData['subscription_code'] ?? null;
                    break;

                default:
                    $status = 'unknown';
                    $success = false;
                    $transactionId = $eventData['reference'] ?? $eventData['id'] ?? null;
            }

            $amount = $eventData['amount'] ?? 0;
            $currency = $eventData['currency'] ?? 'NGN';

            $this->log('info', 'Paystack webhook processed', [
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
                    'amount' => $amount / 100, // Convert from kobo/cents
                    'currency' => $currency,
                    'customer' => $eventData['customer'] ?? null,
                    'plan' => $eventData['plan'] ?? null,
                    'subscription_code' => $eventData['subscription_code'] ?? null,
                    'webhook_data' => $event
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Paystack webhook processing failed', [
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Paystack webhook processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Initialize Paystack API
     */
    private function initializePaystack(): void
    {
        $secretKey = $this->getSecretKey();

        if ($secretKey) {
            $this->paystack = new Paystack($secretKey);
        }
    }

    /**
     * Get Paystack instance
     */
    private function getPaystack(): Paystack
    {
        if ($this->paystack === null) {
            $secretKey = $this->getSecretKey();

            if (!$secretKey) {
                throw new PaymentException('Paystack secret key not configured');
            }

            $this->paystack = new Paystack($secretKey);
        }

        return $this->paystack;
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
    public function getPublicKey(): ?string
    {
        // Paystack typically uses the same key format
        return $this->getSecretKey();
    }

    /**
     * Create Paystack transaction
     */
    private function createTransaction(PaymentRequest $request): PaystackTransaction
    {
        $amountInKobo = (int) round($request->getAmount() * 100);
        $currency = strtoupper($request->getCurrency() ?? 'NGN');
        $metadata = $request->getMetadata();
        $reference = $request->getTransactionId() ?? $this->generateOrderId();

        $transactionData = [
            'amount' => $amountInKobo,
            'email' => $metadata['email'] ?? 'customer@example.com',
            'reference' => $reference,
            'currency' => $currency,
            'callback_url' => $request->getReturnUrl() ?? route('payment.callback'),
        ];

        // Add optional fields
        if (isset($metadata['callback_url'])) {
            $transactionData['callback_url'] = $metadata['callback_url'];
        }

        if (isset($metadata['plan'])) {
            $transactionData['plan'] = $metadata['plan'];
        }

        if (isset($metadata['metadata']) && is_array($metadata['metadata'])) {
            $transactionData['metadata'] = $metadata['metadata'];
        } else {
            $transactionData['metadata'] = [
                'description' => $request->getDescription() ?? 'Payment',
                'order_id' => $reference
            ];
        }

        // Add customer details
        if (isset($metadata['customer_code'])) {
            $transactionData['customer'] = $metadata['customer_code'];
        }

        if (isset($metadata['subaccount'])) {
            $transactionData['subaccount'] = $metadata['subaccount'];
        }

        if (isset($metadata['transaction_charge'])) {
            $transactionData['transaction_charge'] = $metadata['transaction_charge'];
        }

        if (isset($metadata['bearer'])) {
            $transactionData['bearer'] = $metadata['bearer'];
        }

        // Add channels
        if (isset($metadata['channels']) && is_array($metadata['channels'])) {
            $transactionData['channels'] = $metadata['channels'];
        }

        // Add split information
        if (isset($metadata['split_code'])) {
            $transactionData['split_code'] = $metadata['split_code'];
        }

        // Add due date for scheduled payments
        if (isset($metadata['due_date'])) {
            $transactionData['due_date'] = $metadata['due_date'];
        }

        return $this->getPaystack()->transaction->initialize($transactionData);
    }

    /**
     * Create refund
     */
    private function createRefund(string $reference, float $amount): PaystackRefund
    {
        $refundData = [
            'transaction' => $reference,
        ];

        // Refund specific amount if provided (partial refund)
        if ($amount > 0) {
            $refundData['amount'] = (int) round($amount * 100);
        }

        // Add currency if needed (defaults to NGN)
        $refundData['currency'] = 'NGN';

        return $this->getPaystack()->refund->create($refundData);
    }

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods(): array
    {
        return [
            'card',
            'bank',
            'ussd',
            'qr',
            'mobile_money',
            'bank_transfer',
            'eft'
        ];
    }

    /**
     * Create payment page with custom settings
     */
    public function createPaymentPage(PaymentRequest $request): PaymentResponse
    {
        try {
            $metadata = $request->getMetadata();

            $pageData = [
                'name' => $metadata['page_name'] ?? 'Payment Page',
                'description' => $request->getDescription() ?? 'Payment',
                'amount' => (int) round($request->getAmount() * 100),
                'redirect_url' => $request->getReturnUrl() ?? route('payment.success'),
                'metadata' => $metadata['metadata'] ?? [],
                'channels' => $metadata['channels'] ?? ['card', 'bank'],
            ];

            if (isset($metadata['split_code'])) {
                $pageData['split_code'] = $metadata['split_code'];
            }

            if (isset($metadata['logo'])) {
                $pageData['logo'] = $metadata['logo'];
            }

            $page = $this->getPaystack()->page->create($pageData);

            return PaymentResponse::redirect($page->url, [
                'transaction_id' => $page->slug,
                'page_id' => $page->id,
                'page_url' => $page->url,
                'status' => 'created',
                'message' => 'Payment page created successfully'
            ]);

        } catch (\Exception $e) {
            throw new PaymentException('Failed to create Paystack payment page: ' . $e->getMessage());
        }
    }

    /**
     * Create bulk charges
     */
    public function createBulkCharge(array $charges): PaymentResponse
    {
        try {
            $bulkCharge = $this->getPaystack()->bulkCharge->create([
                'charges' => $charges,
                'callback_url' => route('payment.bulk.callback')
            ]);

            return new PaymentResponse(
                success: true,
                transactionId: $bulkCharge->batch_code,
                status: 'created',
                data: [
                    'batch_code' => $bulkCharge->batch_code,
                    'total_charges' => $bulkCharge->total_charges,
                    'total_amount' => $bulkCharge->total_amount / 100,
                    'currency' => $bulkCharge->currency ?? 'NGN'
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to create Paystack bulk charge: ' . $e->getMessage());
        }
    }

    /**
     * Get transaction timeline
     */
    public function getTransactionTimeline(string $reference): PaymentResponse
    {
        try {
            $timeline = $this->getPaystack()->transaction->timeline($reference);

            return new PaymentResponse(
                success: true,
                transactionId: $reference,
                status: 'completed',
                data: [
                    'timeline' => $timeline->timeline ?? [],
                    'reference' => $reference
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Paystack transaction timeline: ' . $e->getMessage());
        }
    }

    /**
     * Get transaction totals
     */
    public function getTransactionTotals(array $params = []): array
    {
        try {
            $totals = $this->getPaystack()->transaction->totals($params);

            return [
                'total_transactions' => $totals->total_transactions ?? 0,
                'total_volume' => ($totals->total_volume ?? 0) / 100,
                'pending_transfers' => ($totals->pending_transfers ?? 0) / 100,
                'pending_transfers_count' => $totals->pending_transfers_count ?? 0,
            ];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Paystack transaction totals: ' . $e->getMessage());
        }
    }

    /**
     * Export transactions
     */
    public function exportTransactions(array $params = []): string
    {
        try {
            $response = $this->getPaystack()->transaction->export($params);
            return $response->path ?? '';
        } catch (\Exception $e) {
            throw new PaymentException('Failed to export Paystack transactions: ' . $e->getMessage());
        }
    }

    /**
     * Resolve card BIN
     */
    public function resolveCardBin(string $bin): array
    {
        try {
            $resolution = $this->getPaystack()->misc->resolveCardBin($bin);

            return [
                'bin' => $resolution->bin,
                'brand' => $resolution->brand,
                'type' => $resolution->type,
                'country' => $resolution->country,
                'country_code' => $resolution->country_code,
                'bank' => $resolution->bank,
            ];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to resolve Paystack card BIN: ' . $e->getMessage());
        }
    }

    /**
     * List banks
     */
    public function listBanks(string $country = 'nigeria'): array
    {
        try {
            $banks = $this->getPaystack()->misc->listBanks(['country' => $country]);
            return $banks->data ?? [];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to list Paystack banks: ' . $e->getMessage());
        }
    }
}