<?php

namespace Mdiqbal\LaravelPayments\Gateways\Mollie;

use Mdiqbal\LaravelPayments\AbstractGateway;
use Mdiqbal\LaravelPayments\DTOs\PaymentRequest;
use Mdiqbal\LaravelPayments\DTOs\PaymentResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Mollie\Laravel\Facades\Mollie;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use Mollie\Api\Resources\Customer;
use Mollie\Api\Resources\Subscription;
use Mollie\Api\Resources\Mandate;
use Mollie\Api\Types\PaymentMethod;

class MollieGateway extends AbstractGateway
{
    /**
     * Mollie client
     */
    protected $mollie;

    /**
     * Gateway configuration
     */
    protected $config;

    /**
     * Supported currencies
     */
    protected array $supportedCurrencies = [
        'EUR', 'USD', 'GBP', 'AUD', 'CAD', 'CHF', 'SEK', 'NOK', 'DKK',
        'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'HRK', 'RUB', 'TRY',
        'JPY', 'HKD', 'SGD', 'NZD', 'MXN', 'BRL', 'INR', 'MYR',
        'THB', 'IDR', 'PHP', 'VND', 'ZAR', 'NGN', 'GHS', 'KES',
        'UGX', 'TZS', 'RWF', 'BIF', 'XAF', 'XOF', 'XPF', 'ALL'
    ];

    /**
     * Constructor
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'api_key' => config('services.mollie.api_key'),
            'test_mode' => config('services.mollie.test_mode', true),
            'redirect_url' => config('services.mollie.redirect_url'),
            'webhook_url' => config('services.mollie.webhook_url'),
        ], $config);

        $this->initializeMollie();
    }

    /**
     * Initialize Mollie SDK
     */
    protected function initializeMollie()
    {
        $this->mollie = Mollie::api();

        if ($this->config['api_key']) {
            $this->mollie->setApiKey($this->config['api_key']);
        }
    }

    /**
     * Get gateway name
     */
    public function getGatewayName(): string
    {
        return 'mollie';
    }

    /**
     * Process payment
     */
    public function pay(array $data): array
    {
        try {
            $paymentRequest = new PaymentRequest($data);

            // Validate currency
            if (!$this->supportsCurrency($paymentRequest->currency)) {
                throw new \InvalidArgumentException("Currency {$paymentRequest->currency} is not supported by Mollie");
            }

            // Create customer if customer data provided
            $customerId = null;
            if (isset($paymentRequest->customer['email'])) {
                $customer = $this->createCustomer([
                    'name' => $paymentRequest->customer['name'] ?? null,
                    'email' => $paymentRequest->customer['email'],
                    'metadata' => $paymentRequest->metadata ?? []
                ]);

                if ($customer['success']) {
                    $customerId = $customer['customer']['id'];
                }
            }

            // Prepare payment data
            $paymentData = [
                'amount' => [
                    'currency' => $paymentRequest->currency,
                    'value' => number_format($paymentRequest->amount, 2, '.', '')
                ],
                'description' => $paymentRequest->description ?? 'Payment',
                'redirectUrl' => $paymentRequest->redirect_url ?? $this->config['redirect_url'],
                'webhookUrl' => $this->config['webhook_url'],
                'metadata' => array_merge(
                    $paymentRequest->metadata ?? [],
                    ['transaction_id' => $paymentRequest->transaction_id]
                ),
                'sequenceType' => $data['sequence_type'] ?? 'oneoff',
                'method' => $this->getPaymentMethods($data)
            ];

            // Add customer if created
            if ($customerId) {
                $paymentData['customerId'] = $customerId;
            }

            // Add due date for delayed payments
            if (isset($data['due_date'])) {
                $paymentData['dueDate'] = $data['due_date'];
            }

            // Add billing address if provided
            if (isset($paymentRequest->customer['address'])) {
                $paymentData['billingAddress'] = [
                    'streetAndNumber' => $paymentRequest->customer['address'],
                    'city' => $paymentRequest->customer['city'] ?? '',
                    'region' => $paymentRequest->customer['state'] ?? '',
                    'postalCode' => $paymentRequest->customer['postal_code'] ?? '',
                    'country' => $paymentRequest->customer['country'] ?? '',
                    'givenName' => $paymentRequest->customer['first_name'] ?? '',
                    'familyName' => $paymentRequest->customer['last_name'] ?? '',
                    'email' => $paymentRequest->email,
                    'phone' => $paymentRequest->customer['phone'] ?? ''
                ];
            }

            // Create payment
            $molliePayment = $this->mollie->payments->create($paymentData);

            return [
                'success' => true,
                'transaction_id' => $paymentRequest->transaction_id,
                'gateway_transaction_id' => $molliePayment->id,
                'payment_url' => $molliePayment->getCheckoutUrl(),
                'redirect_url' => $molliePayment->getCheckoutUrl(),
                'status' => $molliePayment->status,
                'amount' => (float) $molliePayment->amount->value,
                'currency' => $molliePayment->amount->currency,
                'method' => $molliePayment->method,
                'created_at' => $molliePayment->createdAt,
                'due_date' => $molliePayment->dueDate,
                'message' => 'Payment created successfully',
                'data' => $molliePayment
            ];

        } catch (\Exception $e) {
            Log::error('Mollie payment error: ' . $e->getMessage(), [
                'transaction_id' => $paymentRequest->transaction_id ?? null,
                'amount' => $paymentRequest->amount ?? null,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'PAYMENT_FAILED'
                ]
            ];
        }
    }

    /**
     * Verify payment
     */
    public function verify(string $transactionId): array
    {
        try {
            // Find payment by transaction_id in metadata
            $payments = $this->mollie->payments->page([
                'metadata' => ['transaction_id' => $transactionId]
            ]);

            if ($payments->count() === 0) {
                // Try to find by ID directly
                $molliePayment = $this->mollie->payments->get($transactionId);
            } else {
                $molliePayment = $payments[0];
            }

            $amount = (float) $molliePayment->amount->value;
            $currency = $molliePayment->amount->currency;
            $status = $molliePayment->status;

            $response = [
                'success' => true,
                'status' => $status,
                'transaction_id' => $molliePayment->metadata->transaction_id ?? $molliePayment->id,
                'gateway_transaction_id' => $molliePayment->id,
                'amount' => $amount,
                'currency' => $currency,
                'payment_method' => $molliePayment->method,
                'details' => $molliePayment->details,
                'created_at' => $molliePayment->createdAt,
                'paid_at' => $molliePayment->paidAt,
                'canceled_at' => $molliePayment->canceledAt,
                'expired_at' => $molliePayment->expiredAt,
                'sequence_type' => $molliePayment->sequenceType,
                'customer_id' => $molliePayment->customerId,
                'mandate_id' => $molliePayment->mandateId,
                'profile_id' => $molliePayment->profileId,
                'settlement_id' => $molliePayment->settlementId,
                'amount_refunded' => $molliePayment->amountRefunded ? (float) $molliePayment->amountRefunded->value : 0,
                'amount_remaining' => $molliePayment->amountRemaining ? (float) $molliePayment->amountRemaining->value : 0,
                'description' => $molliePayment->description,
                'redirect_url' => $molliePayment->redirectUrl,
                'webhook_url' => $molliePayment->webhookUrl,
                'country_code' => $molliePayment->countryCode,
                'locale' => $molliePayment->locale,
                'metadata' => $molliePayment->metadata,
                'application_fee' => $molliePayment->applicationFee,
                'custom_parameters' => $molliePayment->customParameters,
                'is_cancelable' => $molliePayment->isCancelable,
                'expires_at' => $molliePayment->expiresAt,
                'due_date' => $molliePayment->dueDate,
                'message' => 'Payment retrieved successfully',
                'data' => $molliePayment
            ];

            // Add refund information if available
            if ($molliePayment->_links->refunds) {
                $refunds = $this->mollie->refunds->listForPayment($molliePayment->id);
                $response['refunds'] = $refunds->toArray();
            }

            return $response;

        } catch (\Exception $e) {
            Log::error('Mollie verification error: ' . $e->getMessage(), [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'VERIFICATION_FAILED'
                ]
            ];
        }
    }

    /**
     * Process refund
     */
    public function refund(array $data): array
    {
        try {
            $refundData = [
                'amount' => [
                    'currency' => $data['currency'],
                    'value' => number_format($data['amount'], 2, '.', '')
                ],
                'description' => $data['reason'] ?? 'Refund requested'
            ];

            // Create refund
            $refund = $this->mollie->refunds->createFor($data['payment_id'], $refundData);

            return [
                'success' => true,
                'refund_id' => $refund->id,
                'payment_id' => $data['payment_id'],
                'amount' => (float) $refund->amount->value,
                'currency' => $refund->amount->currency,
                'status' => $refund->status,
                'description' => $refund->description,
                'settlement_amount' => $refund->settlementAmount ? (float) $refund->settlementAmount->value : null,
                'created_at' => $refund->createdAt,
                'message' => 'Refund processed successfully',
                'data' => $refund
            ];

        } catch (\Exception $e) {
            Log::error('Mollie refund error: ' . $e->getMessage(), [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'REFUND_FAILED'
                ]
            ];
        }
    }

    /**
     * Process webhook
     */
    public function processWebhook(array $payload): array
    {
        try {
            if (!isset($payload['id'])) {
                throw new \Exception('Invalid webhook payload: missing payment ID');
            }

            // Retrieve payment details
            $molliePayment = $this->mollie->payments->get($payload['id'], [
                'embed' => ['refunds']
            ]);

            return [
                'success' => true,
                'event_type' => 'payment.' . $molliePayment->status,
                'transaction_id' => $molliePayment->metadata->transaction_id ?? $molliePayment->id,
                'gateway_transaction_id' => $molliePayment->id,
                'status' => $molliePayment->status,
                'amount' => (float) $molliePayment->amount->value,
                'currency' => $molliePayment->amount->currency,
                'payment_method' => $molliePayment->method,
                'refunds' => $molliePayment->_links->refunds ? $molliePayment->refunds()->toArray() : [],
                'metadata' => $molliePayment->metadata,
                'created_at' => $molliePayment->createdAt,
                'paid_at' => $molliePayment->paidAt,
                'data' => $molliePayment
            ];

        } catch (\Exception $e) {
            Log::error('Mollie webhook error: ' . $e->getMessage(), [
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'WEBHOOK_FAILED'
                ]
            ];
        }
    }

    /**
     * Get payment methods
     */
    protected function getPaymentMethods(array $data): ?array
    {
        $methods = $data['payment_options'] ?? null;

        if ($methods === 'all' || !$methods) {
            return null; // All available methods
        }

        if (is_string($methods)) {
            $methods = [$methods];
        }

        // Map common method names to Mollie method constants
        $methodMap = [
            'credit_card' => PaymentMethod::CREDITCARD,
            'ideal' => PaymentMethod::IDEAL,
            'bancontact' => PaymentMethod::BANCONTACT,
            'sepa_debit' => PaymentMethod::DIRECTDEBIT,
            'sepa' => PaymentMethod::DIRECTDEBIT,
            'sofort' => PaymentMethod::SOFORT,
            'giropay' => PaymentMethod::GIROPAY,
            'eps' => PaymentMethod::EPS,
            'paypal' => PaymentMethod::PAYPAL,
            'apple_pay' => PaymentMethod::APPLEPAY,
            'google_pay' => PaymentMethod::PAYPAL,
            'klarna' => PaymentMethod::KLARNA_PAY_LATER,
            'przelewy24' => PaymentMethod::PRZELEWY24,
            'belfius' => PaymentMethod::BELFIUS,
            'kbc' => PaymentMethod::KBC,
            'inghomepay' => PaymentMethod::INGHOMEPAY,
            'point_of_sale' => PaymentMethod::POINTOFSALE,
            'bank_transfer' => PaymentMethod::BANKTRANSFER,
            'voucher' => PaymentMethod::VOUCHER,
            'trustly' => PaymentMethod::TRUSTLY,
            'twint' => PaymentMethod::TWINT,
            'bacs' => PaymentMethod::BACS,
            'mybank' => PaymentMethod::MYBANK,
            'sdd' => PaymentMethod::DIRECTDEBIT,
        ];

        $selectedMethods = [];
        foreach ($methods as $method) {
            $method = strtolower($method);
            if (isset($methodMap[$method])) {
                $selectedMethods[] = $methodMap[$method];
            } elseif (in_array($method, PaymentMethod::getMethods())) {
                $selectedMethods[] = $method;
            }
        }

        return !empty($selectedMethods) ? $selectedMethods : null;
    }

    /**
     * Create customer
     */
    public function createCustomer(array $data): array
    {
        try {
            $customerData = [
                'name' => $data['name'],
                'email' => $data['email'],
                'metadata' => $data['metadata'] ?? []
            ];

            if (isset($data['locale'])) {
                $customerData['locale'] = $data['locale'];
            }

            $customer = $this->mollie->customers->create($customerData);

            return [
                'success' => true,
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'locale' => $customer->locale,
                    'metadata' => $customer->metadata,
                    'createdAt' => $customer->createdAt
                ],
                'message' => 'Customer created successfully'
            ];

        } catch (\Exception $e) {
            Log::error('Mollie customer creation error: ' . $e->getMessage(), [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'CUSTOMER_FAILED'
                ]
            ];
        }
    }

    /**
     * Create subscription
     */
    public function createSubscription(array $data): array
    {
        try {
            if (!isset($data['customer_id'])) {
                throw new \Exception('Customer ID is required for subscription');
            }

            $subscriptionData = [
                'amount' => [
                    'currency' => $data['currency'] ?? 'EUR',
                    'value' => number_format($data['amount'], 2, '.', '')
                ],
                'interval' => $data['interval'] ?? '1 month',
                'description' => $data['description'] ?? 'Subscription',
                'webhookUrl' => $this->config['webhook_url'],
                'metadata' => $data['metadata'] ?? []
            ];

            if (isset($data['start_date'])) {
                $subscriptionData['startDate'] = $data['start_date'];
            }

            if (isset($data['times'])) {
                $subscriptionData['times'] = $data['times'];
            }

            if (isset($data['mandate_id'])) {
                $subscriptionData['mandateId'] = $data['mandate_id'];
            }

            $subscription = $this->mollie->subscriptions->createFor(
                $data['customer_id'],
                $subscriptionData
            );

            return [
                'success' => true,
                'subscription_id' => $subscription->id,
                'customer_id' => $data['customer_id'],
                'amount' => (float) $subscription->amount->value,
                'currency' => $subscription->amount->currency,
                'interval' => $subscription->interval,
                'description' => $subscription->description,
                'status' => $subscription->status,
                'method' => $subscription->method,
                'mandate_id' => $subscription->mandateId,
                'start_date' => $subscription->startDate,
                'next_payment_date' => $subscription->nextPaymentDate,
                'canceled_at' => $subscription->canceledAt,
                'webhook_url' => $subscription->webhookUrl,
                'created_at' => $subscription->createdAt,
                'message' => 'Subscription created successfully',
                'data' => $subscription
            ];

        } catch (\Exception $e) {
            Log::error('Mollie subscription error: ' . $e->getMessage(), [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'SUBSCRIPTION_FAILED'
                ]
            ];
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        try {
            $subscription = $this->mollie->subscriptions->get($subscriptionId);
            $canceledSubscription = $subscription->cancel();

            return [
                'success' => true,
                'subscription_id' => $subscriptionId,
                'status' => $canceledSubscription->status,
                'canceled_at' => $canceledSubscription->canceledAt,
                'message' => 'Subscription canceled successfully',
                'data' => $canceledSubscription
            ];

        } catch (\Exception $e) {
            Log::error('Mollie subscription cancel error: ' . $e->getMessage(), [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'SUBSCRIPTION_CANCEL_FAILED'
                ]
            ];
        }
    }

    /**
     * Create payment link (Mollie calls this "Payment URL" without amount)
     */
    public function createPaymentLink(array $data): array
    {
        try {
            $paymentData = [
                'description' => $data['description'] ?? 'Payment',
                'redirectUrl' => $data['redirect_url'] ?? $this->config['redirect_url'],
                'webhookUrl' => $this->config['webhook_url'],
                'method' => $this->getPaymentMethods($data),
                'metadata' => $data['metadata'] ?? []
            ];

            if (isset($data['amount'])) {
                $paymentData['amount'] = [
                    'currency' => $data['currency'] ?? 'EUR',
                    'value' => number_format($data['amount'], 2, '.', '')
                ];
            }

            $payment = $this->mollie->payments->create($paymentData);

            return [
                'success' => true,
                'link_id' => $payment->id,
                'payment_url' => $payment->getCheckoutUrl(),
                'amount' => isset($paymentData['amount']) ? (float) $paymentData['amount']['value'] : null,
                'currency' => $paymentData['amount']['currency'] ?? null,
                'status' => $payment->status,
                'created_at' => $payment->createdAt,
                'expires_at' => $payment->expiresAt,
                'message' => 'Payment link created successfully',
                'data' => $payment
            ];

        } catch (\Exception $e) {
            Log::error('Mollie payment link error: ' . $e->getMessage(), [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'PAYMENT_LINK_FAILED'
                ]
            ];
        }
    }

    /**
     * Get transaction status
     */
    public function getTransactionStatus(string $transactionId): array
    {
        return $this->verify($transactionId);
    }

    /**
     * Get all payment methods
     */
    public function getAvailablePaymentMethods(array $params = []): array
    {
        try {
            $queryParams = [];

            if (isset($params['amount'])) {
                $queryParams['amount'] = [
                    'currency' => $params['currency'] ?? 'EUR',
                    'value' => number_format($params['amount'], 2, '.', '')
                ];
            }

            if (isset($params['locale'])) {
                $queryParams['locale'] = $params['locale'];
            }

            if (isset($params['billing_country'])) {
                $queryParams['billingCountry'] = $params['billing_country'];
            }

            $methods = $this->mollie->methods->all($queryParams);

            $paymentMethods = [];
            foreach ($methods as $method) {
                $paymentMethods[] = [
                    'id' => $method->id,
                    'description' => $method->description,
                    'image' => $method->image,
                    'minimum_amount' => $method->minimumAmount ? [
                        'value' => (float) $method->minimumAmount->value,
                        'currency' => $method->minimumAmount->currency
                    ] : null,
                    'maximum_amount' => $method->maximumAmount ? [
                        'value' => (float) $method->maximumAmount->value,
                        'currency' => $method->maximumAmount->currency
                    ] : null
                ];
            }

            return [
                'success' => true,
                'methods' => $paymentMethods,
                'count' => count($paymentMethods)
            ];

        } catch (\Exception $e) {
            Log::error('Mollie payment methods error: ' . $e->getMessage(), [
                'params' => $params,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'PAYMENT_METHODS_FAILED'
                ]
            ];
        }
    }

    /**
     * Create mandate for recurring payments
     */
    public function createMandate(string $customerId, array $data): array
    {
        try {
            $mandateData = [
                'method' => $data['method'] ?? PaymentMethod::DIRECTDEBIT,
                'consumerAccount' => $data['consumer_account'] ?? null,
                'consumerName' => $data['consumer_name'] ?? null,
                'mandateReference' => $data['mandate_reference'] ?? null,
                'signatureDate' => $data['signature_date'] ?? null
            ];

            $mandate = $this->mollie->mandates->createFor($customerId, $mandateData);

            return [
                'success' => true,
                'mandate_id' => $mandate->id,
                'customer_id' => $customerId,
                'method' => $mandate->method,
                'status' => $mandate->status,
                'details' => $mandate->details,
                'created_at' => $mandate->createdAt,
                'message' => 'Mandate created successfully',
                'data' => $mandate
            ];

        } catch (\Exception $e) {
            Log::error('Mollie mandate creation error: ' . $e->getMessage(), [
                'customer_id' => $customerId,
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'MANDATE_FAILED'
                ]
            ];
        }
    }

    /**
     * Get customer mandates
     */
    public function getCustomerMandates(string $customerId): array
    {
        try {
            $mandates = $this->mollie->mandates->listFor($customerId);

            $mandateList = [];
            foreach ($mandates as $mandate) {
                $mandateList[] = [
                    'id' => $mandate->id,
                    'method' => $mandate->method,
                    'status' => $mandate->status,
                    'details' => $mandate->details,
                    'created_at' => $mandate->createdAt
                ];
            }

            return [
                'success' => true,
                'mandates' => $mandateList,
                'count' => count($mandateList)
            ];

        } catch (\Exception $e) {
            Log::error('Mollie mandates error: ' . $e->getMessage(), [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 'MANDATES_FAILED'
                ]
            ];
        }
    }

    /**
     * Check if currency is supported
     */
    protected function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supportedCurrencies);
    }

    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies(): array
    {
        return $this->supportedCurrencies;
    }

    /**
     * Get gateway configuration
     */
    public function getGatewayConfig(): array
    {
        return [
            'name' => $this->getGatewayName(),
            'display_name' => 'Mollie',
            'currencies' => $this->getSupportedCurrencies(),
            'supports_subscriptions' => true,
            'supports_refunds' => true,
            'supports_webhooks' => true,
            'test_mode' => $this->config['test_mode'],
            'api_key' => $this->config['api_key'] ? substr($this->config['api_key'], 0, 8) . '...' : null,
        ];
    }
}