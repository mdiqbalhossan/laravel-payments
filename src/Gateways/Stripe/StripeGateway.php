<?php

namespace Mdiqbal\LaravelPayments\Gateways\Stripe;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
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
                'amount' => $this->fromMinorUnits($paymentIntent->amount, $paymentIntent->currency),
                'currency' => strtoupper($paymentIntent->currency),
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

    /**
     * Verify a payment after redirect/callback.
     *
     * The payload is never trusted on its own: the PaymentIntent (or Checkout
     * Session) is always re-fetched from Stripe. Accepts payment_intent /
     * payment_intent_id / session_id / id, or a Stripe event array.
     */
    public function verify(array $payload): PaymentResponse
    {
        $object = $payload['data']['object'] ?? [];
        $sessionId = $payload['session_id'] ?? (str_starts_with((string) ($payload['id'] ?? ''), 'cs_') ? $payload['id'] : null);
        $intentFromEvent = ($object['object'] ?? null) === 'payment_intent'
            ? ($object['id'] ?? null)
            : ($object['payment_intent'] ?? null);
        $intentId = $payload['payment_intent'] ?? $payload['payment_intent_id'] ?? $intentFromEvent;

        if (!$intentId && str_starts_with((string) ($payload['id'] ?? ''), 'pi_')) {
            $intentId = $payload['id'];
        }

        try {
            if (!$intentId && $sessionId) {
                $session = $this->getStripeClient()->checkout->sessions->retrieve($sessionId);
                $intentId = is_string($session->payment_intent) ? $session->payment_intent : ($session->payment_intent->id ?? null);
            }

            if (!$intentId) {
                throw new PaymentException('No PaymentIntent or Checkout Session id found in payload');
            }

            return $this->retrievePayment($intentId);
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->log('error', 'Stripe verification failed', ['error' => $e->getMessage()]);
            throw new PaymentException('Stripe verification failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Handle a Stripe webhook: verifies the Stripe-Signature header against the
     * raw body using the configured webhook_secret (whsec_...).
     */
    public function handleWebhook(array $payload, string $rawBody, array $headers): PaymentResponse
    {
        $secret = $this->getModeConfig('webhook_secret');
        if (!$secret) {
            throw PaymentException::configuration('stripe', 'webhook_secret is required to accept Stripe webhooks');
        }

        try {
            $event = Webhook::constructEvent($rawBody, $headers['stripe-signature'] ?? '', $secret);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            throw new InvalidSignatureException('Invalid Stripe webhook signature: ' . $e->getMessage(), gateway: 'stripe');
        }

        return $this->parseEvent($event->toArray());
    }

    /**
     * Map a (signature-verified) Stripe event to a PaymentResponse.
     */
    protected function parseEvent(array $event): PaymentResponse
    {
        $eventType = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        [$status, $success] = match ($eventType) {
            'payment_intent.succeeded', 'charge.succeeded' => ['completed', true],
            'checkout.session.completed' => ($object['payment_status'] ?? '') === 'paid' ? ['completed', true] : ['pending', false],
            'payment_intent.payment_failed', 'charge.failed' => ['failed', false],
            'payment_intent.canceled' => ['canceled', false],
            'charge.refunded' => ['refunded', false],
            default => ['unknown', false],
        };

        $transactionId = ($object['object'] ?? '') === 'payment_intent'
            ? ($object['id'] ?? null)
            : ($object['payment_intent'] ?? $object['id'] ?? null);

        $currency = strtoupper($object['currency'] ?? 'USD');
        $minor = $object['amount_received'] ?? $object['amount_total'] ?? $object['amount'] ?? null;

        return new PaymentResponse(
            success: $success,
            transactionId: $transactionId,
            status: $status,
            data: [
                'event_type' => $eventType,
                'event_id' => $event['id'] ?? null,
                'order_id' => $object['metadata']['transaction_id'] ?? $object['client_reference_id'] ?? null,
                'webhook_data' => $event,
            ],
            gatewayReference: $object['id'] ?? null,
            amount: $minor !== null ? $this->fromMinorUnits($minor, $currency) : null,
            currency: $currency
        );
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
            $currency = strtoupper($paymentIntent->currency);

            return new PaymentResponse(
                success: $paymentIntent->status === 'succeeded',
                transactionId: $paymentIntent->id,
                status: $status,
                data: [
                    'client_secret' => $paymentIntent->client_secret,
                    'order_id' => $paymentIntent->metadata['transaction_id'] ?? null,
                    'payment_method' => $paymentIntent->payment_method,
                    'latest_charge' => $paymentIntent->latest_charge ?? null,
                ],
                gatewayReference: $paymentIntent->id,
                amount: $this->fromMinorUnits($paymentIntent->amount_received ?: $paymentIntent->amount, $currency),
                currency: $currency
            );

        } catch (\Exception $e) {
            throw new PaymentException('Stripe payment retrieval failed: ' . $e->getMessage(), 0, $e);
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
        $currency = strtolower($request->getCurrency() ?: 'USD');
        $amountInCents = $this->toMinorUnits($request->getAmount(), $currency);
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
        if ($email = $metadata['email'] ?? $request->getEmail()) {
            $intentParams['receipt_email'] = $email;
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
            $intent = $this->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
            $refundParams['amount'] = $this->toMinorUnits($amount, $intent->currency);
        }

        // Add reason if provided
        $refundParams['reason'] = 'requested_by_customer';

        return $this->getStripeClient()->refunds->create($refundParams);
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
            $currency = strtolower($request->getCurrency() ?: 'USD');
            $amountInCents = $this->toMinorUnits($request->getAmount(), $currency);
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
                'client_reference_id' => $request->getTransactionId(),
                'metadata' => [
                    'transaction_id' => $request->getTransactionId() ?? $this->generateOrderId(),
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'transaction_id' => $request->getTransactionId(),
                    ],
                ],
            ];

            // Add customer if provided
            if (isset($metadata['customer_id'])) {
                $sessionParams['customer'] = $metadata['customer_id'];
            } elseif ($request->getEmail()) {
                $sessionParams['customer_email'] = $request->getEmail();
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