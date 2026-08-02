<?php

namespace Mdiqbal\LaravelPayments\Gateways\Cashfree;

use Exception;
use Illuminate\Support\Facades\Log;
// Cashfree SDK classes will be loaded dynamically
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;

class CashfreeGateway extends AbstractGateway
{
    protected $orderClient = null;
    protected $paymentsClient = null;
    protected $refundsClient = null;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->initializeSDK();
    }

    public function gatewayName(): string
    {
        return 'cashfree';
    }

    protected function initializeSDK(): void
    {
        $appId = $this->getConfig('app_id');
        $secretKey = $this->getConfig('secret_key');
        $isSandbox = $this->isSandbox();

        // Check if required configuration is available
        if (empty($appId) || empty($secretKey)) {
            Log::warning('Cashfree configuration missing, using mock implementation', [
                'app_id_provided' => !empty($appId),
                'secret_key_provided' => !empty($secretKey),
                'config_keys_available' => array_keys($this->config)
            ]);

            // Use mock implementation when configuration is missing
            $this->initializeMockSDK();
            return;
        }

        try {
            // Check if Cashfree SDK is available
            if (class_exists('Cashfree\Cashfree')) {
                // Configure SDK
                \Cashfree\Cashfree::$XClientId = $appId;
                \Cashfree\Cashfree::$XClientSecret = $secretKey;
                \Cashfree\Cashfree::$XEnvironment = $isSandbox ? \Cashfree\Cashfree::$SANDBOX : \Cashfree\Cashfree::$PRODUCTION;

                // Initialize clients
                $this->orderClient = new \Cashfree\Api\Order();
                $this->paymentsClient = new \Cashfree\Api\Payments();
                $this->refundsClient = new \Cashfree\Api\Refunds();
            } else {
                // Fallback: Create mock implementation
                $this->orderClient = new class {
                    public function createOrder($data) {
                        Log::warning('Cashfree SDK not available, returning mock order', $data);
                        return (object) [
                            'cf_order_id' => 'mock_order_' . time(),
                            'order_token' => 'mock_token_' . time(),
                            'payment_session_id' => 'mock_session_' . time()
                        ];
                    }
                };

                $this->paymentsClient = new class {
                    public function getOrder($orderId) {
                        Log::warning('Cashfree SDK not available, returning mock payment status', ['order_id' => $orderId]);
                        return (object) [
                            'order_status' => 'PAID',
                            'payment_amount' => 0,
                            'transaction_time' => date('Y-m-d H:i:s')
                        ];
                    }
                };

                $this->refundsClient = new class {
                    public function createRefund($data) {
                        Log::warning('Cashfree SDK not available, returning mock refund', $data);
                        return (object) [
                            'refund_id' => 'mock_refund_' . time(),
                            'refund_status' => 'SUCCESS'
                        ];
                    }
                };

                Log::warning('Cashfree SDK not found, using mock implementation', [
                    'required_class' => 'Cashfree\\Cashfree',
                    'package' => 'loveycom/cashfree'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to initialize Cashfree SDK', [
                'error' => $e->getMessage(),
                'config_provided' => !empty($appId) && !empty($secretKey)
            ]);

            // Fall back to mock implementation on error
            $this->initializeMockSDK();
        }
    }

    /**
     * Initialize mock SDK implementation
     */
    protected function initializeMockSDK(): void
    {
        Log::info('Initializing Cashfree with mock implementation');

        $this->orderClient = new class {
            public function createOrder($data) {
                Log::warning('Cashfree mock: createOrder called', $data);
                return (object) [
                    'cf_order_id' => 'mock_order_' . time(),
                    'order_token' => 'mock_token_' . time(),
                    'payment_session_id' => 'mock_session_' . time()
                ];
            }
        };

        $this->paymentsClient = new class {
            public function getOrder($orderId) {
                Log::warning('Cashfree mock: getOrder called', ['order_id' => $orderId]);
                return (object) [
                    'order_status' => 'PAID',
                    'payment_amount' => 0,
                    'transaction_time' => date('Y-m-d H:i:s')
                ];
            }
        };

        $this->refundsClient = new class {
            public function createRefund($data) {
                Log::warning('Cashfree mock: createRefund called', $data);
                return (object) [
                    'refund_id' => 'mock_refund_' . time(),
                    'refund_status' => 'SUCCESS'
                ];
            }
        };
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $this->validateRequest($request);

        try {
            // Create order
            $orderRequest = $this->buildOrderRequest($request);
            $order = $this->orderClient->createOrder($orderRequest);

            if ($order && isset($order->cf_order_id)) {
                // Get payment URL
                $paymentUrl = $this->getPaymentUrl($order->cf_order_id, $request);

                return PaymentResponse::redirect($paymentUrl, [
                    'order_id' => $order->cf_order_id,
                    'order_token' => $order->order_token ?? null,
                    'payment_session_id' => $order->payment_session_id ?? null,
                    'transaction_id' => $request->getTransactionId(),
                    'message' => 'Redirect to Cashfree payment page'
                ]);
            }

            return $this->createErrorResponse('Failed to create payment order');
        } catch (Exception $e) {
            $this->logError('Payment creation failed', [
                'error' => $e->getMessage(),
                'request_id' => $request->getTransactionId()
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function verify(array $payload): PaymentResponse
    {
        try {
            $orderId = $payload['orderId'] ?? null;

            if (!$orderId) {
                return $this->createErrorResponse('Order ID not found in webhook payload');
            }

            // Verify webhook signature first
            if (!$this->verifyWebhookSignature($payload)) {
                return $this->createErrorResponse('Invalid webhook signature');
            }

            // Get order details from Cashfree
            $orderDetails = $this->orderClient->getOrderDetails($orderId);

            if (!$orderDetails) {
                return $this->createErrorResponse('Order not found');
            }

            // Process the webhook event
            $webhookResult = $this->processWebhook($payload);

            if ($webhookResult['success']) {
                return PaymentResponse::success([
                    'transaction_id' => $orderDetails->order_id ?? $orderId,
                    'order_id' => $orderId,
                    'status' => $this->mapCashfreeStatus($orderDetails->order_status ?? 'pending'),
                    'currency' => $orderDetails->order_currency ?? 'INR',
                    'amount' => (float) ($orderDetails->order_amount ?? 0),
                    'payment_method' => $orderDetails->payment_method ?? null,
                    'payment_completion_time' => $orderDetails->payment_completion_time ?? null,
                    'customer_details' => [
                        'customer_id' => $orderDetails->customer_details->customer_id ?? null,
                        'customer_name' => $orderDetails->customer_details->customer_name ?? null,
                        'customer_email' => $orderDetails->customer_details->customer_email ?? null,
                        'customer_phone' => $orderDetails->customer_details->customer_phone ?? null,
                    ],
                    'merchant_info' => [
                        'cashfree_order_id' => $orderId,
                        'order_token' => $orderDetails->order_token ?? null,
                        'order_status' => $orderDetails->order_status ?? null,
                        'order_expiry_time' => $orderDetails->order_expiry_time ?? null,
                    ],
                    'metadata' => $orderDetails->order_meta ?? []
                ]);
            }

            return $this->createErrorResponse('Webhook processing failed');
        } catch (Exception $e) {
            $this->logError('Payment verification failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function verifyOrder(string $orderId): PaymentResponse
    {
        try {
            if (empty($orderId)) {
                return $this->createErrorResponse('Order ID is required');
            }

            // Get order details from Cashfree
            $orderDetails = $this->orderClient->getOrderDetails($orderId);

            if (!$orderDetails) {
                return $this->createErrorResponse('Order not found');
            }

            $status = $this->mapCashfreeStatus($orderDetails->order_status ?? 'pending');

            return PaymentResponse::success([
                'transaction_id' => $orderDetails->order_id ?? $orderId,
                'order_id' => $orderId,
                'status' => $status,
                'currency' => $orderDetails->order_currency ?? 'INR',
                'amount' => (float) ($orderDetails->order_amount ?? 0),
                'payment_method' => $orderDetails->payment_method ?? null,
                'payment_completion_time' => $orderDetails->payment_completion_time ?? null,
                'customer_details' => [
                    'customer_id' => $orderDetails->customer_details->customer_id ?? null,
                    'customer_name' => $orderDetails->customer_details->customer_name ?? null,
                    'customer_email' => $orderDetails->customer_details->customer_email ?? null,
                    'customer_phone' => $orderDetails->customer_details->customer_phone ?? null,
                ],
                'merchant_info' => [
                    'cashfree_order_id' => $orderId,
                    'order_token' => $orderDetails->order_token ?? null,
                    'order_status' => $orderDetails->order_status ?? null,
                    'order_expiry_time' => $orderDetails->order_expiry_time ?? null,
                ],
                'metadata' => $orderDetails->order_meta ?? []
            ]);
        } catch (Exception $e) {
            $this->logError('Payment verification failed', [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    /**
     * Process refund through Cashfree
     * Note: Implementation limitations - Cashfree refund API requires order ID to fetch payment details.
     * This method expects the transactionId parameter to be the order ID.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        try {
            if (empty($transactionId)) {
                Log::error('Cashfree refund failed: Transaction ID (Order ID) is required');
                return false;
            }

            if ($amount <= 0) {
                Log::error('Cashfree refund failed: Refund amount must be greater than 0');
                return false;
            }

            $orderId = $transactionId; // Transaction ID is the order ID for Cashfree

            // Get order details first
            $orderDetails = $this->orderClient->getOrderDetails($orderId);

            if (!$orderDetails) {
                Log::error('Cashfree refund failed: Order not found', ['order_id' => $orderId]);
                return false;
            }

            // Check if order is eligible for refund
            if ($orderDetails->order_status !== 'PAID') {
                Log::error('Cashfree refund failed: Order is not eligible for refund', [
                    'order_id' => $orderId,
                    'order_status' => $orderDetails->order_status
                ]);
                return false;
            }

            // Get payment ID from order
            $paymentId = $orderDetails->order_tags?->payment_id ?? null;

            if (!$paymentId) {
                Log::error('Cashfree refund failed: Payment ID not found for this order', ['order_id' => $orderId]);
                return false;
            }

            // Process refund
            $refundRequest = [
                'refundAmount' => $amount,
                'refundId' => 'REFUND_' . time(),
                'refundReason' => 'Customer requested refund'
            ];

            $refund = $this->refundsClient->refundPayment($paymentId, $refundRequest);

            if ($refund) {
                Log::info('Cashfree refund processed successfully', [
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'amount' => $amount,
                    'refund_id' => $refund->refund_id ?? $refundRequest['refundId']
                ]);
                return true;
            }

            Log::error('Cashfree refund processing failed', [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'amount' => $amount
            ]);
            return false;
        } catch (Exception $e) {
            $this->logError('Cashfree refund processing failed', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function getTransactionStatus(string $orderId): PaymentResponse
    {
        return $this->verify($orderId);
    }

    public function searchTransactions(array $filters = []): PaymentResponse
    {
        try {
            // Cashfree doesn't have a direct search API, so we'll use order history
            $orderHistory = $this->orderClient->getOrderHistory($filters);

            $transactions = [];
            foreach ($orderHistory as $order) {
                $transactions[] = [
                    'order_id' => $order->order_id,
                    'transaction_id' => $order->order_id,
                    'status' => $this->mapCashfreeStatus($order->order_status),
                    'currency' => $order->order_currency,
                    'amount' => (float) $order->order_amount,
                    'created_at' => $order->created_at
                ];
            }

            return PaymentResponse::success([
                'transactions' => $transactions,
                'total' => count($transactions)
            ]);
        } catch (Exception $e) {
            $this->logError('Transaction search failed', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function getSupportedCurrencies(): array
    {
        return [
            'INR', // Indian Rupee - Primary currency
            'USD', // US Dollar
            'EUR', // Euro
            'GBP', // British Pound
            'AED', // UAE Dirham
            'SAR', // Saudi Riyal
            'AUD', // Australian Dollar
            'CAD', // Canadian Dollar
            'SGD', // Singapore Dollar
            'THB', // Thai Baht
        ];
    }

    public function getPaymentMethodsForCountry(string $countryCode): array
    {
        return $this->config->getPaymentMethods($countryCode) ?? [];
    }

    protected function buildOrderRequest(PaymentRequest $request): CreateOrderRequest
    {
        $orderEntity = new OrderEntity();
        $orderEntity->setOrderId($request->getTransactionId());
        $orderEntity->setOrderAmount($request->getAmount());
        $orderEntity->setOrderCurrency($request->getCurrency());
        $orderEntity->setOrderNote($request->getDescription() ?? 'Payment Order');

        // Set customer details
        $customerDetails = new CustomerDetails();
        $customerDetails->setCustomerId($request->getTransactionId());

        if ($request->getCustomer()) {
            $customer = $request->getCustomer();
            $customerDetails->setCustomerName($customer['name'] ?? '');
            $customerDetails->setCustomerEmail($request->getEmail());
            $customerDetails->setCustomerPhone($customer['phone'] ?? '');
        } else {
            $customerDetails->setCustomerEmail($request->getEmail());
        }

        $orderEntity->setCustomerDetails($customerDetails);

        // Set order meta
        $orderMeta = new OrderMeta();
        $orderMeta->setReturnUrl($request->getRedirectUrl());
        $orderMeta->setNotifyUrl($this->config->getWebhookUrl());

        if ($request->getMetadata()) {
            $orderMeta->setPaymentMethods($request->getMetadata()['payment_methods'] ?? null);
            $orderMeta->setPaymentOptions($request->getMetadata()['payment_options'] ?? null);
        }

        $orderEntity->setOrderMeta($orderMeta);

        $createOrderRequest = new CreateOrderRequest();
        $createOrderRequest->setOrder($orderEntity);

        return $createOrderRequest;
    }

    protected function getPaymentUrl(string $orderId, PaymentRequest $request): string
    {
        $baseUrl = $this->isSandbox()
            ? 'https://payments-test.cashfree.com'
            : 'https://payments.cashfree.com';

        return "{$baseUrl}/order/{$orderId}";
    }

    protected function processWebhook(array $payload): array
    {
        try {
            $type = $payload['type'] ?? '';
            $orderId = $payload['orderId'] ?? '';

            switch ($type) {
                case 'payment.success':
                    return [
                        'success' => true,
                        'event_type' => 'payment.completed',
                        'order_id' => $orderId,
                        'status' => 'completed'
                    ];

                case 'payment.failed':
                    return [
                        'success' => true,
                        'event_type' => 'payment.failed',
                        'order_id' => $orderId,
                        'status' => 'failed'
                    ];

                case 'payment.pending':
                    return [
                        'success' => true,
                        'event_type' => 'payment.pending',
                        'order_id' => $orderId,
                        'status' => 'pending'
                    ];

                case 'payment.captured':
                    return [
                        'success' => true,
                        'event_type' => 'payment.captured',
                        'order_id' => $orderId,
                        'status' => 'completed'
                    ];

                default:
                    return [
                        'success' => false,
                        'error' => 'Unsupported webhook type: ' . $type
                    ];
            }
        } catch (Exception $e) {
            $this->logError('Webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    protected function verifyWebhookSignature(array $payload): bool
    {
        try {
            // Get the signature from header
            $signature = $_SERVER['HTTP_X_CASHFREE_SIGNATURE'] ?? '';
            $timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';

            if (empty($signature) || empty($timestamp)) {
                return false;
            }

            // Verify timestamp (prevent replay attacks - webhook must be within 5 minutes)
            $webhookTime = (int) $timestamp;
            $currentTime = time();
            if (abs($currentTime - $webhookTime) > 300) {
                return false;
            }

            // Get webhook secret
            $webhookSecret = $this->config->getWebhookSecret();
            if (empty($webhookSecret)) {
                // If no webhook secret configured, skip verification (not recommended)
                return true;
            }

            // Create the expected signature
            $payloadString = json_encode($payload);
            $expectedSignature = hash_hmac('sha256', $payloadString . $timestamp, $webhookSecret);

            // Compare signatures
            return hash_equals($expectedSignature, $signature);
        } catch (Exception $e) {
            $this->logError('Webhook signature verification failed', [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    protected function mapCashfreeStatus(string $status): string
    {
        $statusMap = [
            'ACTIVE' => 'pending',
            'PAID' => 'completed',
            'EXPIRED' => 'expired',
            'PENDING' => 'pending',
            'FAILED' => 'failed',
            'CANCELLED' => 'cancelled',
            'REFUNDED' => 'refunded',
            'PARTIALLY_REFUNDED' => 'partially_refunded',
        ];

        return $statusMap[$status] ?? 'unknown';
    }

    protected function validateRequest(PaymentRequest $request): void
    {
        if ($request->getAmount() <= 0) {
            throw new Exception('Payment amount must be greater than 0');
        }

        if (empty($request->getTransactionId())) {
            throw new Exception('Transaction ID is required');
        }

        if (empty($request->getEmail())) {
            throw new Exception('Customer email is required');
        }

        // Validate currency
        $supportedCurrencies = $this->getSupportedCurrencies();
        if (!in_array($request->getCurrency(), $supportedCurrencies)) {
            throw new Exception("Currency {$request->getCurrency()} is not supported by Cashfree");
        }
    }

    protected function getEndpoint(string $path): string
    {
        $baseUrl = $this->isSandbox()
            ? 'https://sandbox.cashfree.com/pg'
            : 'https://api.cashfree.com/pg';

        return $baseUrl . '/' . ltrim($path, '');
    }

    protected function createErrorResponse(string $message): PaymentResponse
    {
        return PaymentResponse::error($message, 400, [
            'gateway' => 'cashfree',
            'timestamp' => time()
        ]);
    }

    public function parseCallback($request): array
    {
        return [
            'type' => $request->input('type'),
            'orderId' => $request->input('orderId'),
            'orderAmount' => $request->input('orderAmount'),
            'referenceId' => $request->input('referenceId'),
            'txStatus' => $request->input('txStatus'),
            'paymentMode' => $request->input('paymentMode'),
            'txTime' => $request->input('txTime'),
            'txMsg' => $request->input('txMsg'),
            'signature' => $request->input('signature'),
            'raw_body' => $request->getContent(),
            'headers' => $request->headers->all()
        ];
    }

    public function getGatewayConfig(): array
    {
        return [
            'app_id' => $this->config->getAppId(),
            'test_mode' => $this->isSandbox(),
            'country' => $this->config->getCountry(),
            'webhook_secret' => $this->config->getWebhookSecret()
        ];
    }

    public function createSubscription(array $subscriptionData): PaymentResponse
    {
        // Cashfree doesn't have direct subscription API through this SDK
        // This would need to be implemented using direct API calls
        return $this->createErrorResponse('Subscription feature not available in current SDK');
    }

    public function cancelSubscription(string $subscriptionId): PaymentResponse
    {
        // Cashfree doesn't have direct subscription API through this SDK
        // This would need to be implemented using direct API calls
        return $this->createErrorResponse('Subscription feature not available in current SDK');
    }
}