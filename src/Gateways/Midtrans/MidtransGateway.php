<?php

namespace Mdiqbal\LaravelPayments\Gateways\Midtrans;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * Midtrans Snap.
 *
 * Flow: pay() creates a Snap transaction and returns redirect_url (and the
 * snap token in data for Snap.js). verify() reads the authoritative status
 * from the Core API. HTTP notifications are checked with signature_key =
 * sha512(order_id + status_code + gross_amount + server_key).
 */
class MidtransGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'midtrans';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $orderId = $request->getTransactionId() ?? $this->generateOrderId();
        $currency = strtoupper($request->getCurrency() ?: 'IDR');

        if ($currency !== 'IDR') {
            throw new PaymentException('Midtrans Snap charges in IDR; convert the amount before calling pay()');
        }

        $customer = $request->getCustomer() ?? [];
        $metadata = $request->getMetadata();

        $body = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) round($request->getAmount()),
            ],
            'customer_details' => array_filter([
                'first_name' => $customer['name'] ?? null,
                'email' => $request->getEmail(),
                'phone' => $customer['phone'] ?? null,
            ]),
        ];

        if ($request->getReturnUrl()) {
            $body['callbacks'] = ['finish' => $request->getReturnUrl()];
        }

        if (!empty($metadata['enabled_payments'])) {
            $body['enabled_payments'] = $metadata['enabled_payments'];
        }

        if (!empty($metadata['items'])) {
            $body['item_details'] = $metadata['items'];
        }

        $response = $this->client()->post($this->snapUrl() . '/snap/v1/transactions', $body);
        $json = $response->json() ?? [];

        if (!$response->successful() || empty($json['token'])) {
            throw new PaymentException('Midtrans: ' . ($json['error_messages'][0] ?? "HTTP {$response->status()}"));
        }

        return PaymentResponse::redirect($json['redirect_url'], [
            'transaction_id' => $orderId,
            'snap_token' => $json['token'],
            'client_key' => $this->getModeConfig('client_key'),
            'message' => 'Redirect to Midtrans Snap',
        ]);
    }

    /**
     * Get the transaction status from the Core API. Accepts order_id.
     */
    public function verify(array $payload): PaymentResponse
    {
        $orderId = $payload['order_id'] ?? $payload['transaction_id'] ?? null;
        if (!$orderId) {
            throw new PaymentException('Midtrans order_id not found in payload');
        }

        $response = $this->client()->get($this->apiUrl() . '/v2/' . rawurlencode($orderId) . '/status');
        $json = $response->json() ?? [];

        if (!$response->successful() || !isset($json['transaction_status'])) {
            throw new PaymentException('Midtrans: ' . ($json['status_message'] ?? "HTTP {$response->status()}"));
        }

        $txStatus = $json['transaction_status'];
        $fraud = $json['fraud_status'] ?? 'accept';
        $paid = $txStatus === 'settlement' || ($txStatus === 'capture' && $fraud === 'accept');

        return new PaymentResponse(
            success: $paid,
            transactionId: $json['order_id'] ?? $orderId,
            message: $json['status_message'] ?? null,
            status: match (true) {
                $paid => 'completed',
                in_array($txStatus, ['pending', 'authorize'], true), $txStatus === 'capture' => 'pending',
                in_array($txStatus, ['cancel', 'expire'], true) => 'canceled',
                in_array($txStatus, ['refund', 'partial_refund'], true) => 'refunded',
                default => 'failed',
            },
            data: [
                'transaction_status' => $txStatus,
                'fraud_status' => $json['fraud_status'] ?? null,
                'payment_type' => $json['payment_type'] ?? null,
                'raw' => $json,
            ],
            gatewayReference: $json['transaction_id'] ?? null,
            amount: isset($json['gross_amount']) ? (float) $json['gross_amount'] : null,
            currency: $json['currency'] ?? 'IDR'
        );
    }

    /**
     * HTTP notification: verify signature_key, then confirm with the status API.
     */
    public function handleWebhook(array $payload, string $rawBody, array $headers): PaymentResponse
    {
        $expected = hash('sha512',
            ($payload['order_id'] ?? '') . ($payload['status_code'] ?? '') . ($payload['gross_amount'] ?? '') . $this->requireModeConfig('server_key')
        );

        if (!hash_equals($expected, (string) ($payload['signature_key'] ?? ''))) {
            throw new InvalidSignatureException('Invalid Midtrans signature_key', gateway: 'midtrans');
        }

        return $this->verify($payload);
    }

    /**
     * Refund a settled transaction. $transactionId is the order_id.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        $body = ['refund_key' => 'ref-' . Str::lower(Str::random(12)), 'reason' => 'Refund requested'];
        if ($amount > 0) {
            $body['amount'] = (int) round($amount);
        }

        $response = $this->client()->post($this->apiUrl() . '/v2/' . rawurlencode($transactionId) . '/refund', $body);
        $json = $response->json() ?? [];

        if ($response->successful() && in_array((string) ($json['status_code'] ?? ''), ['200', '201'], true)) {
            return true;
        }

        throw new PaymentException('Midtrans refund failed: ' . ($json['status_message'] ?? "HTTP {$response->status()}"));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    private function client()
    {
        return Http::timeout(30)
            ->acceptJson()
            ->withBasicAuth($this->requireModeConfig('server_key'), '');
    }

    private function snapUrl(): string
    {
        return $this->isSandbox() ? 'https://app.sandbox.midtrans.com' : 'https://app.midtrans.com';
    }

    private function apiUrl(): string
    {
        return $this->isSandbox() ? 'https://api.sandbox.midtrans.com' : 'https://api.midtrans.com';
    }
}
