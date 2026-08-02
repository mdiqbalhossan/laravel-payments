<?php

namespace Mdiqbal\LaravelPayments\Gateways\Paystack;

use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

class PaystackGateway extends AbstractGateway
{
    private const PAYSTACK_BASE_URL = 'https://api.paystack.co';

    public function gatewayName(): string
    {
        return 'paystack';
    }

    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * Make HTTP request to Paystack API
     */
    protected function makePaystackRequest(string $method, string $endpoint, array $data = []): array
    {
        $secretKey = $this->getSecretKey();

        if (!$secretKey) {
            throw new PaymentException('Paystack secret key not configured');
        }

        $url = self::PAYSTACK_BASE_URL . $endpoint;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $secretKey,
            'Content-Type' => 'application/json',
        ])->{strtolower($method)}($url, $data);

        if (!$response->successful()) {
            $error = $response->json();
            $message = $error['message'] ?? 'Paystack API request failed';
            throw new PaymentException($message);
        }

        return $response->json();
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
                'reference' => $transaction['reference'],
                'authorization_url' => $transaction['authorization_url'],
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency()
            ]);

            return PaymentResponse::redirect($transaction['authorization_url'], [
                'transaction_id' => $transaction['reference'],
                'reference' => $transaction['reference'],
                'access_code' => $transaction['access_code'],
                'authorization_url' => $transaction['authorization_url'],
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
            $response = $this->makePaystackRequest('GET', '/transaction/verify/' . $reference);
            $transaction = $response['data'] ?? [];

            if (!isset($transaction['status'])) {
                throw new PaymentException('Transaction verification failed');
            }

            $status = match($transaction['status']) {
                'success' => 'completed',
                'failed' => 'failed',
                'abandoned' => 'canceled',
                'reversed' => 'refunded',
                default => 'unknown'
            };

            $this->log('info', 'Paystack transaction verified', [
                'reference' => $reference,
                'status' => $status,
                'amount' => ($transaction['amount'] ?? 0) / 100,
                'currency' => $transaction['currency'] ?? 'NGN'
            ]);

            return new PaymentResponse(
                success: $transaction['status'] === 'success',
                transactionId: $transaction['reference'] ?? $reference,
                status: $status,
                data: [
                    'reference' => $transaction['reference'] ?? $reference,
                    'amount' => ($transaction['amount'] ?? 0) / 100, // Convert from kobo/cents
                    'currency' => $transaction['currency'] ?? 'NGN',
                    'gateway_response' => $transaction['gateway_response'] ?? null,
                    'paid_at' => $transaction['paid_at'] ?? null,
                    'channel' => $transaction['channel'] ?? null,
                    'ip_address' => $transaction['ip_address'] ?? null,
                    'plan' => $transaction['plan'] ?? null,
                    'plan_object' => $transaction['plan_object'] ?? null,
                    'customer' => $transaction['customer'] ?? null,
                    'authorization' => $transaction['authorization'] ?? null,
                    'fees' => ($transaction['fees'] ?? 0) / 100,
                    'log' => $transaction['log'] ?? null,
                    'requested_amount' => ($transaction['requested_amount'] ?? 0) / 100,
                    'fees_split' => $transaction['fees_split'] ?? [],
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
                'refund_id' => $refund['id'] ?? null,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'status' => $refund['status'] ?? null
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
        $secretKey = $this->getSecretKey();
        
        if (!$secretKey) {
            return null;
        }

        // Paystack public key is derived from secret key
        // sk_test_xxx -> pk_test_xxx, sk_live_xxx -> pk_live_xxx
        if (str_starts_with($secretKey, 'sk_test_')) {
            return str_replace('sk_test_', 'pk_test_', $secretKey);
        } elseif (str_starts_with($secretKey, 'sk_live_')) {
            return str_replace('sk_live_', 'pk_live_', $secretKey);
        }

        return null;
    }

    /**
     * Create Paystack transaction
     */
    private function createTransaction(PaymentRequest $request): array
    {
        $amountInKobo = (int) round($request->getAmount() * 100);
        $metadata = $request->getMetadata();
        $reference = $request->getTransactionId() ?? $this->generateOrderId();

        $currency = strtoupper($request->getCurrency() ?? 'ZAR'); // Default to ZAR
        
        $transactionData = [
            'amount' => $amountInKobo,
            'email' => $metadata['email'] ?? 'customer@example.com',
            'reference' => $reference,
            'currency' => $currency,
            'callback_url' => $request->getReturnUrl() ?? route('payment.callback'),
        ];
        
        // Paystack supports: NGN, GHS, ZAR, USD
        // Currency is always included (defaults to ZAR if not specified)

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

        $response = $this->makePaystackRequest('POST', '/transaction/initialize', $transactionData);
        return $response['data'] ?? [];
    }

    /**
     * Create refund
     */
    private function createRefund(string $reference, float $amount): array
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

        $response = $this->makePaystackRequest('POST', '/refund', $refundData);
        return $response['data'] ?? [];
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
     * Fetch a transaction
     */
    public function fetchTransaction(string $reference): PaymentResponse
    {
        try {
            $response = $this->makePaystackRequest('GET', '/transaction/verify/' . $reference);
            $transaction = $response['data'] ?? [];

            $status = match($transaction['status'] ?? 'unknown') {
                'success' => 'completed',
                'failed' => 'failed',
                'abandoned' => 'canceled',
                'reversed' => 'refunded',
                default => 'unknown'
            };

            return new PaymentResponse(
                success: ($transaction['status'] ?? '') === 'success',
                transactionId: $transaction['reference'] ?? $reference,
                status: $status,
                data: [
                    'reference' => $transaction['reference'] ?? $reference,
                    'amount' => ($transaction['amount'] ?? 0) / 100,
                    'currency' => $transaction['currency'] ?? 'NGN',
                    'gateway_response' => $transaction['gateway_response'] ?? null,
                    'paid_at' => $transaction['paid_at'] ?? null,
                    'channel' => $transaction['channel'] ?? null,
                    'ip_address' => $transaction['ip_address'] ?? null,
                    'customer' => $transaction['customer'] ?? null,
                    'authorization' => $transaction['authorization'] ?? null
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Paystack transaction: ' . $e->getMessage());
        }
    }
}
