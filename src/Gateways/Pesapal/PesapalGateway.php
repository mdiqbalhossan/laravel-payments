<?php

namespace Mdiqbal\LaravelPayments\Gateways\Pesapal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * Pesapal API 3.0.
 *
 * Config (per mode): consumer_key, consumer_secret, ipn_id (register your IPN
 * URL once with RegisterIPN and store the returned ipn_id).
 *
 * Flow: pay() submits the order and returns redirect_url; the
 * order_tracking_id is the transaction id. Pesapal redirects/IPNs with
 * OrderTrackingId; verify() reads GetTransactionStatus (IPNs are unsigned,
 * so the API is always the source of truth).
 */
class PesapalGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'pesapal';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $callback = $request->getReturnUrl();
        if (!$callback) {
            throw new PaymentException('Pesapal requires a return (callback) URL');
        }

        $customer = $request->getCustomer() ?? [];
        $email = $request->getEmail();
        $phone = $customer['phone'] ?? null;

        if (!$email && !$phone) {
            throw new PaymentException('Pesapal requires a customer email or phone');
        }

        $result = $this->api('post', '/api/Transactions/SubmitOrderRequest', array_filter([
            'id' => $request->getTransactionId() ?? $this->generateOrderId(),
            'currency' => strtoupper($request->getCurrency() ?: 'KES'),
            'amount' => round($request->getAmount(), 2),
            'description' => mb_substr($request->getDescription() ?? 'Payment', 0, 100),
            'callback_url' => $callback,
            'cancellation_url' => $request->getCancelUrl(),
            'notification_id' => $this->requireModeConfig('ipn_id'),
            'billing_address' => array_filter([
                'email_address' => $email,
                'phone_number' => $phone,
                'first_name' => $customer['name'] ?? null,
            ]),
        ], fn ($v) => $v !== null));

        if (empty($result['redirect_url']) || empty($result['order_tracking_id'])) {
            throw new PaymentException('Pesapal: ' . ($result['error']['message'] ?? 'failed to submit order'));
        }

        return PaymentResponse::redirect($result['redirect_url'], [
            'transaction_id' => $result['order_tracking_id'],
            'gateway_reference' => $result['merchant_reference'] ?? $request->getTransactionId(),
            'message' => 'Redirect to Pesapal',
        ]);
    }

    /**
     * Accepts OrderTrackingId (redirect/IPN) or order_tracking_id / transaction_id.
     */
    public function verify(array $payload): PaymentResponse
    {
        $trackingId = $payload['OrderTrackingId'] ?? $payload['order_tracking_id'] ?? $payload['transaction_id'] ?? null;
        if (!$trackingId) {
            throw new PaymentException('Pesapal OrderTrackingId not found in payload');
        }

        $result = $this->api('get', '/api/Transactions/GetTransactionStatus', ['orderTrackingId' => $trackingId]);
        $code = (int) ($result['status_code'] ?? 0);

        return new PaymentResponse(
            success: $code === 1,
            transactionId: $trackingId,
            message: $result['payment_status_description'] ?? $result['description'] ?? null,
            status: match ($code) {
                1 => 'completed',
                2 => 'failed',
                3 => 'refunded',
                default => 'pending',
            },
            data: [
                'merchant_reference' => $result['merchant_reference'] ?? null,
                'confirmation_code' => $result['confirmation_code'] ?? null,
                'payment_method' => $result['payment_method'] ?? null,
                'raw' => $result,
            ],
            gatewayReference: $result['confirmation_code'] ?? null,
            amount: isset($result['amount']) ? (float) $result['amount'] : null,
            currency: $result['currency'] ?? null
        );
    }

    /**
     * Refund. $transactionId is the order_tracking_id.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        $status = $this->verify(['OrderTrackingId' => $transactionId]);

        if (!$status->success || !$status->gatewayReference) {
            throw new PaymentException('Pesapal refund: transaction is not completed');
        }

        $result = $this->api('post', '/api/Transactions/RefundRequest', [
            'confirmation_code' => $status->gatewayReference,
            'amount' => (string) round($amount > 0 ? $amount : (float) $status->amount, 2),
            'username' => $this->getModeConfig('refund_username') ?? 'api',
            'remarks' => 'Refund requested',
        ]);

        if ((string) ($result['status'] ?? '') === '200') {
            return true;
        }

        throw new PaymentException('Pesapal refund failed: ' . ($result['message'] ?? 'unknown error'));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    private function api(string $method, string $path, array $data = []): array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->withToken($this->token())
            ->{$method}($this->baseUrl() . $path, $data);

        $json = $response->json() ?? [];

        if (!$response->successful() || isset($json['error']['message'])) {
            throw new PaymentException('Pesapal: ' . ($json['error']['message'] ?? "HTTP {$response->status()}"));
        }

        return $json;
    }

    private function token(): string
    {
        $key = $this->requireModeConfig('consumer_key');

        return Cache::remember('payments.pesapal.token.' . $this->mode . '.' . md5($key), now()->addMinutes(4), function () use ($key) {
            $response = Http::timeout(30)->acceptJson()->post($this->baseUrl() . '/api/Auth/RequestToken', [
                'consumer_key' => $key,
                'consumer_secret' => $this->requireModeConfig('consumer_secret'),
            ]);

            $token = $response->json('token');
            if (!$response->successful() || !$token) {
                throw new PaymentException('Pesapal authentication failed: ' . ($response->json('error.message') ?? $response->status()));
            }

            return $token;
        });
    }

    private function baseUrl(): string
    {
        return $this->isSandbox() ? 'https://cybqa.pesapal.com/pesapalv3' : 'https://pay.pesapal.com/v3';
    }
}
