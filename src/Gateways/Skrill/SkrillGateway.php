<?php

namespace Mdiqbal\LaravelPayments\Gateways\Skrill;

use Exception;
use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;

class SkrillGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'skrill';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $this->validateRequest($request);

        try {
            // Generate payment session using PSD2 API
            $paymentSession = $this->createPaymentSession($request);

            if ($paymentSession && isset($paymentSession['id'])) {
                return PaymentResponse::redirect($paymentSession['redirect_url'], [
                    'session_id' => $paymentSession['id'],
                    'payment_id' => $paymentSession['id'],
                    'transaction_id' => $request->getTransactionId(),
                    'message' => 'Redirect to Skrill payment page'
                ]);
            }

            return $this->createErrorResponse('Failed to create payment session');
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
            $sessionId = $payload['session_id'] ?? null;

            if (!$sessionId) {
                return $this->createErrorResponse('Session ID not found in webhook payload');
            }

            // Get payment status from Skrill
            $paymentStatus = $this->getPaymentStatus($sessionId);

            if (!$paymentStatus) {
                return $this->createErrorResponse('Payment session not found');
            }

            return PaymentResponse::success([
                'transaction_id' => $paymentStatus['merchant_transaction_id'] ?? $sessionId,
                'session_id' => $sessionId,
                'payment_id' => $paymentStatus['id'] ?? $sessionId,
                'status' => $this->mapSkrillStatus($paymentStatus['status'] ?? 'unknown'),
                'currency' => $paymentStatus['currency'] ?? 'EUR',
                'amount' => (float) ($paymentStatus['amount'] ?? 0),
                'fee_amount' => (float) ($paymentStatus['total_fee'] ?? 0),
                'net_amount' => (float) ($paymentStatus['total_amount'] ?? 0),
                'payment_method' => $paymentStatus['payment_instrument']['type'] ?? null,
                'customer_info' => [
                    'email' => $paymentStatus['customer']['email'] ?? null,
                    'name' => ($paymentStatus['customer']['first_name'] ?? '') . ' ' . ($paymentStatus['customer']['last_name'] ?? ''),
                    'ip' => $paymentStatus['customer']['ip'] ?? null,
                ],
                'merchant_info' => [
                    'skrill_session_id' => $sessionId,
                    'skrill_payment_id' => $paymentStatus['id'] ?? null,
                    'payment_type' => $paymentStatus['type'] ?? null,
                    'created_at' => $paymentStatus['creation_date'] ?? null,
                    'updated_at' => $paymentStatus['modification_date'] ?? null,
                ],
                'metadata' => [
                    'reference' => $paymentStatus['reference'] ?? null,
                    'merchant_description' => $paymentStatus['merchant_description'] ?? null,
                ]
            ]);
        } catch (Exception $e) {
            $this->logError('Payment verification failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function verify(string $sessionId): PaymentResponse
    {
        try {
            if (empty($sessionId)) {
                return $this->createErrorResponse('Session ID is required');
            }

            // Get payment status from Skrill
            $paymentStatus = $this->getPaymentStatus($sessionId);

            if (!$paymentStatus) {
                return $this->createErrorResponse('Payment session not found');
            }

            return PaymentResponse::success([
                'transaction_id' => $paymentStatus['merchant_transaction_id'] ?? $sessionId,
                'session_id' => $sessionId,
                'payment_id' => $paymentStatus['id'] ?? $sessionId,
                'status' => $this->mapSkrillStatus($paymentStatus['status'] ?? 'unknown'),
                'currency' => $paymentStatus['currency'] ?? 'EUR',
                'amount' => (float) ($paymentStatus['amount'] ?? 0),
                'fee_amount' => (float) ($paymentStatus['total_fee'] ?? 0),
                'net_amount' => (float) ($paymentStatus['total_amount'] ?? 0),
                'payment_method' => $paymentStatus['payment_instrument']['type'] ?? null,
                'customer_info' => [
                    'email' => $paymentStatus['customer']['email'] ?? null,
                    'name' => ($paymentStatus['customer']['first_name'] ?? '') . ' ' . ($paymentStatus['customer']['last_name'] ?? ''),
                    'ip' => $paymentStatus['customer']['ip'] ?? null,
                ],
                'merchant_info' => [
                    'skrill_session_id' => $sessionId,
                    'skrill_payment_id' => $paymentStatus['id'] ?? null,
                    'payment_type' => $paymentStatus['type'] ?? null,
                    'created_at' => $paymentStatus['creation_date'] ?? null,
                    'updated_at' => $paymentStatus['modification_date'] ?? null,
                ],
                'metadata' => [
                    'reference' => $paymentStatus['reference'] ?? null,
                    'merchant_description' => $paymentStatus['merchant_description'] ?? null,
                ]
            ]);
        } catch (Exception $e) {
            $this->logError('Payment verification failed', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function refund(array $data): PaymentResponse
    {
        try {
            $sessionId = $data['session_id'];
            $refundAmount = $data['amount'] ?? null;
            $refundReason = $data['reason'] ?? 'Refund requested';

            if (empty($sessionId)) {
                return $this->createErrorResponse('Session ID is required');
            }

            // Get original payment details first
            $paymentStatus = $this->getPaymentStatus($sessionId);

            if (!$paymentStatus) {
                return $this->createErrorResponse('Original payment not found');
            }

            // Check if payment is eligible for refund
            if (!in_array($paymentStatus['status'], ['processed', 'success'])) {
                return $this->createErrorResponse('Payment is not eligible for refund');
            }

            // Prepare refund request
            $refundData = [
                'session_id' => $sessionId,
                'amount' => $refundAmount,
                'reason' => $refundReason,
                'merchant_account_id' => $this->config->getMerchantId(),
            ];

            // Make API request to process refund
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->config->getApiKey(),
            ])->post($this->getEndpoint('psp/v2/refunds'), $refundData);

            if (!$response->successful()) {
                return $this->createErrorResponse('Refund request failed');
            }

            $result = $response->json();

            if (isset($result['id'])) {
                return PaymentResponse::success([
                    'refund_id' => $result['id'],
                    'session_id' => $sessionId,
                    'amount_refunded' => (float) ($result['amount'] ?? $refundAmount),
                    'currency' => $result['currency'] ?? $paymentStatus['currency'],
                    'reason' => $refundReason,
                    'status' => 'refunded',
                    'merchant_info' => [
                        'skrill_refund_id' => $result['id'],
                        'session_id' => $sessionId,
                    ]
                ]);
            }

            return $this->createErrorResponse('Refund processing failed: ' . ($result['error']['message'] ?? 'Unknown error'));
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

    public function getTransactionStatus(string $sessionId): PaymentResponse
    {
        return $this->verify($sessionId);
    }

    public function searchTransactions(array $filters = []): PaymentResponse
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->config->getApiKey(),
            ])->get($this->getEndpoint('psp/v2/payments'), $filters);

            if (!$response->successful()) {
                return $this->createErrorResponse('Failed to fetch transactions');
            }

            $result = $response->json();

            $transactions = [];
            foreach ($result['items'] ?? [] as $payment) {
                $transactions[] = [
                    'session_id' => $payment['id'],
                    'transaction_id' => $payment['merchant_transaction_id'] ?? $payment['id'],
                    'status' => $this->mapSkrillStatus($payment['status']),
                    'currency' => $payment['currency'],
                    'amount' => (float) $payment['amount'],
                    'created_at' => $payment['creation_date'],
                    'payment_method' => $payment['payment_instrument']['type'] ?? null,
                ];
            }

            return PaymentResponse::success([
                'transactions' => $transactions,
                'total' => count($transactions),
                'limit' => $result['limit'] ?? 10,
                'offset' => $result['offset'] ?? 0,
                'has_more' => $result['has_more'] ?? false
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
            'EUR', // Euro - Primary currency
            'USD', // US Dollar
            'GBP', // British Pound
            'PLN', // Polish Zloty
            'CZK', // Czech Koruna
            'DKK', // Danish Krone
            'NOK', // Norwegian Krone
            'SEK', // Swedish Krona
            'CHF', // Swiss Franc
            'CAD', // Canadian Dollar
            'AUD', // Australian Dollar
            'JPY', // Japanese Yen
            'HKD', // Hong Kong Dollar
            'SGD', // Singapore Dollar
            'ZAR', // South African Rand
            'INR', // Indian Rupee
        ];
    }

    public function getPaymentMethodsForCountry(string $countryCode): array
    {
        return $this->config->getPaymentMethods($countryCode) ?? [];
    }

    protected function createPaymentSession(PaymentRequest $request): ?array
    {
        try {
            $paymentData = [
                'identification' => [
                    'transactionid' => $request->getTransactionId(),
                    'customerid' => $request->getMetadata()['customer_id'] ?? null,
                ],
                'payment' => [
                    'amount' => (float) $request->getAmount(),
                    'currency' => $request->getCurrency(),
                    'type' => 'DB', // Debit transaction
                    'descriptor' => $request->getDescription() ?? 'Payment',
                ],
                'account' => [
                    'holder' => $this->buildAccountHolder($request),
                    'email' => $request->getEmail(),
                ],
                'customer' => [
                    'name' => [
                        'firstname' => $this->getFirstName($request->getCustomer()['name'] ?? ''),
                        'lastname' => $this->getLastName($request->getCustomer()['name'] ?? ''),
                    ],
                    'email' => $request->getEmail(),
                    'phone' => $request->getCustomer()['phone'] ?? null,
                    'address' => [
                        'street' => $request->getCustomer()['address'] ?? null,
                        'city' => $request->getCustomer()['city'] ?? null,
                        'state' => $request->getCustomer()['state'] ?? null,
                        'zip' => $request->getCustomer()['postal_code'] ?? null,
                        'country' => $request->getCustomer()['country'] ?? null,
                    ],
                ],
                'merchant' => [
                    'redirect' => [
                        'successurl' => $request->getRedirectUrl(),
                        'failureurl' => $request->getRedirectUrl(),
                        'cancelurl' => $request->getRedirectUrl(),
                        'pendingurl' => $request->getRedirectUrl(),
                    ],
                    'notificationurl' => $this->config->getWebhookUrl(),
                ],
                'customfields' => $this->buildCustomFields($request->getMetadata() ?? []),
            ];

            // Add optional parameters
            if ($request->getMetadata()) {
                $metadata = $request->getMetadata();

                if (isset($metadata['payment_methods'])) {
                    $paymentData['payment']['payment_methods'] = $metadata['payment_methods'];
                }

                if (isset($metadata['language'])) {
                    $paymentData['customer']['language'] = $metadata['language'];
                }
            }

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->config->getApiKey(),
            ])->post($this->getEndpoint('psp/v2/payments'), $paymentData);

            if ($response->successful()) {
                $result = $response->json();
                return $result;
            }

            $this->logError('Payment session creation failed', [
                'response' => $response->body(),
                'status' => $response->status()
            ]);

            return null;
        } catch (Exception $e) {
            $this->logError('Payment session creation exception', [
                'error' => $e->getMessage(),
                'request_data' => $request->toArray()
            ]);
            return null;
        }
    }

    protected function getPaymentStatus(string $sessionId): ?array
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->config->getApiKey(),
            ])->get($this->getEndpoint("psp/v2/payments/{$sessionId}"));

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (Exception $e) {
            $this->logError('Payment status check failed', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId
            ]);
            return null;
        }
    }

    protected function buildAccountHolder(array $customer): string
    {
        return $customer['name'] ?? 'Customer';
    }

    protected function getFirstName(string $fullName): string
    {
        $nameParts = explode(' ', $fullName, 2);
        return $nameParts[0] ?? '';
    }

    protected function getLastName(string $fullName): string
    {
        $nameParts = explode(' ', $fullName, 2);
        return $nameParts[1] ?? '';
    }

    protected function buildCustomFields(array $metadata): array
    {
        $customFields = [];
        $index = 0;

        foreach ($metadata as $key => $value) {
            if (is_string($value) && $index < 5) { // Skrill allows up to 5 custom fields
                $customFields[] = [
                    'name' => $key,
                    'value' => $value
                ];
                $index++;
            }
        }

        return $customFields;
    }

    protected function mapSkrillStatus(string $status): string
    {
        $statusMap = [
            'pending' => 'pending',
            'processed' => 'completed',
            'success' => 'completed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'partial_refunded' => 'partially_refunded',
            'declined' => 'failed',
            'expired' => 'expired',
            'preauthorised' => 'authorized',
            'chargeback' => 'reversed',
            'reversed' => 'reversed',
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
            throw new Exception("Currency {$request->getCurrency()} is not supported by Skrill");
        }
    }

    protected function getEndpoint(string $path): string
    {
        $baseUrl = $this->isSandbox()
            ? 'https://psp.dev.skrillws.net'
            : 'https://psp.skrill.com';

        return $baseUrl . '/' . ltrim($path, '/');
    }

    protected function createErrorResponse(string $message): PaymentResponse
    {
        return PaymentResponse::error($message, 400, [
            'gateway' => 'skrill',
            'timestamp' => time()
        ]);
    }

    public function parseCallback($request): array
    {
        return [
            'session_id' => $request->input('session_id'),
            'transaction_id' => $request->input('transaction_id'),
            'status' => $request->input('status'),
            'amount' => $request->input('amount'),
            'currency' => $request->input('currency'),
            'payment_method' => $request->input('payment_method'),
            'customer_email' => $request->input('customer_email'),
            'reference' => $request->input('reference'),
            'merchant_description' => $request->input('merchant_description'),
            'signature' => $request->input('signature'),
            'raw_body' => $request->getContent(),
            'headers' => $request->headers->all()
        ];
    }

    public function getGatewayConfig(): array
    {
        return [
            'merchant_id' => $this->config->getMerchantId(),
            'api_key' => $this->config->getApiKey(),
            'test_mode' => $this->isSandbox(),
            'country' => $this->config->getCountry(),
            'webhook_url' => $this->config->getWebhookUrl()
        ];
    }

    public function createSubscription(array $subscriptionData): PaymentResponse
    {
        try {
            // Skrill supports recurring payments through subscription API
            $subscriptionRequest = [
                'identification' => [
                    'transactionid' => 'SUB_' . time(),
                ],
                'payment' => [
                    'amount' => (float) $subscriptionData['amount'],
                    'currency' => $subscriptionData['currency'] ?? 'EUR',
                    'type' => 'RECURRING', // Recurring payment
                    'descriptor' => $subscriptionData['description'] ?? 'Subscription',
                ],
                'account' => [
                    'email' => $subscriptionData['email'],
                ],
                'customer' => [
                    'name' => [
                        'firstname' => $this->getFirstName($subscriptionData['customer_name'] ?? ''),
                        'lastname' => $this->getLastName($subscriptionData['customer_name'] ?? ''),
                    ],
                    'email' => $subscriptionData['email'],
                ],
                'merchant' => [
                    'redirect' => [
                        'successurl' => $subscriptionData['return_url'] ?? null,
                        'failureurl' => $subscriptionData['return_url'] ?? null,
                    ],
                    'notificationurl' => $this->config->getWebhookUrl(),
                ],
                'recurring' => [
                    'frequency' => $subscriptionData['frequency'] ?? 1, // 1 = monthly
                    'period' => $subscriptionData['period'] ?? 'M', // M = monthly
                    'cycles' => $subscriptionData['cycles'] ?? 0, // 0 = unlimited
                ],
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->config->getApiKey(),
            ])->post($this->getEndpoint('psp/v2/subscriptions'), $subscriptionRequest);

            if ($response->successful()) {
                $result = $response->json();

                return PaymentResponse::redirect($result['redirect_url'], [
                    'session_id' => $result['id'],
                    'payment_id' => $result['id'],
                    'transaction_id' => $subscriptionRequest['identification']['transactionid'],
                    'message' => 'Redirect to Skrill subscription setup'
                ]);
            }

            return $this->createErrorResponse('Failed to create subscription');
        } catch (Exception $e) {
            return $this->createErrorResponse($e->getMessage());
        }
    }

    public function cancelSubscription(string $subscriptionId): PaymentResponse
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->config->getApiKey(),
            ])->delete($this->getEndpoint("psp/v2/subscriptions/{$subscriptionId}"));

            if ($response->successful()) {
                return PaymentResponse::success([
                    'subscription_id' => $subscriptionId,
                    'status' => 'cancelled'
                ]);
            }

            return $this->createErrorResponse('Failed to cancel subscription');
        } catch (Exception $e) {
            return $this->createErrorResponse($e->getMessage());
        }
    }
}