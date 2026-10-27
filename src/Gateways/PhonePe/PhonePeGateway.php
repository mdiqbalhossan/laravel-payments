<?php

namespace Mdiqbal\LaravelPayments\Gateways\PhonePe;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * PhonePe PG Standard Checkout (salt-key / X-VERIFY API, /pg/v1).
 *
 * Config (per mode): merchant_id (or client_id), salt_key (or secret_key),
 * salt_index (or key_index), optional merchant_user_id.
 *
 * Flow: pay() returns the PhonePe pay page URL; the merchantTransactionId
 * (your order id) is the transaction id. verify() calls the Status API.
 * Server-to-server callbacks ({"response": base64}) are checked with
 * X-VERIFY = sha256(response . saltKey) . "###" . saltIndex.
 */
class PhonePeGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'phonepe';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        if (strtoupper($request->getCurrency() ?: 'INR') !== 'INR') {
            throw new PaymentException('PhonePe only supports INR');
        }

        $redirectUrl = $request->getReturnUrl();
        if (!$redirectUrl) {
            throw new PaymentException('PhonePe requires a return (redirect) URL');
        }

        $txnId = $request->getTransactionId() ?? $this->generateOrderId();
        $customer = $request->getCustomer() ?? [];

        $payload = array_filter([
            'merchantId' => $this->merchantId(),
            'merchantTransactionId' => $txnId,
            'merchantUserId' => $this->getModeConfig('merchant_user_id') ?? ('MUID' . substr(md5((string) ($request->getEmail() ?? $txnId)), 0, 12)),
            'amount' => (int) round($request->getAmount() * 100),
            'redirectUrl' => $redirectUrl,
            'redirectMode' => 'POST',
            'callbackUrl' => $request->getNotifyUrl() ?? $redirectUrl,
            'mobileNumber' => isset($customer['phone']) ? preg_replace('/\D/', '', (string) $customer['phone']) : null,
            'paymentInstrument' => ['type' => 'PAY_PAGE'],
        ], fn ($v) => $v !== null);

        $result = $this->post('/pg/v1/pay', $payload);
        $url = $result['data']['instrumentResponse']['redirectInfo']['url'] ?? null;

        if (!($result['success'] ?? false) || !$url) {
            throw new PaymentException('PhonePe: ' . ($result['message'] ?? $result['code'] ?? 'failed to initiate payment'));
        }

        return PaymentResponse::redirect($url, [
            'transaction_id' => $txnId,
            'message' => 'Redirect to PhonePe',
        ]);
    }

    /**
     * Check status with the Status API. Accepts merchantTransactionId /
     * transactionId (redirect POST) / transaction_id / order_id.
     */
    public function verify(array $payload): PaymentResponse
    {
        $txnId = $payload['merchantTransactionId'] ?? $payload['transactionId'] ?? $payload['transaction_id'] ?? $payload['order_id'] ?? null;
        if (!$txnId) {
            throw new PaymentException('PhonePe merchantTransactionId not found in payload');
        }

        $mid = $this->merchantId();
        $path = "/pg/v1/status/{$mid}/" . rawurlencode($txnId);

        $response = Http::timeout(30)->acceptJson()->withHeaders([
            'X-VERIFY' => $this->xVerify($path),
            'X-MERCHANT-ID' => $mid,
        ])->get($this->baseUrl() . $path);

        $result = $response->json() ?? [];
        if (!$response->successful() && empty($result['code'])) {
            throw new PaymentException("PhonePe status API failed with HTTP {$response->status()}");
        }

        $code = $result['code'] ?? 'UNKNOWN';
        $data = $result['data'] ?? [];

        return new PaymentResponse(
            success: $code === 'PAYMENT_SUCCESS' && ($data['state'] ?? 'COMPLETED') === 'COMPLETED',
            transactionId: $txnId,
            message: $result['message'] ?? $code,
            status: match ($code) {
                'PAYMENT_SUCCESS' => 'completed',
                'PAYMENT_PENDING' => 'pending',
                default => 'failed',
            },
            data: [
                'code' => $code,
                'payment_instrument' => $data['paymentInstrument'] ?? null,
                'raw' => $result,
            ],
            gatewayReference: $data['transactionId'] ?? null,
            amount: isset($data['amount']) ? $data['amount'] / 100 : null,
            currency: 'INR'
        );
    }

    /**
     * Server-to-server callback: {"response": "<base64>"} with X-VERIFY header.
     */
    public function handleWebhook(array $payload, string $rawBody, array $headers): PaymentResponse
    {
        $encoded = (string) ($payload['response'] ?? '');
        $expected = hash('sha256', $encoded . $this->saltKey()) . '###' . $this->saltIndex();

        if ($encoded === '' || !hash_equals($expected, $headers['x-verify'] ?? '')) {
            throw new InvalidSignatureException('Invalid PhonePe X-VERIFY', gateway: 'phonepe');
        }

        $decoded = json_decode(base64_decode($encoded), true) ?? [];

        return $this->verify(['merchantTransactionId' => $decoded['data']['merchantTransactionId'] ?? null]);
    }

    /**
     * Refund. $transactionId is the original merchantTransactionId.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        if ($amount <= 0) {
            $amount = (float) $this->verify(['merchantTransactionId' => $transactionId])->amount;
        }

        $result = $this->post('/pg/v1/refund', [
            'merchantId' => $this->merchantId(),
            'merchantUserId' => $this->getModeConfig('merchant_user_id') ?? 'MUID_REFUND',
            'originalTransactionId' => $transactionId,
            'merchantTransactionId' => 'RF' . Str::upper(Str::random(16)),
            'amount' => (int) round($amount * 100),
            'callbackUrl' => $this->getModeConfig('refund_callback_url') ?? url('/'),
        ]);

        if (($result['success'] ?? false) && in_array($result['code'] ?? null, ['PAYMENT_SUCCESS', 'PAYMENT_PENDING'], true)) {
            return true;
        }

        throw new PaymentException('PhonePe refund failed: ' . ($result['message'] ?? $result['code'] ?? 'unknown error'));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * X-VERIFY = sha256(base64Body . apiPath . saltKey) . "###" . saltIndex
     * (for GET requests base64Body is empty). The salt key itself is never sent.
     */
    public function xVerify(string $path, string $base64Body = ''): string
    {
        return hash('sha256', $base64Body . $path . $this->saltKey()) . '###' . $this->saltIndex();
    }

    private function post(string $path, array $payload): array
    {
        $encoded = base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $response = Http::timeout(30)->acceptJson()->withHeaders([
            'X-VERIFY' => $this->xVerify($path, $encoded),
        ])->post($this->baseUrl() . $path, ['request' => $encoded]);

        $json = $response->json() ?? [];
        if (!$response->successful() && empty($json['code'])) {
            throw new PaymentException("PhonePe API {$path} failed with HTTP {$response->status()}");
        }

        return $json;
    }

    private function merchantId(): string
    {
        return $this->getModeConfig('merchant_id') ?? $this->requireModeConfig('client_id');
    }

    private function saltKey(): string
    {
        return $this->getModeConfig('salt_key') ?? $this->requireModeConfig('secret_key');
    }

    private function saltIndex(): string
    {
        return (string) ($this->getModeConfig('salt_index') ?? $this->getModeConfig('key_index') ?? '1');
    }

    private function baseUrl(): string
    {
        return $this->getModeConfig('base_url') ?? ($this->isSandbox()
            ? 'https://api-preprod.phonepe.com/apis/pg-sandbox'
            : 'https://api.phonepe.com/apis/hermes');
    }
}
