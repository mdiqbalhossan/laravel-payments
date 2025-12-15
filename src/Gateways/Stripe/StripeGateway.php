<?php

namespace Mdiqbal\LaravelPayments\Gateways\Stripe;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\PaymentIntent;
use Stripe\Refund as StripeRefund;
use Stripe\Webhook;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;

class StripeGateway extends AbstractGateway
{
    private ?StripeClient $stripeClient = null;

    public function gatewayName(): string
    {
        return 'stripe';
    }

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->initializeStripe();
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            $apiKey = $this->getSecretKey();
            if (!$apiKey) {
                throw new PaymentException('Stripe API key not configured');
            }

            // Create PaymentIntent
            $paymentIntent = $this->createPaymentIntent($request);

            $this->log('info', 'Stripe PaymentIntent created', [
                'payment_intent_id' => $paymentIntent->id,
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency()
            ]);

            // Return client secret for frontend payment processing
            return PaymentResponse::success([
                'transaction_id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount / 100, // Convert from cents
                'currency' => $paymentIntent->currency,
                'message' => 'PaymentIntent created successfully'
            ]);

        } catch (\Exception $e) {
            $this->log('error', 'Stripe payment creation failed', [
                'error' => $e->getMessage(),
                'amount' => $request->getAmount()
            ]);
            throw new PaymentException('Stripe payment failed: ' . $e->getMessage());
        }
    }

    public function verify(array $payload): PaymentResponse
    {
        try {
            // Verify webhook signature
            $webhookSecret = $this->getModeConfig('webhook_secret');
            if ($webhookSecret) {
                $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
                if (!$this->verifyWebhookSignature($payload, $signature, $webhookSecret)) {
                    throw new PaymentException('Invalid Stripe webhook signature');
                }
            }

            $event = $payload;
            $eventType = $event['type'] ?? '';
            $eventObject = $event['data']['object'] ?? [];

            // Process different webhook events
            switch ($eventType) {
                case 'payment_intent.succeeded':
                    $status = 'completed';
                    $success = true;
                    $transactionId = $eventObject['id'] ?? null;
                    break;

                case 'payment_intent.payment_failed':
                    $status = 'failed';
                    $success = false;
                    $transactionId = $eventObject['id'] ?? null;
                    break;

                case 'payment_intent.canceled':
                    $status = 'canceled';
                    $success = false;
                    $transactionId = $eventObject['id'] ?? null;
                    break;

                case 'charge.succeeded':
                    $status = 'completed';
                    $success = true;
                    $transactionId = $eventObject['payment_intent'] ?? null;
                    break;

                case 'charge.failed':
                    $status = 'failed';
                    $success = false;
                    $transactionId = $eventObject['payment_intent'] ?? null;
                    break;

                default:
                    $status = 'unknown';
                    $success = false;
                    $transactionId = $eventObject['id'] ?? $eventObject['payment_intent'] ?? null;
            }

            $amount = $eventObject['amount'] ?? 0;
            $currency = $eventObject['currency'] ?? 'usd';

            $this->log('info', 'Stripe webhook verified', [
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
                    'amount' => $amount ? $amount / 100 : 0, // Convert from cents
                    'currency' => strtoupper($currency),
                    'charge_id' => $eventObject['id'] ?? null,
                    'payment_method' => $eventObject['payment_method'] ?? null,
                    'webhook_data' => $event
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Stripe webhook verification failed', [
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Stripe webhook verification failed: ' . $e->getMessage());
        }
    }

    public function refund(string $transactionId, float $amount): bool
    {
        try {
            $refund = $this->createRefund($transactionId, $amount);

            $this->log('info', 'Stripe refund processed', [
                'refund_id' => $refund->id,
                'payment_intent_id' => $transactionId,
                'amount' => $amount
            ]);

            return true;

        } catch (\Exception $e) {
            $this->log('error', 'Stripe refund failed', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Stripe refund failed: ' . $e->getMessage());
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * Confirm a PaymentIntent (for payments that require confirmation)
     */
    public function confirmPayment(string $paymentIntentId): PaymentResponse
    {
        try {
            $paymentIntent = $this->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
            $confirmedIntent = $this->getStripeClient()->paymentIntents->confirm($paymentIntentId);

            $status = $confirmedIntent->status === 'succeeded' ? 'completed' :
                     ($confirmedIntent->status === 'requires_action' ? 'pending' : 'failed');

            return new PaymentResponse(
                success: $confirmedIntent->status === 'succeeded',
                transactionId: $confirmedIntent->id,
                status: $status,
                data: [
                    'client_secret' => $confirmedIntent->client_secret,
                    'next_action' => $confirmedIntent->next_action,
                    'payment_method' => $confirmedIntent->payment_method,
                    'amount' => $confirmedIntent->amount / 100,
                    'currency' => $confirmedIntent->currency
                ]
            );

        } catch (\Exception $e) {
            throw new PaymentException('Stripe payment confirmation failed: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve a PaymentIntent
     */
    public function retrievePayment(string $paymentIntentId): PaymentResponse
    {
        try {
            $paymentIntent = $this->getStripeClient()->paymentIntents->retrieve($paymentIntentId);

            $status = $paymentIntent->status === 'succeeded' ? 'completed' : $paymentIntent->status;

            return new PaymentResponse(
                success: $paymentIntent->status === 'succeeded',
                transactionId: $paymentIntent->id,
                status: $status,
                data: [
                    'client_secret' => $paymentIntent->client_secret,
                    'amount' => $paymentIntent->amount / 100,
                    'currency' => $paymentIntent->currency,
                    'payment_method' => $paymentIntent->payment_method,
                    'charges' => $paymentIntent->charges->data ?? []
                ]
            );

        } catch (\Exception $e) {
            throw new PaymentException('Stripe payment retrieval failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a customer
     */
    public function createCustomer(array $customerData): string
    {
        try {
            $customer = $this->getStripeClient()->customers->create($customerData);
            return $customer->id;
        } catch (\Exception $e) {
            throw new PaymentException('Stripe customer creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a payment method
     */
    public function createPaymentMethod(array $paymentMethodData): string
    {
        try {
            $paymentMethod = $this->getStripeClient()->paymentMethods->create($paymentMethodData);
            return $paymentMethod->id;
        } catch (\Exception $e) {
            throw new PaymentException('Stripe payment method creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Attach payment method to customer
     */
    public function attachPaymentMethod(string $paymentMethodId, string $customerId): bool
    {
        try {
            $this->getStripeClient()->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);
            return true;
        } catch (\Exception $e) {
            throw new PaymentException('Failed to attach payment method: ' . $e->getMessage());
        }
    }

    /**
     * Initialize Stripe SDK
     */
    private function initializeStripe(): void
    {
        $apiKey = $this->getSecretKey();
        if ($apiKey) {
            Stripe::setApiKey($apiKey);
            Stripe::setApiVersion('2023-10-16');
        }
    }

    /**
     * Get Stripe client instance
     */
    private function getStripeClient(): StripeClient
    {
        if ($this->stripeClient === null) {
            $apiKey = $this->getSecretKey();
            if (!$apiKey) {
                throw new PaymentException('Stripe API key not configured');
            }
            $this->stripeClient = new StripeClient([
                'api_key' => $apiKey,
                'stripe_version' => '2023-10-16'
            ]);
        }
        return $this->stripeClient;
    }

    /**
     * Get secret key based on mode
     */
    private function getSecretKey(): ?string
    {
        return $this->getModeConfig('secret_key');
    }

    /**
     * Get publishable key based on mode
     */
    public function getPublishableKey(): ?string
    {
        return $this->getModeConfig('api_key');
    }

    /**
     * Create PaymentIntent
     */
    private function createPaymentIntent(PaymentRequest $request): PaymentIntent
    {
        $amountInCents = (int) round($request->getAmount() * 100);
        $currency = strtolower($request->getCurrency() ?? 'USD');
        $metadata = $request->getMetadata();

        $intentParams = [
            'amount' => $amountInCents,
            'currency' => $currency,
            'description' => $request->getDescription() ?? 'Payment',
            'metadata' => [
                'transaction_id' => $request->getTransactionId() ?? $this->generateOrderId(),
            ],
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
        ];

        // Add customer if provided
        if (isset($metadata['customer_id'])) {
            $intentParams['customer'] = $metadata['customer_id'];
        }

        // Add payment method if provided
        if (isset($metadata['payment_method_id'])) {
            $intentParams['payment_method'] = $metadata['payment_method_id'];
            $intentParams['confirm'] = true;
            $intentParams['off_session'] = $metadata['off_session'] ?? false;
        }

        // Add email for guest checkout
        if (isset($metadata['email'])) {
            $intentParams['receipt_email'] = $metadata['email'];
        }

        // Add custom metadata
        if (isset($metadata['custom']) && is_array($metadata['custom'])) {
            $intentParams['metadata'] = array_merge($intentParams['metadata'], $metadata['custom']);
        }

        // Add shipping information if provided
        if (isset($metadata['shipping'])) {
            $intentParams['shipping'] = $metadata['shipping'];
        }

        // Add application fee (for platforms)
        if (isset($metadata['application_fee_amount'])) {
            $intentParams['application_fee_amount'] = (int) round($metadata['application_fee_amount'] * 100);
        }

        // Add transfer data (for connected accounts)
        if (isset($metadata['transfer_data'])) {
            $intentParams['transfer_data'] = $metadata['transfer_data'];
        }

        return $this->getStripeClient()->paymentIntents->create($intentParams);
    }

    /**
     * Create refund
     */
    private function createRefund(string $paymentIntentId, float $amount): StripeRefund
    {
        $refundParams = [
            'payment_intent' => $paymentIntentId,
        ];

        // Refund specific amount if provided (partial refund)
        if ($amount > 0) {
            $refundParams['amount'] = (int) round($amount * 100);
        }

        // Add reason if provided
        $refundParams['reason'] = 'requested_by_customer';

        return $this->getStripeClient()->refunds->create($refundParams);
    }

    /**
     * Verify webhook signature
     */
    private function verifyWebhookSignature(array $payload, string $signature, string $webhookSecret): bool
    {
        try {
            $payloadString = json_encode($payload);
            Webhook::constructEvent($payloadString, $signature, $webhookSecret);
            return true;
        } catch (SignatureVerificationException $e) {
            $this->log('error', 'Stripe webhook signature verification failed', [
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
            'card',
            'klarna',
            'afterpay_clearpay',
            'alipay',
            'ideal',
            'bancontact',
            'giropay',
            'eps',
            'sofort',
            'sepa_debit',
            'acss_debit',
            'affirm',
            'cashapp',
            'paypal',
            'us_bank_account',
            'link',
        ];
    }

    /**
     * Create checkout session (for redirect-based payments)
     */
    public function createCheckoutSession(PaymentRequest $request): PaymentResponse
    {
        try {
            $amountInCents = (int) round($request->getAmount() * 100);
            $currency = strtolower($request->getCurrency() ?? 'USD');
            $metadata = $request->getMetadata();

            $sessionParams = [
                'payment_method_types' => $metadata['payment_method_types'] ?? ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $request->getDescription() ?? 'Payment',
                            'description' => $metadata['product_description'] ?? '',
                        ],
                        'unit_amount' => $amountInCents,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $request->getReturnUrl() ?? route('payment.success'),
                'cancel_url' => $request->getCancelUrl() ?? route('payment.cancel'),
                'metadata' => [
                    'transaction_id' => $request->getTransactionId() ?? $this->generateOrderId(),
                ],
            ];

            // Add customer if provided
            if (isset($metadata['customer_id'])) {
                $sessionParams['customer'] = $metadata['customer_id'];
            }

            // Add custom metadata
            if (isset($metadata['custom']) && is_array($metadata['custom'])) {
                $sessionParams['metadata'] = array_merge($sessionParams['metadata'], $metadata['custom']);
            }

            // Add shipping options if needed
            if (isset($metadata['shipping_options'])) {
                $sessionParams['shipping_options'] = $metadata['shipping_options'];
            }

            $session = $this->getStripeClient()->checkout->sessions->create($sessionParams);

            return PaymentResponse::redirect($session->url, [
                'transaction_id' => $session->id,
                'session_id' => $session->id,
                'status' => 'created',
                'message' => 'Redirect to Stripe Checkout'
            ]);

        } catch (\Exception $e) {
            throw new PaymentException('Stripe Checkout session creation failed: ' . $e->getMessage());
        }
    }
}