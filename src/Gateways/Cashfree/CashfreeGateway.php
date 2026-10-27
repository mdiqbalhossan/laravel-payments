<?php

namespace Mdiqbal\LaravelPayments\Gateways\Cashfree;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * Cashfree Payment Gateway (PG API, x-api-version 2023-08-01).
 *
 * Flow: pay() creates an order and returns payment_session_id, which the
 * frontend passes to the Cashfree JS SDK (cashfree.checkout({paymentSessionId})).
 * verify() reads the order from the API (order_status PAID = success).
 * Webhooks are checked with x-webhook-signature =
 * base64(hmac_sha256(x-webhook-timestamp . rawBody, secret_key)).
 */
class CashfreeGateway extends AbstractGateway
{
    private const API_VERSION = '2023-08-01';

    public function gatewayName(): string
    {
        return 'cashfree';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $orderId = $request->getTransactionId() ?? $this->generateOrderId();
        $customer = $request->getCustomer() ?? [];

        $phone = $customer['phone'] ?? $request->getMeta('phone');
        if (!$phone) {
            throw new PaymentException('Cashfree requires a customer phone number (customer.phone)');
        }

        $body = [
            'order_id' => $orderId,
            'order_amount' => round($request->getAmount(), 2),
            'order_currency' => strtoupper($request->getCurrency() ?: 'INR'),
            'customer_details' => array_filter([
                'customer_id' => (string) ($request->getMeta('customer_id') ?? 'cust_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $orderId)),
                'customer_phone' => (string) $phone,
                'customer_email' => $request->getEmail(),
                'customer_name' => $customer['name'] ?? null,
            ]),
            'order_meta' => array_filter([
                'return_url' => $request->getReturnUrl(),
                'notify_url' => $request->getNotifyUrl(),
            ]),
            'order_note' => $request->getDescription(),
        ];

        $order = $this->api('post', '/pg/orders', array_filter($body, fn ($v) => $v !== null && $v !== []));

        if (empty($order['payment_session_id'])) {
            throw new PaymentException('Cashfree did not return a payment_session_id');
        }

        return new PaymentResponse(
            success: true,
            transactionId: $orderId,
            message: 'Order created; open Cashfree checkout with payment_session_id',
            status: 'pending',
            data: [
                'payment_session_id' => $order['payment_session_id'],
                'cf_order_id' => $order['cf_order_id'] ?? null,
                'environment' => $this->isSandbox() ? 'sandbox' : 'production',
            ],
            gatewayReference: isset($order['cf_order_id']) ? (string) $order['cf_order_id'] : null,
            amount: (float) $order['order_amount'],
            currency: $order['order_currency'] ?? null
        );
    }

    /**
     * Read the order status from Cashfree. Accepts order_id (return URL) or
     * a webhook payload (data.order.order_id).
     */
    public function verify(array $payload): PaymentResponse
    {
        $orderId = $payload['order_id'] ?? $payload['orderId'] ?? $payload['data']['order']['order_id'] ?? null;
        if (!$orderId) {
            throw new PaymentException('Cashfree order_id not found in payload');
        }

        $order = $this->api('get', '/pg/orders/' . rawurlencode($orderId));
        $status = $order['order_status'] ?? 'UNKNOWN';

        return new PaymentResponse(
            success: $status === 'PAID',
            transactionId: $order['order_id'] ?? $orderId,
            message: "Cashfree order status: {$status}",
            status: match ($status) {
                'PAID' => 'completed',
                'ACTIVE' => 'pending',
                'EXPIRED', 'TERMINATED' => 'canceled',
                default => 'failed',
            },
            data: ['raw' => $order],
            gatewayReference: isset($order['cf_order_id']) ? (string) $order['cf_order_id'] : null,
            amount: isset($order['order_amount']) ? (float) $order['order_amount'] : null,
            currency: $order['order_currency'] ?? null
        );
    }

    public function handleWebhook(array $payload, string $rawBody, array $headers): PaymentResponse
    {
        $timestamp = $headers['x-webhook-timestamp'] ?? '';
        $signature = $headers['x-webhook-signature'] ?? '';
        $expected = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $this->requireModeConfig('secret_key'), true));

        if ($timestamp === '' || $signature === '' || !hash_equals($expected, $signature)) {
            throw new InvalidSignatureException('Invalid Cashfree webhook signature', gateway: 'cashfree');
        }

        return $this->verify($payload);
    }

    /**
     * Refund a paid order. $transactionId is the order_id. Refunds are processed asynchronously.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        if ($amount <= 0) {
            $amount = (float) ($this->verify(['order_id' => $transactionId])->amount ?? 0);
        }

        $refund = $this->api('post', '/pg/orders/' . rawurlencode($transactionId) . '/refunds', [
            'refund_amount' => round($amount, 2),
            'refund_id' => 'refund_' . Str::lower(Str::random(16)),
            'refund_note' => 'Refund requested',
        ]);

        return in_array($refund['refund_status'] ?? null, ['PENDING', 'SUCCESS', 'ONHOLD'], true);
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    private function api(string $method, string $path, array $data = []): array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->withHeaders([
                'x-client-id' => $this->requireModeConfig('app_id'),
                'x-client-secret' => $this->requireModeConfig('secret_key'),
                'x-api-version' => self::API_VERSION,
            ])
            ->{$method}($this->baseUrl() . $path, $data);

        $json = $response->json() ?? [];

        if (!$response->successful()) {
            throw new PaymentException('Cashfree: ' . ($json['message'] ?? "HTTP {$response->status()}"));
        }

        return $json;
    }

    private function baseUrl(): string
    {
        return $this->isSandbox() ? 'https://sandbox.cashfree.com' : 'https://api.cashfree.com';
    }
}
