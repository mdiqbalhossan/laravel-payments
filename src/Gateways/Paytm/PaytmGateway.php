<?php

namespace Mdiqbal\LaravelPayments\Gateways\Paytm;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use GuzzleHttp\Client;
use Illuminate\Support\Str;

class PaytmGateway extends AbstractGateway
{
    private ?Client $httpClient = null;

    public function gatewayName(): string
    {
        return 'paytm';
    }

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->httpClient = new Client([
            'timeout' => 30,
            'verify' => true,
        ]);
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        try {
            $merchantId = $this->getMerchantId();
            $merchantKey = $this->getMerchantKey();

            if (!$merchantId || !$merchantKey) {
                throw new PaymentException('Paytm merchant credentials not configured');
            }

            // Initialize Paytm transaction
            $transactionData = $this->createTransaction($request);

            $this->log('info', 'Paytm transaction created', [
                'order_id' => $transactionData['order_id'],
                'txn_token' => $transactionData['txn_token'],
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency()
            ]);

            // Generate payment URL
            $paymentUrl = $this->getPaymentUrl($transactionData);

            return PaymentResponse::redirect($paymentUrl, [
                'transaction_id' => $transactionData['order_id'],
                'order_id' => $transactionData['order_id'],
                'txn_token' => $transactionData['txn_token'],
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency(),
                'status' => 'created',
                'message' => 'Transaction created successfully'
            ]);

        } catch (\Exception $e) {
            $this->log('error', 'Paytm payment creation failed', [
                'error' => $e->getMessage(),
                'amount' => $request->getAmount()
            ]);
            throw new PaymentException('Paytm payment failed: ' . $e->getMessage());
        }
    }

    public function verify(array $payload): PaymentResponse
    {
        try {
            // Verify the checksum
            if (!$this->verifyChecksum($payload)) {
                throw new PaymentException('Invalid Paytm checksum');
            }

            $orderId = $payload['ORDERID'] ?? null;
            $transactionId = $payload['TXNID'] ?? null;
            $status = $payload['STATUS'] ?? null;

            if (!$orderId || !$transactionId) {
                throw new PaymentException('Invalid transaction response');
            }

            // Get transaction status from Paytm (optional, we already have status from callback)
            // $statusResponse = $this->getTransactionStatus($orderId);

            $paymentStatus = match($status) {
                'TXN_SUCCESS', 'SUCCESS' => 'completed',
                'TXN_FAILURE', 'FAILURE' => 'failed',
                'PENDING' => 'pending',
                'OPEN' => 'pending',
                default => 'unknown'
            };

            $this->log('info', 'Paytm transaction verified', [
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'status' => $paymentStatus
            ]);

            return new PaymentResponse(
                success: $status === 'TXN_SUCCESS',
                transactionId: $transactionId,
                status: $paymentStatus,
                data: [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'bank_transaction_id' => $payload['BANKTXNID'] ?? null,
                    'bank_name' => $payload['BANKNAME'] ?? null,
                    'payment_mode' => $payload['PAYMENTMODE'] ?? null,
                    'gateway_name' => $payload['GATEWAYNAME'] ?? null,
                    'response_code' => $payload['RESPCODE'] ?? null,
                    'response_message' => $payload['RESPMSG'] ?? null,
                    'transaction_date' => $payload['TXNDATE'] ?? null,
                    'amount' => ($payload['TXNAMOUNT'] ?? 0) / 100, // Convert from paise
                    'currency' => 'INR',
                    'checksum_hash' => $payload['CHECKSUMHASH'] ?? null,
                    'mid' => $payload['MID'] ?? null,
                    'verification_data' => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Paytm payment verification failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw new PaymentException('Paytm payment verification failed: ' . $e->getMessage());
        }
    }

    public function refund(string $transactionId, float $amount): bool
    {
        try {
            $refund = $this->createRefund($transactionId, $amount);

            $this->log('info', 'Paytm refund processed', [
                'refund_id' => $refund['refund_id'] ?? null,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'status' => $refund['status'] ?? 'PENDING'
            ]);

            return true;

        } catch (\Exception $e) {
            $this->log('error', 'Paytm refund failed', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Paytm refund failed: ' . $e->getMessage());
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * Get transaction status
     */
    public function getTransactionStatus(string $orderId): PaymentResponse
    {
        try {
            $merchantId = $this->getMerchantId();
            $merchantKey = $this->getMerchantKey();

            if (!$merchantId || !$merchantKey) {
                throw new PaymentException('Paytm merchant credentials not configured');
            }

            $apiUrl = $this->isSandbox()
                ? 'https://securegw-stage.paytm.in/v3/order/status'
                : 'https://securegw.paytm.in/v3/order/status';

            $requestBody = [
                'mid' => $merchantId,
                'orderId' => $orderId,
            ];

            $checksum = $this->generateChecksumString($requestBody, $merchantKey);
            $requestBody['checksumhash'] = $checksum;

            $response = $this->httpClient->post($apiUrl, [
                'json' => $requestBody,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            $status = $responseData['body']['resultInfo']['resultStatus'] ?? 'PENDING';
            $transactionId = $responseData['body']['txnId'] ?? null;
            $amount = ($responseData['body']['txnAmount'] ?? 0) / 100;

            return new PaymentResponse(
                success: $status === 'TXN_SUCCESS',
                transactionId: $transactionId ?? $orderId,
                status: $status === 'TXN_SUCCESS' ? 'completed' : ($status === 'PENDING' ? 'pending' : 'failed'),
                data: [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                    'currency' => 'INR',
                    'status' => $status,
                    'response_code' => $responseData['body']['resultInfo']['resultCode'] ?? null,
                    'response_message' => $responseData['body']['resultInfo']['resultMsg'] ?? null,
                    'bank_transaction_id' => $responseData['body']['bankTxnId'] ?? null,
                    'bank_name' => $responseData['body']['bankName'] ?? null,
                    'payment_mode' => $responseData['body']['paymentMode'] ?? null,
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to get Paytm transaction status: ' . $e->getMessage());
        }
    }

    /**
     * Initiate refund
     */
    public function initiateRefund(array $refundData): PaymentResponse
    {
        try {
            $refund = $this->createRefund(
                $refundData['transaction_id'] ?? '',
                $refundData['refund_amount'] ?? 0
            );

            return new PaymentResponse(
                success: true,
                transactionId: $refund['refund_id'] ?? null,
                status: 'initiated',
                data: [
                    'refund_id' => $refund['refund_id'] ?? null,
                    'order_id' => $refundData['order_id'] ?? null,
                    'transaction_id' => $refundData['transaction_id'] ?? null,
                    'refund_amount' => ($refund['refund_amount'] ?? 0) / 100,
                    'status' => $refund['status'] ?? 'PENDING'
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to initiate Paytm refund: ' . $e->getMessage());
        }
    }

    /**
     * Check refund status
     */
    public function checkRefundStatus(string $refundId): PaymentResponse
    {
        try {
            // Refund status check implementation would go here
            // For now, return a basic response
            return new PaymentResponse(
                success: false,
                transactionId: $refundId,
                status: 'pending',
                data: [
                    'refund_id' => $refundId,
                    'status' => 'PENDING',
                ]
            );
        } catch (\Exception $e) {
            throw new PaymentException('Failed to check Paytm refund status: ' . $e->getMessage());
        }
    }

    /**
     * Validate Paytm checksum
     */
    public function validateChecksum(array $params, string $checksum): bool
    {
        try {
            $merchantKey = $this->getMerchantKey();
            if (!$merchantKey) {
                return false;
            }

            $generatedChecksum = $this->generateChecksumString($params, $merchantKey);
            return hash_equals($checksum, $generatedChecksum);
        } catch (\Exception $e) {
            $this->log('error', 'Checksum validation failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Process webhook event
     */
    public function processWebhook(array $payload): PaymentResponse
    {
        try {
            // Verify webhook checksum
            if (!$this->verifyChecksum($payload)) {
                throw new PaymentException('Invalid Paytm webhook checksum');
            }

            $eventType = $payload['eventType'] ?? 'transaction';
            $orderId = $payload['ORDERID'] ?? null;
            $transactionId = $payload['TXNID'] ?? null;
            $status = $payload['STATUS'] ?? null;

            $paymentStatus = match($status) {
                'TXN_SUCCESS', 'SUCCESS' => 'completed',
                'TXN_FAILURE', 'FAILURE' => 'failed',
                'PENDING' => 'pending',
                'REFUND' => 'refunded',
                default => 'unknown'
            };

            $this->log('info', 'Paytm webhook processed', [
                'event_type' => $eventType,
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'status' => $paymentStatus
            ]);

            return new PaymentResponse(
                success: $status === 'TXN_SUCCESS',
                transactionId: $transactionId,
                status: $paymentStatus,
                data: [
                    'event_type' => $eventType,
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'bank_transaction_id' => $payload['BANKTXNID'] ?? null,
                    'payment_mode' => $payload['PAYMENTMODE'] ?? null,
                    'amount' => ($payload['TXNAMOUNT'] ?? 0) / 100,
                    'currency' => 'INR',
                    'response_code' => $payload['RESPCODE'] ?? null,
                    'response_message' => $payload['RESPMSG'] ?? null,
                    'webhook_data' => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Paytm webhook processing failed', [
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Paytm webhook processing failed: ' . $e->getMessage());
        }
    }


    /**
     * Get merchant ID based on mode
     */
    private function getMerchantId(): ?string
    {
        return $this->getModeConfig('merchant_id');
    }

    /**
     * Get merchant key based on mode
     */
    private function getMerchantKey(): ?string
    {
        return $this->getModeConfig('merchant_key');
    }

    /**
     * Get website based on mode
     */
    private function getWebsite(): string
    {
        return $this->isSandbox() ? 'WEBSTAGING' : 'DEFAULT';
    }

    /**
     * Get industry type
     */
    private function getIndustryType(): string
    {
        return 'Retail';
    }

    /**
     * Create Paytm transaction
     */
    private function createTransaction(PaymentRequest $request): array
    {
        $merchantId = $this->getMerchantId();
        $merchantKey = $this->getMerchantKey();

        if (!$merchantId || !$merchantKey) {
            throw new PaymentException('Paytm merchant credentials not configured');
        }

        $amountInPaise = (int) round($request->getAmount() * 100);
        $currency = $request->getCurrency() ?? 'INR';
        $metadata = $request->getMetadata();
        $orderId = $request->getTransactionId() ?? $this->generateOrderId();
        $customer = $request->getCustomer();

        // Prepare request body for Paytm API (body parameters)
        // MID must be in body for checksum calculation, and also in query parameter
        $bodyParams = [
            'mid' => $merchantId,
            'orderId' => $orderId,
            'amount' => (string) $amountInPaise,
            'currency' => $currency,
            'websiteName' => $this->getWebsite(),
            'industryTypeId' => $this->getIndustryType(),
            'channelId' => $metadata['channel_id'] ?? 'WEB',
            'callbackUrl' => $request->getReturnUrl() ?? $request->getCallbackUrl(),
        ];

        // Add customer details (only add non-null values)
        if ($customer) {
            $bodyParams['customerId'] = $metadata['customer_id'] ?? $customer['email'] ?? 'CUST' . $orderId;
            $bodyParams['email'] = $customer['email'] ?? $metadata['email'] ?? 'customer@example.com';
            if (isset($customer['phone']) && $customer['phone']) {
                $bodyParams['mobileNumber'] = $customer['phone'];
            } elseif (isset($metadata['phone']) && $metadata['phone']) {
                $bodyParams['mobileNumber'] = $metadata['phone'];
            }
        } else {
            $bodyParams['customerId'] = $metadata['customer_id'] ?? 'CUST' . $orderId;
            $bodyParams['email'] = $metadata['email'] ?? 'customer@example.com';
            if (isset($metadata['phone']) && $metadata['phone']) {
                $bodyParams['mobileNumber'] = $metadata['phone'];
            }
        }

        // Remove null and empty values before checksum generation
        $bodyParamsForChecksum = array_filter($bodyParams, function($value) {
            return $value !== null && $value !== '';
        }, ARRAY_FILTER_USE_BOTH);

        // Generate checksum from body parameters (MID is included in body for checksum)
        $checksum = $this->generateChecksumString($bodyParamsForChecksum, $merchantKey);
        $bodyParams['checksumhash'] = $checksum;

        // Paytm API v1 expects body and head structure
        // Note: MID is in query parameter, but also in body for checksum calculation
        $requestBody = [
            'body' => $bodyParams,
            'head' => [
                'version' => 'v1',
                'channelId' => $metadata['channel_id'] ?? 'WEB',
                'requestTimestamp' => (string)(time() * 1000), // Paytm expects milliseconds as string
            ]
        ];

        // Call Paytm initiate transaction API
        // Paytm requires MID as a query parameter
        $baseUrl = $this->isSandbox()
            ? 'https://securegw-stage.paytm.in/theia/api/v1/initiateTransaction'
            : 'https://securegw.paytm.in/theia/api/v1/initiateTransaction';

        try {
            $this->log('info', 'Paytm initiate transaction request', [
                'url' => $baseUrl,
                'query' => ['mid' => $merchantId],
                'body' => $requestBody,
                'merchant_id' => $merchantId,
            ]);

            $response = $this->httpClient->post($baseUrl, [
                'query' => [
                    'mid' => $merchantId,
                ],
                'json' => $requestBody,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $responseBody = $response->getBody()->getContents();
            $responseData = json_decode($responseBody, true);

            $this->log('info', 'Paytm initiate transaction response', [
                'status_code' => $response->getStatusCode(),
                'response' => $responseData,
            ]);

            if (!isset($responseData['body']['txnToken'])) {
                $errorMsg = $responseData['body']['resultInfo']['resultMsg'] ?? 'Unknown error';
                $errorCode = $responseData['body']['resultInfo']['resultCode'] ?? 'Unknown';
                throw new PaymentException("Paytm API error [{$errorCode}]: {$errorMsg}. Full response: " . json_encode($responseData));
            }

            return [
                'order_id' => $orderId,
                'txn_token' => $responseData['body']['txnToken'],
                'amount' => $amountInPaise,
                'currency' => $currency,
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorMessage = 'Paytm API error: ' . $errorBody;
            }
            throw new PaymentException($errorMessage);
        }
    }

    /**
     * Get payment URL
     */
    private function getPaymentUrl(array $transactionData): string
    {
        $baseUrl = $this->isSandbox()
            ? 'https://securegw-stage.paytm.in/theia/processTransaction'
            : 'https://securegw.paytm.in/theia/processTransaction';

        $params = [
            'orderid' => $transactionData['order_id'],
            'mid' => $this->getMerchantId(),
            'txnToken' => $transactionData['txn_token'],
        ];

        return $baseUrl . '?' . http_build_query($params);
    }

    /**
     * Create refund
     */
    private function createRefund(string $transactionId, float $amount): array
    {
        // Refund implementation would go here
        // For now, return basic structure
        return [
            'refund_id' => 'REF' . Str::random(10),
            'transaction_id' => $transactionId,
            'refund_amount' => (int) round($amount * 100),
            'status' => 'PENDING',
        ];
    }

    /**
     * Verify checksum
     */
    private function verifyChecksum(array $payload): bool
    {
        try {
            $merchantKey = $this->getMerchantKey();
            if (!$merchantKey) {
                return false;
            }

            // Extract checksum from payload
            $checksum = $payload['CHECKSUMHASH'] ?? '';
            unset($payload['CHECKSUMHASH']);

            // Remove any empty values
            $payload = array_filter($payload, function($value) {
                return $value !== '' && $value !== null;
            });

            // Generate checksum from payload
            $generatedChecksum = $this->generateChecksumString($payload, $merchantKey);

            return hash_equals($checksum, $generatedChecksum);
        } catch (\Exception $e) {
            $this->log('error', 'Checksum verification failed', [
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
            'credit_card',
            'debit_card',
            'net_banking',
            'upi',
            'paytm_wallet',
            'emi',
            'cash_card'
        ];
    }

    /**
     * Create subscription
     */
    public function createSubscription(array $subscriptionData): PaymentResponse
    {
        // Subscription implementation would go here
        throw new PaymentException('Paytm subscription is not yet implemented');
    }

    /**
     * Get subscription status
     */
    public function getSubscriptionStatus(string $subscriptionId): PaymentResponse
    {
        throw new PaymentException('Paytm subscription status is not yet implemented');
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(string $subscriptionId): bool
    {
        throw new PaymentException('Paytm subscription cancellation is not yet implemented');
    }

    /**
     * Validate transaction parameters
     */
    private function validateTransactionParams(array $params): array
    {
        // Required parameters
        $required = ['MID', 'ORDERID', 'TXNAMOUNT', 'CURRENCY', 'CHECKSUMHASH'];

        foreach ($required as $param) {
            if (!isset($params[$param])) {
                throw new PaymentException("Missing required parameter: {$param}");
            }
        }

        // Validate amount
        if ($params['TXNAMOUNT'] <= 0) {
            throw new PaymentException('Invalid transaction amount');
        }

        // Validate currency
        if ($params['CURRENCY'] !== 'INR') {
            throw new PaymentException('Only INR currency is supported');
        }

        return $params;
    }

    /**
     * Generate checksum
     */
    public function generateChecksum(array $params): string
    {
        try {
            $merchantKey = $this->getMerchantKey();
            if (!$merchantKey) {
                throw new PaymentException('Paytm merchant key not configured');
            }

            return $this->generateChecksumString($params, $merchantKey);
        } catch (\Exception $e) {
            throw new PaymentException('Failed to generate Paytm checksum: ' . $e->getMessage());
        }
    }

    /**
     * Generate checksum string using SHA256
     */
    private function generateChecksumString(array $params, string $merchantKey): string
    {
        // Sort parameters by key
        ksort($params);

        // Create string from parameters
        $string = '';
        foreach ($params as $key => $value) {
            if ($key !== 'CHECKSUMHASH' && $value !== '' && $value !== null) {
                $string .= $key . '=' . $value . '&';
            }
        }

        // Remove trailing &
        $string = rtrim($string, '&');

        // Append merchant key
        $string .= '&' . $merchantKey;

        // Generate SHA256 hash
        return hash('sha256', $string);
    }

    /**
     * Get payment modes
     */
    public function getPaymentModes(): array
    {
        // Return default payment modes supported by Paytm
        return [
            'CC' => 'Credit Card',
            'DC' => 'Debit Card',
            'NB' => 'Net Banking',
            'UPI' => 'UPI',
            'WALLET' => 'Paytm Wallet',
            'EMI' => 'EMI',
        ];
    }

    /**
     * Create recurring payment
     */
    public function createRecurringPayment(array $recurringData): PaymentResponse
    {
        throw new PaymentException('Paytm recurring payment is not yet implemented');
    }
}
