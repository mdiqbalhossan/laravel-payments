<?php

namespace Mdiqbal\LaravelPayments\Gateways\Mercadopago;

use Exception;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Refund\RefundClient;
use MercadoPago\Client\Subscription\SubscriptionClient;
use MercadoPago\MercadoPagoConfig;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;

class MercadopagoGateway extends AbstractGateway
{
    protected ?PaymentClient $paymentClient = null;
    protected ?PreferenceClient $preferenceClient = null;
    protected ?RefundClient $refundClient = null;
    protected ?SubscriptionClient $subscriptionClient = null;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->initializeSDK();
    }

    public function gatewayName(): string
    {
        return 'mercadopago';
    }

    protected function initializeSDK(): void
    {
        $accessToken = $this->config->getAccessToken();
        $isSandbox = $this->isSandbox();

        if (empty($accessToken)) {
            throw new Exception('Mercado Pago access token is required');
        }

        // Configure SDK
        MercadoPagoConfig::setAccessToken($accessToken);
        if ($isSandbox) {
            MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL);
        }

        // Initialize clients
        $this->paymentClient = new PaymentClient();
        $this->preferenceClient = new PreferenceClient();
        $this->refundClient = new RefundClient();
        $this->subscriptionClient = new SubscriptionClient();
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $this->validateRequest($request);

        try {
            // Create preference for checkout
            $preference = $this->createPreference($request);

            if ($preference && isset($preference['id'])) {
                // Get the appropriate checkout URL based on country
                $checkoutUrl = $this->getCheckoutUrl($preference['id']);

                return PaymentResponse::redirect($checkoutUrl, [
                    'preference_id' => $preference['id'],
                    'init_point' => $preference['init_point'] ?? null,
                    'sandbox_init_point' => $preference['sandbox_init_point'] ?? null,
                    'transaction_id' => $request->getTransactionId(),
                    'message' => 'Redirect to Mercado Pago payment page'
                ]);
            }

            return $this->createErrorResponse('Failed to create payment preference');
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
            $paymentId = $payload['data']['id'] ?? null;

            if (!$paymentId) {
                return $this->createErrorResponse('Payment ID not found in webhook payload');
            }

            // Get payment details from Mercado Pago
            $payment = $this->paymentClient->get($paymentId);

            if (!$payment) {
                return $this->createErrorResponse('Payment not found');
            }

            // Process the webhook event
            $webhookResult = $this->processWebhook($payload);

            if ($webhookResult['success']) {
                return PaymentResponse::success([
                    'transaction_id' => $payment->external_reference ?? $paymentId,
                    'payment_id' => $paymentId,
                    'status' => $this->mapMercadoPagoStatus($payment->status),
                    'payment_method_id' => $payment->payment_method_id,
                    'payment_type_id' => $payment->payment_type_id,
                    'currency' => $payment->currency_id,
                    'amount' => (float) $payment->transaction_amount,
                    'captured_amount' => (float) ($payment->transaction_amount - ($payment->transaction_details->total_paid_amount ?? 0)),
                    'net_amount' => (float) ($payment->transaction_details->net_amount ?? 0),
                    'fee_amount' => (float) ($payment->transaction_details->total_paid_amount ?? 0) - (float) ($payment->transaction_details->net_amount ?? 0),
                    'payment_method' => $payment->payment_method_id ?? null,
                    'card_info' => $this->getCardInfo($payment),
                    'payer_info' => $this->getPayerInfo($payment),
                    'merchant_info' => [
                        'mercado_pago_payment_id' => $paymentId,
                        'preference_id' => $payment->preference_id ?? null,
                        'status_detail' => $payment->status_detail,
                        'operation_type' => $payment->operation_type ?? null,
                        'date_created' => $payment->date_created,
                        'date_approved' => $payment->date_approved ?? null,
                    ],
                    'metadata' => $payment->metadata ?? []
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

    public function verify(string $paymentId): PaymentResponse
    {
        try {
            if (empty($paymentId)) {
                return $this->createErrorResponse('Payment ID is required');
            }

            // Get payment details from Mercado Pago
            $payment = $this->paymentClient->get($paymentId);

            if (!$payment) {
                return $this->createErrorResponse('Payment not found');
            }

            $status = $this->mapMercadoPagoStatus($payment->status);

            return PaymentResponse::success([
                'transaction_id' => $payment->external_reference ?? $paymentId,
                'payment_id' => $paymentId,
                'status' => $status,
                'payment_method_id' => $payment->payment_method_id,
                'payment_type_id' => $payment->payment_type_id,
                'currency' => $payment->currency_id,
                'amount' => (float) $payment->transaction_amount,
                'captured_amount' => (float) ($payment->transaction_amount - ($payment->transaction_details->total_paid_amount ?? 0)),
                'net_amount' => (float) ($payment->transaction_details->net_amount ?? 0),
                'fee_amount' => (float) ($payment->transaction_details->total_paid_amount ?? 0) - (float) ($payment->transaction_details->net_amount ?? 0),
                'payment_method' => $payment->payment_method_id ?? null,
                'card_info' => $this->getCardInfo($payment),
                'payer_info' => $this->getPayerInfo($payment),
                'merchant_info' => [
                    'mercado_pago_payment_id' => $paymentId,
                    'preference_id' => $payment->preference_id ?? null,
                    'status_detail' => $payment->status_detail,
                    'operation_type' => $payment->operation_type ?? null,
                    'date_created' => $payment->date_created,
                    'date_approved' => $payment->date_approved ?? null,
                ],
                'metadata' => $payment->metadata ?? []
            ]);
        } catch (Exception $e) {
            $this->logError('Payment verification failed', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function refund(array $data): PaymentResponse
    {
        try {
            $paymentId = $data['payment_id'];
            $amount = $data['amount'] ?? null;
            $reason = $data['reason'] ?? 'Refund requested';

            if (empty($paymentId)) {
                return $this->createErrorResponse('Payment ID is required');
            }

            // Get payment details first
            $payment = $this->paymentClient->get($paymentId);

            if (!$payment) {
                return $this->createErrorResponse('Payment not found');
            }

            // Process refund
            if ($amount && $amount < $payment->transaction_amount) {
                // Partial refund
                $refundData = [
                    'amount' => $amount,
                    'reason' => $reason
                ];
                $refund = $this->refundClient->createPartial($paymentId, $refundData);
            } else {
                // Full refund
                $refund = $this->refundClient->create($paymentId);
            }

            if ($refund) {
                return PaymentResponse::success([
                    'refund_id' => $refund->id,
                    'payment_id' => $paymentId,
                    'amount_refunded' => (float) ($amount ?? $payment->transaction_amount),
                    'currency' => $payment->currency_id,
                    'reason' => $reason,
                    'status' => 'refunded',
                    'date_created' => $refund->date_created,
                    'merchant_info' => [
                        'mercado_pago_refund_id' => $refund->id,
                        'payment_id' => $paymentId
                    ]
                ]);
            }

            return $this->createErrorResponse('Refund processing failed');
        } catch (Exception $e) {
            $this->logError('Refund processing failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function createCustomer(array $customerData): PaymentResponse
    {
        try {
            $customerClient = new \MercadoPago\Client\Customer\CustomerClient();

            $customer = $customerClient->create([
                'email' => $customerData['email'],
                'first_name' => $customerData['first_name'] ?? null,
                'last_name' => $customerData['last_name'] ?? null,
                'phone' => [
                    'area_code' => $customerData['phone']['area_code'] ?? null,
                    'number' => $customerData['phone']['number'] ?? null
                ],
                'identification' => [
                    'type' => $customerData['identification']['type'] ?? null,
                    'number' => $customerData['identification']['number'] ?? null
                ],
                'default_address' => $customerData['default_address'] ?? null,
                'description' => $customerData['description'] ?? null,
                'metadata' => $customerData['metadata'] ?? []
            ]);

            return PaymentResponse::success([
                'customer_id' => $customer->id,
                'email' => $customer->email,
                'merchant_info' => [
                    'mercado_pago_customer_id' => $customer->id
                ]
            ]);
        } catch (Exception $e) {
            $this->logError('Customer creation failed', [
                'error' => $e->getMessage(),
                'customer_data' => $customerData
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function getTransactionStatus(string $transactionId): PaymentResponse
    {
        return $this->verify($transactionId);
    }

    public function searchTransactions(array $filters = []): PaymentResponse
    {
        try {
            $requestOptions = new RequestOptions();
            $requestOptions->setCustomHeaders([
                'x-paginator-limit' => $filters['limit'] ?? 50
            ]);

            // Search payments by external reference
            if (isset($filters['external_reference'])) {
                $results = $this->paymentClient->search([
                    'external_reference' => $filters['external_reference']
                ], $requestOptions);
            } else {
                $results = $this->paymentClient->search($filters, $requestOptions);
            }

            $transactions = [];
            foreach ($results->results ?? [] as $payment) {
                $transactions[] = [
                    'payment_id' => $payment->id,
                    'external_reference' => $payment->external_reference,
                    'status' => $this->mapMercadoPagoStatus($payment->status),
                    'currency' => $payment->currency_id,
                    'amount' => (float) $payment->transaction_amount,
                    'date_created' => $payment->date_created
                ];
            }

            return PaymentResponse::success([
                'transactions' => $transactions,
                'total' => $results->paging->total ?? 0,
                'limit' => $results->paging->limit ?? 50,
                'offset' => $results->paging->offset ?? 0
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
            'ARS', // Argentine Peso
            'BRL', // Brazilian Real
            'CLP', // Chilean Peso
            'COP', // Colombian Peso
            'MXN', // Mexican Peso
            'PEN', // Peruvian Sol
            'UYU', // Uruguayan Peso
            'USD', // US Dollar
            'EUR', // Euro (limited)
        ];
    }

    public function getPaymentMethodsForCountry(string $countryCode): array
    {
        return $this->config->getPaymentMethods($countryCode) ?? [];
    }

    protected function createPreference(PaymentRequest $request): ?array
    {
        try {
            $items = [];

            // Convert amount to items array (single item for simplicity)
            $items[] = [
                'title' => $request->getDescription() ?? 'Payment',
                'quantity' => 1,
                'currency_id' => $request->getCurrency(),
                'unit_price' => $request->getAmount()
            ];

            // Prepare preference data
            $preferenceData = [
                'items' => $items,
                'external_reference' => $request->getTransactionId(),
                'payer' => $this->buildPayerData($request),
                'back_urls' => [
                    'success' => $request->getRedirectUrl(),
                    'failure' => $request->getRedirectUrl(),
                    'pending' => $request->getRedirectUrl()
                ],
                'auto_return' => 'approved',
                'payment_methods' => [
                    'excluded_payment_methods' => [],
                    'excluded_payment_types' => [],
                    'installments' => null
                ],
                'metadata' => $request->getMetadata() ?? []
            ];

            // Create preference
            $preference = $this->preferenceClient->create($preferenceData);

            return [
                'id' => $preference->id,
                'init_point' => $preference->init_point,
                'sandbox_init_point' => $preference->sandbox_init_point,
            ];
        } catch (Exception $e) {
            $this->logError('Failed to create preference', [
                'error' => $e->getMessage(),
                'request_data' => $request->toArray()
            ]);
            return null;
        }
    }

    protected function buildPayerData(PaymentRequest $request): array
    {
        $payer = [];

        if ($request->getEmail()) {
            $payer['email'] = $request->getEmail();
        }

        if ($request->getCustomer()) {
            $customer = $request->getCustomer();
            if (isset($customer['name'])) {
                $nameParts = explode(' ', $customer['name'], 2);
                $payer['first_name'] = $nameParts[0] ?? null;
                $payer['last_name'] = $nameParts[1] ?? null;
            }

            if (isset($customer['phone'])) {
                $payer['phone'] = [
                    'area_code' => '',
                    'number' => $customer['phone']
                ];
            }

            if (isset($customer['address'])) {
                $payer['address'] = [
                    'zip_code' => $customer['postal_code'] ?? '',
                    'street_name' => $customer['address'] ?? '',
                    'street_number' => $customer['address_number'] ?? ''
                ];
            }

            if (isset($customer['identification'])) {
                $payer['identification'] = $customer['identification'];
            }
        }

        return $payer;
    }

    protected function getCheckoutUrl(string $preferenceId): string
    {
        $country = $this->config->getCountry() ?? 'MX';

        // Base checkout URLs for different countries
        $checkoutUrls = [
            'AR' => 'https://www.mercadopago.com.ar/checkout',
            'BR' => 'https://www.mercadopago.com.br/checkout',
            'CL' => 'https://www.mercadopago.cl/checkout',
            'CO' => 'https://www.mercadopago.com.co/checkout',
            'MX' => 'https://www.mercadopago.com.mx/checkout',
            'PE' => 'https://www.mercadopago.com.pe/checkout',
            'UY' => 'https://www.mercadopago.com.uy/checkout',
        ];

        $baseUrl = $checkoutUrls[$country] ?? $checkoutUrls['MX'];

        return $this->isSandbox()
            ? "https://sandbox.mercadopago.com.ar/checkout?preference_id={$preferenceId}"
            : "{$baseUrl}?preference_id={$preferenceId}";
    }

    protected function processWebhook(array $payload): array
    {
        try {
            $type = $payload['type'] ?? '';
            $data = $payload['data'] ?? [];

            // Verify webhook signature if configured
            if ($this->config->getWebhookSecret()) {
                $signature = $_SERVER['HTTP_X_MELI_SIGNATURE'] ?? '';
                if (!$this->verifyWebhookSignature($signature, $payload)) {
                    return ['success' => false, 'error' => 'Invalid webhook signature'];
                }
            }

            switch ($type) {
                case 'payment':
                    return $this->processPaymentWebhook($data);

                case 'chargeback':
                    return $this->processChargebackWebhook($data);

                case 'merchant_order':
                    return $this->processMerchantOrderWebhook($data);

                default:
                    return $this->createErrorResponse('Unsupported webhook type: ' . $type);
            }
        } catch (Exception $e) {
            $this->logError('Webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    protected function processPaymentWebhook(array $data): array
    {
        try {
            $paymentId = $data['id'] ?? null;

            if (!$paymentId) {
                return $this->createErrorResponse('Payment ID not found in webhook');
            }

            // Get payment details
            $payment = $this->paymentClient->get($paymentId);

            if (!$payment) {
                return $this->createErrorResponse('Payment not found');
            }

            // Map Mercado Pago status to standard status
            $status = $this->mapMercadoPagoStatus($payment->status);

            return [
                'success' => true,
                'event_type' => 'payment.' . $payment->status,
                'transaction_id' => $payment->external_reference ?? $paymentId,
                'payment_id' => $paymentId,
                'status' => $status,
                'amount' => (float) $payment->transaction_amount,
                'currency' => $payment->currency_id,
                'payment_method' => $payment->payment_method_id ?? null,
                'date_created' => $payment->date_created,
                'date_approved' => $payment->date_approved ?? null,
                'merchant_info' => [
                    'mercado_pago_payment_id' => $paymentId,
                    'status_detail' => $payment->status_detail,
                    'operation_type' => $payment->operation_type ?? null,
                    'payment_method_id' => $payment->payment_method_id,
                    'payment_type_id' => $payment->payment_type_id,
                ]
            ];
        } catch (Exception $e) {
            return $this->createErrorResponse($e->getMessage());
        }
    }

    protected function processChargebackWebhook(array $data): array
    {
        return [
            'success' => true,
            'event_type' => 'chargeback.created',
            'chargeback_id' => $data['id'] ?? null,
            'message' => 'Chargeback notification received'
        ];
    }

    protected function processMerchantOrderWebhook(array $data): array
    {
        return [
            'success' => true,
            'event_type' => 'merchant_order.created',
            'order_id' => $data['id'] ?? null,
            'message' => 'Merchant order notification received'
        ];
    }

    protected function verifyWebhookSignature(string $signature, array $payload): bool
    {
        if (empty($signature) || empty($payload)) {
            return false;
        }

        // Extract the signature parts
        $parts = explode(',', $signature);
        $signatureData = [];

        foreach ($parts as $part) {
            [$key, $value] = explode('=', $part, 2);
            $signatureData[$key] = $value;
        }

        if (!isset($signatureData['ts'], $signatureData['v1'])) {
            return false;
        }

        // Verify timestamp (prevent replay attacks - webhook must be within 5 minutes)
        $timestamp = (int) $signatureData['ts'];
        $currentTime = time();
        if (abs($currentTime - $timestamp) > 300) {
            return false;
        }

        // Generate expected signature
        $secret = $this->config->getWebhookSecret();
        $payloadId = $payload['id'] ?? '';
        $expectedSignature = hash_hmac('sha256', $payloadId . $timestamp, $secret);

        return hash_equals($expectedSignature, $signatureData['v1']);
    }

    protected function getCardInfo($payment): ?array
    {
        if (!isset($payment->card)) {
            return null;
        }

        return [
            'id' => $payment->card->id ?? null,
            'last_four_digits' => $payment->card->last_four_digits ?? null,
            'expiration_month' => $payment->card->expiration_month ?? null,
            'expiration_year' => $payment->card->expiration_year ?? null,
            'cardholder' => [
                'name' => $payment->card->cardholder->name ?? null,
                'identification' => $payment->card->cardholder->identification ?? null
            ]
        ];
    }

    protected function getPayerInfo($payment): array
    {
        return [
            'id' => $payment->payer->id ?? null,
            'email' => $payment->payer->email ?? null,
            'first_name' => $payment->payer->first_name ?? null,
            'last_name' => $payment->payer->last_name ?? null,
            'phone' => [
                'area_code' => $payment->payer->phone->area_code ?? null,
                'number' => $payment->payer->phone->number ?? null
            ],
            'identification' => [
                'type' => $payment->payer->identification->type ?? null,
                'number' => $payment->payer->identification->number ?? null
            ]
        ];
    }

    protected function mapMercadoPagoStatus(string $status): string
    {
        $statusMap = [
            'pending' => 'pending',
            'approved' => 'completed',
            'authorized' => 'authorized',
            'in_process' => 'processing',
            'in_mediation' => 'disputed',
            'rejected' => 'failed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'charged_back' => 'reversed',
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
            throw new Exception("Currency {$request->getCurrency()} is not supported by Mercado Pago");
        }
    }

    protected function getEndpoint(string $path): string
    {
        $baseUrl = $this->isSandbox()
            ? 'https://api.mercadopago.com'
            : 'https://api.mercadopago.com';

        return $baseUrl . '/' . ltrim($path, '/');
    }

    protected function createErrorResponse(string $message): PaymentResponse
    {
        return PaymentResponse::error($message, 400, [
            'gateway' => 'mercadopago',
            'timestamp' => time()
        ]);
    }

    public function parseCallback($request): array
    {
        return [
            'type' => $request->input('type'),
            'data' => $request->input('data'),
            'id' => $request->input('id'),
            'topic' => $request->input('topic'),
            'resource' => $request->input('resource'),
            'raw_body' => $request->getContent(),
            'headers' => $request->headers->all()
        ];
    }

    public function getGatewayConfig(): array
    {
        return [
            'access_token' => $this->config->getAccessToken(),
            'test_mode' => $this->isSandbox(),
            'country' => $this->config->getCountry(),
            'webhook_secret' => $this->config->getWebhookSecret()
        ];
    }

    public function createSubscription(array $subscriptionData): PaymentResponse
    {
        try {
            $subscription = $this->subscriptionClient->create([
                'preapproval_plan_id' => $subscriptionData['plan_id'],
                'payer_email' => $subscriptionData['payer_email'],
                'back_url' => $subscriptionData['back_url'] ?? null,
                'reason' => $subscriptionData['reason'] ?? 'Subscription',
                'external_reference' => $subscriptionData['external_reference'] ?? null,
                'auto_recurring' => $subscriptionData['auto_recurring'] ?? []
            ]);

            return PaymentResponse::success([
                'subscription_id' => $subscription->id,
                'status' => 'pending',
                'init_point' => $subscription->init_point,
                'sandbox_init_point' => $subscription->sandbox_init_point,
                'merchant_info' => [
                    'mercado_pago_subscription_id' => $subscription->id
                ]
            ]);
        } catch (Exception $e) {
            $this->logError('Subscription creation failed', [
                'error' => $e->getMessage(),
                'subscription_data' => $subscriptionData
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function cancelSubscription(string $subscriptionId): PaymentResponse
    {
        try {
            $this->subscriptionClient->update($subscriptionId, ['status' => 'cancelled']);

            return PaymentResponse::success([
                'subscription_id' => $subscriptionId,
                'status' => 'cancelled'
            ]);
        } catch (Exception $e) {
            return $this->createErrorResponse($e->getMessage());
        }
    }
}