<?php

namespace Mdiqbal\LaravelPayments\Gateways\Razorpay;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use Razorpay\Api\Api;
use Razorpay\Api\Order;
use Razorpay\Api\Payment as RazorpayPayment;
use Razorpay\Api\Refund as RazorpayRefund;
use Razorpay\Api\Subscription;
use Razorpay\Api\Plan;
use Razorpay\Api\Customer;
use Razorpay\Api\Card;

class RazorpayGateway extends AbstractGateway
{
    private ?Api $razorpay = null;

    public function gatewayName(): string
    {
        return 'razorpay';
    }

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->initializeRazorpay();
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            $keyId = $this->getKeyId();
            $keySecret = $this->getKeySecret();

            if (!$keyId || !$keySecret) {
                throw new PaymentException('Razorpay API keys not configured');
            }

            // Create Razorpay Order
            $order = $this->createOrder($request);

            $this->log('info', 'Razorpay order created', [
                'order_id' => $order->id,
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency()
            ]);

            return PaymentResponse::success([
                'transaction_id' => $order->id,
                'razorpay_order_id' => $order->id,
                'amount' => $order->amount / 100, // Convert from paise to rupees
                'currency' => $order->currency,
                'receipt' => $order->receipt,
                'status' => $order->status,
                'key_id' => $keyId,
                'message' => 'Order created successfully'
            ]);

        } catch (\Exception $e) {
            $this->log('error', 'Razorpay payment creation failed', [
                'error' => $e->getMessage(),
                'amount' => $request->getAmount()
            ]);
            throw new PaymentException('Razorpay payment failed: ' . $e->getMessage());
        }
    }

    public function verify(array $payload): PaymentResponse
    {
        try {
            // Verify payment signature
            $signature = $payload['razorpay_signature'] ?? '';
            $orderId = $payload['razorpay_order_id'] ?? '';
            $paymentId = $payload['razorpay_payment_id'] ?? '';

            if (!$this->verifySignature($orderId, $paymentId, $signature)) {
                throw new PaymentException('Invalid Razorpay signature');
            }

            // Fetch payment details from Razorpay
            $payment = $this->getRazorpay()->payment->fetch($paymentId);

            if ($payment->status !== 'captured') {
                throw new PaymentException('Payment not captured');
            }

            $this->log('info', 'Razorpay payment verified', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'amount' => $payment->amount / 100,
                'status' => $payment->status
            ]);

            return new PaymentResponse(
                success: true,
                transactionId: $paymentId,
                status: 'completed',
                data: [
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'amount' => $payment->amount / 100,
                    'currency' => $payment->currency,
                    'method' => $payment->method,
                    'email' => $payment->email,
                    'contact' => $payment->contact,
                    'fee' => $payment->fee / 100,
                    'tax' => $payment->tax / 100,
                    'payment_capture' => $payment->capture ? 'yes' : 'no',
                    'card_id' => $payment->card_id ?? null,
                    'bank' => $payment->bank ?? null,
                    'wallet' => $payment->wallet ?? null,
                    'vpa' => $payment->vpa ?? null,
                    'verification_payload' => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Razorpay payment verification failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw new PaymentException('Razorpay payment verification failed: ' . $e->getMessage());
        }
    }

    public function refund(string $transactionId, float $amount): bool
    {
        try {
            $refund = $this->createRefund($transactionId, $amount);

            $this->log('info', 'Razorpay refund processed', [
                'refund_id' => $refund->id,
                'payment_id' => $transactionId,
                'amount' => $amount,
                'status' => $refund->status
            ]);

            return true;

        } catch (\Exception $e) {
            $this->log('error', 'Razorpay refund failed', [
                'payment_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Razorpay refund failed: ' . $e->getMessage());
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * Create a customer in Razorpay
     */
    public function createCustomer(array $customerData): string
    {
        try {
            $customer = $this->getRazorpay()->customer->create($customerData);
            return $customer->id;
        } catch (\Exception $e) {
            throw new PaymentException('Razorpay customer creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a subscription plan
     */
    public function createPlan(array $planData): string
    {
        try {
            $plan = $this->getRazorpay()->plan->create($planData);
            return $plan->id;
        } catch (\Exception $e) {
            throw new PaymentException('Razorpay plan creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a subscription
     */
    public function createSubscription(array $subscriptionData): PaymentResponse
    {
        try {
            $subscription = $this->getRazorpay()->subscription->create($subscriptionData);

            return new PaymentResponse(
                success: true,
                transactionId: $subscription->id,
                status: $subscription->status,
                data: [
                    'subscription_id' => $subscription->id,
                    'plan_id' => $subscription->plan_id,
                    'customer_id' => $subscription->customer_id,
                    'current_start' => $subscription->current_start,
                    'current_end' => $subscription->current_end,
                    'short_url' => $subscription->short_url,
                    'has_trial' => $subscription->has_trial,
                    'trial_start' => $subscription->trial_start,
                    'trial_end' => $subscription->trial_end
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Razorpay subscription creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch a payment
     */
    public function fetchPayment(string $paymentId): PaymentResponse
    {
        try {
            $payment = $this->getRazorpay()->payment->fetch($paymentId);

            $status = match($payment->status) {
                'captured' => 'completed',
                'authorized' => 'pending',
                'failed' => 'failed',
                'refunded' => 'refunded',
                default => 'unknown'
            };

            return new PaymentResponse(
                success: $payment->status === 'captured',
                transactionId: $payment->id,
                status: $status,
                data: [
                    'amount' => $payment->amount / 100,
                    'currency' => $payment->currency,
                    'method' => $payment->method,
                    'email' => $payment->email,
                    'contact' => $payment->contact,
                    'order_id' => $payment->order_id,
                    'invoice_id' => $payment->invoice_id,
                    'description' => $payment->description,
                    'notes' => $payment->notes ?? [],
                    'created_at' => $payment->created_at
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Razorpay payment: ' . $e->getMessage());
        }
    }

    /**
     * Capture a payment
     */
    public function capturePayment(string $paymentId, float $amount): PaymentResponse
    {
        try {
            $amountInPaise = (int) round($amount * 100);
            $payment = $this->getRazorpay()->payment->fetch($paymentId)->capture(['amount' => $amountInPaise]);

            return new PaymentResponse(
                success: $payment->status === 'captured',
                transactionId: $payment->id,
                status: 'completed',
                data: [
                    'amount' => $payment->amount / 100,
                    'currency' => $payment->currency,
                    'method' => $payment->method,
                    'captured_at' => $payment->created_at
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to capture Razorpay payment: ' . $e->getMessage());
        }
    }

    /**
     * Get payment details by order ID
     */
    public function getPaymentByOrderId(string $orderId): PaymentResponse
    {
        try {
            $order = $this->getRazorpay()->order->fetch($orderId);
            $payments = $this->getRazorpay()->order->fetch($orderId)->payments();

            $paymentDetails = [];
            foreach ($payments->items as $payment) {
                $paymentDetails[] = [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'amount' => $payment->amount / 100,
                    'method' => $payment->method
                ];
            }

            $status = match($order->status) {
                'created' => 'pending',
                'attempted' => 'processing',
                'paid' => 'completed',
                default => 'unknown'
            };

            return new PaymentResponse(
                success: $order->status === 'paid',
                transactionId: $orderId,
                status: $status,
                data: [
                    'order_id' => $order->id,
                    'amount' => $order->amount / 100,
                    'currency' => $order->currency,
                    'receipt' => $order->receipt,
                    'attempts' => $order->attempts,
                    'payments_count' => $order->payments_count,
                    'payments' => $paymentDetails,
                    'notes' => $order->notes ?? []
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Razorpay order: ' . $e->getMessage());
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
            $eventData = $event['payload']['payment']['entity'] ?? [];

            // Process different webhook events
            switch ($eventType) {
                case 'payment.authorized':
                    $status = 'authorized';
                    $success = true;
                    $transactionId = $eventData['id'] ?? null;
                    break;

                case 'payment.captured':
                    $status = 'completed';
                    $success = true;
                    $transactionId = $eventData['id'] ?? null;
                    break;

                case 'payment.failed':
                    $status = 'failed';
                    $success = false;
                    $transactionId = $eventData['id'] ?? null;
                    break;

                case 'refund.processed':
                    $status = 'refunded';
                    $success = true;
                    $transactionId = $eventData['payment_id'] ?? null;
                    break;

                case 'order.paid':
                    $status = 'completed';
                    $success = true;
                    $transactionId = $eventData['id'] ?? null;
                    break;

                default:
                    $status = 'unknown';
                    $success = false;
                    $transactionId = $eventData['id'] ?? $eventData['order_id'] ?? null;
            }

            $amount = $eventData['amount'] ?? 0;
            $currency = $eventData['currency'] ?? 'INR';

            $this->log('info', 'Razorpay webhook processed', [
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
                    'amount' => $amount / 100, // Convert from paise
                    'currency' => $currency,
                    'method' => $eventData['method'] ?? null,
                    'email' => $eventData['email'] ?? null,
                    'contact' => $eventData['contact'] ?? null,
                    'webhook_data' => $event
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Razorpay webhook processing failed', [
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Razorpay webhook processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Initialize Razorpay API
     */
    private function initializeRazorpay(): void
    {
        $keyId = $this->getKeyId();
        $keySecret = $this->getKeySecret();

        if ($keyId && $keySecret) {
            $this->razorpay = new Api($keyId, $keySecret);
        }
    }

    /**
     * Get Razorpay API instance
     */
    private function getRazorpay(): Api
    {
        if ($this->razorpay === null) {
            $keyId = $this->getKeyId();
            $keySecret = $this->getKeySecret();

            if (!$keyId || !$keySecret) {
                throw new PaymentException('Razorpay API keys not configured');
            }

            $this->razorpay = new Api($keyId, $keySecret);
        }

        return $this->razorpay;
    }

    /**
     * Get key ID based on mode
     */
    private function getKeyId(): ?string
    {
        return $this->getModeConfig('key_id');
    }

    /**
     * Get key secret based on mode
     */
    private function getKeySecret(): ?string
    {
        return $this->getModeConfig('key_secret');
    }

    /**
     * Create Razorpay order
     */
    private function createOrder(PaymentRequest $request): Order
    {
        $amountInPaise = (int) round($request->getAmount() * 100);
        $currency = strtoupper($request->getCurrency() ?? 'INR');
        $metadata = $request->getMetadata();

        $orderData = [
            'amount' => $amountInPaise,
            'currency' => $currency,
            'receipt' => $request->getTransactionId() ?? $this->generateOrderId(),
            'notes' => [
                'description' => $request->getDescription() ?? 'Payment',
            ],
            'payment_capture' => 1, // Auto-capture payment
        ];

        // Add additional notes if provided
        if (isset($metadata['notes']) && is_array($metadata['notes'])) {
            $orderData['notes'] = array_merge($orderData['notes'], $metadata['notes']);
        }

        // Add customer email and contact if provided
        if (isset($metadata['email'])) {
            $orderData['notes']['email'] = $metadata['email'];
        }
        if (isset($metadata['contact'])) {
            $orderData['notes']['contact'] = $metadata['contact'];
        }

        // Add customer ID if provided
        if (isset($metadata['customer_id'])) {
            $orderData['customer_id'] = $metadata['customer_id'];
        }

        return $this->getRazorpay()->order->create($orderData);
    }

    /**
     * Create refund
     */
    private function createRefund(string $paymentId, float $amount): RazorpayRefund
    {
        $refundData = [
            'payment_id' => $paymentId,
        ];

        // Refund specific amount if provided (partial refund)
        if ($amount > 0) {
            $refundData['amount'] = (int) round($amount * 100);
        }

        // Add notes if needed
        $refundData['notes'] = [
            'reason' => 'requested_by_customer',
            'refund_source' => 'api'
        ];

        return $this->getRazorpay()->refund->create($refundData);
    }

    /**
     * Verify Razorpay signature
     */
    private function verifySignature(string $orderId, string $paymentId, string $signature): bool
    {
        try {
            $keySecret = $this->getKeySecret();
            if (!$keySecret) {
                return false;
            }

            $generatedSignature = hash_hmac(
                'sha256',
                $orderId . '|' . $paymentId,
                $keySecret
            );

            return hash_equals($generatedSignature, $signature);
        } catch (\Exception $e) {
            $this->log('error', 'Signature verification failed', [
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
            'netbanking',
            'wallet',
            'emi',
            'upi',
            'paylater'
        ];
    }

    /**
     * Create payment link for sharing
     */
    public function createPaymentLink(PaymentRequest $request): PaymentResponse
    {
        try {
            $amountInPaise = (int) round($request->getAmount() * 100);
            $metadata = $request->getMetadata();

            $linkData = [
                'amount' => $amountInPaise,
                'currency' => strtoupper($request->getCurrency() ?? 'INR'),
                'accept_partial' => false,
                'description' => $request->getDescription() ?? 'Payment',
                'customer' => [
                    'email' => $metadata['email'] ?? null,
                    'contact' => $metadata['contact'] ?? null,
                    'name' => $metadata['customer_name'] ?? null,
                ],
                'notify' => [
                    'sms' => $metadata['notify_sms'] ?? false,
                    'email' => $metadata['notify_email'] ?? true,
                ],
                'reminder_enable' => $metadata['reminder_enable'] ?? true,
                'notes' => $metadata['notes'] ?? [],
            ];

            if (isset($metadata['callback_url'])) {
                $linkData['callback_url'] = $metadata['callback_url'];
            }
            if (isset($metadata['callback_method'])) {
                $linkData['callback_method'] = $metadata['callback_method'];
            }

            $link = $this->getRazorpay()->paymentLink->create($linkData);

            return PaymentResponse::redirect($link->short_url, [
                'transaction_id' => $link->id,
                'payment_link_id' => $link->id,
                'short_url' => $link->short_url,
                'status' => 'created',
                'message' => 'Payment link created successfully'
            ]);

        } catch (\Exception $e) {
            throw new PaymentException('Failed to create Razorpay payment link: ' . $e->getMessage());
        }
    }

    /**
     * Get payment link details
     */
    public function getPaymentLink(string $linkId): PaymentResponse
    {
        try {
            $link = $this->getRazorpay()->paymentLink->fetch($linkId);

            return new PaymentResponse(
                success: true,
                transactionId: $link->id,
                status: $link->status,
                data: [
                    'payment_link_id' => $link->id,
                    'short_url' => $link->short_url,
                    'amount' => $link->amount / 100,
                    'currency' => $link->currency,
                    'description' => $link->description,
                    'paid_amount' => $link->paid_amount / 100,
                    'payments_count' => $link->payments_count,
                    'created_at' => $link->created_at
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to fetch Razorpay payment link: ' . $e->getMessage());
        }
    }
}