<?php

namespace Mdiqbal\LaravelPayments\Gateways\Flutterwave;

use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * Flutterwave Standard (v3 REST API).
 *
 * Flow: pay() returns the hosted checkout link. Flutterwave redirects back
 * with ?status=...&tx_ref=...&transaction_id=...; pass that to verify(),
 * which re-verifies the transaction with the API. Webhooks are authenticated
 * with the "verif-hash" header, which must equal the configured secret hash.
 */
class FlutterwaveGateway extends AbstractGateway
{
    private const BASE_URL = 'https://api.flutterwave.com/v3';

    public function gatewayName(): string
    {
        return 'flutterwave';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $email = $request->getEmail();
        if (!$email) {
            throw new PaymentException('Flutterwave requires a customer email');
        }

        $redirectUrl = $request->getReturnUrl() ?? $this->getModeConfig('redirect_url');
        if (!$redirectUrl) {
            throw new PaymentException('Flutterwave requires a return (redirect) URL');
        }

        $txRef = $request->getTransactionId() ?? $this->generateOrderId();
        $customer = $request->getCustomer() ?? [];
        $metadata = $request->getMetadata();

        $body = [
            'tx_ref' => $txRef,
            'amount' => $request->getAmount(),
            'currency' => strtoupper($request->getCurrency() ?: 'NGN'),
            'redirect_url' => $redirectUrl,
            'customer' => array_filter([
                'email' => $email,
                'name' => $customer['name'] ?? null,
                'phonenumber' => $customer['phone'] ?? null,
            ]),
            'customizations' => $metadata['customizations'] ?? array_filter([
                'title' => config('app.name'),
                'description' => $request->getDescription(),
            ]),
            'meta' => ['order_id' => $txRef],
        ];

        foreach (['payment_options', 'subaccounts', 'payment_plan'] as $key) {
            if (isset($metadata[$key])) {
                $body[$key] = $metadata[$key];
            }
        }

        $result = $this->api('post', '/payments', $body);

        if (($result['status'] ?? null) !== 'success' || empty($result['data']['link'])) {
            throw new PaymentException('Flutterwave: ' . ($result['message'] ?? 'failed to create payment'));
        }

        return PaymentResponse::redirect($result['data']['link'], [
            'transaction_id' => $txRef,
            'message' => 'Redirect to Flutterwave checkout',
        ]);
    }

    /**
     * Verify via the API. Accepts transaction_id (preferred) and/or tx_ref.
     */
    public function verify(array $payload): PaymentResponse
    {
        $id = $payload['transaction_id'] ?? $payload['data']['id'] ?? null;
        $txRef = $payload['tx_ref'] ?? $payload['data']['tx_ref'] ?? null;

        if (($payload['status'] ?? null) === 'cancelled' && !$id) {
            return new PaymentResponse(success: false, transactionId: $txRef, status: 'canceled', message: 'Payment cancelled');
        }

        if ($id) {
            $result = $this->api('get', '/transactions/' . rawurlencode((string) $id) . '/verify');
        } elseif ($txRef) {
            $result = $this->api('get', '/transactions/verify_by_reference', ['tx_ref' => $txRef]);
        } else {
            throw new PaymentException('Flutterwave verification needs transaction_id or tx_ref');
        }

        $data = $result['data'] ?? [];

        if ($txRef !== null && isset($data['tx_ref']) && $data['tx_ref'] !== $txRef) {
            throw new PaymentException('Flutterwave transaction does not match tx_ref');
        }

        $status = $data['status'] ?? 'unknown';

        return new PaymentResponse(
            success: $status === 'successful',
            transactionId: $data['tx_ref'] ?? $txRef,
            message: $data['processor_response'] ?? $result['message'] ?? null,
            status: match ($status) {
                'successful' => 'completed',
                'pending' => 'pending',
                'cancelled' => 'canceled',
                default => 'failed',
            },
            data: [
                'flw_ref' => $data['flw_ref'] ?? null,
                'payment_type' => $data['payment_type'] ?? null,
                'charged_amount' => $data['charged_amount'] ?? null,
                'app_fee' => $data['app_fee'] ?? null,
                'customer' => $data['customer'] ?? null,
                'raw' => $data,
            ],
            gatewayReference: isset($data['id']) ? (string) $data['id'] : null,
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            currency: $data['currency'] ?? null
        );
    }

    /**
     * Webhook: "verif-hash" must equal the secret hash from the dashboard
     * (config key webhook_secret / secret_hash); then re-verify via API.
     */
    public function handleWebhook(array $payload, string $rawBody, array $headers): PaymentResponse
    {
        $secretHash = $this->getModeConfig('secret_hash') ?? $this->getModeConfig('webhook_secret');
        if (!$secretHash) {
            throw PaymentException::configuration('flutterwave', 'secret_hash (webhook_secret) is required to accept Flutterwave webhooks');
        }

        $received = $headers['verif-hash'] ?? '';
        if ($received === '' || !hash_equals($secretHash, $received)) {
            throw new InvalidSignatureException('Invalid Flutterwave verif-hash', gateway: 'flutterwave');
        }

        return $this->verify(['transaction_id' => $payload['data']['id'] ?? null, 'tx_ref' => $payload['data']['tx_ref'] ?? null]);
    }

    /**
     * Refund. $transactionId may be the Flutterwave transaction id or your tx_ref.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        $id = ctype_digit($transactionId)
            ? $transactionId
            : $this->verify(['tx_ref' => $transactionId])->gatewayReference;

        if (!$id) {
            throw new PaymentException('Flutterwave refund: transaction not found');
        }

        $result = $this->api('post', "/transactions/{$id}/refund", $amount > 0 ? ['amount' => $amount] : []);

        if (($result['status'] ?? null) === 'success') {
            return true;
        }

        throw new PaymentException('Flutterwave refund failed: ' . ($result['message'] ?? 'unknown error'));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    private function api(string $method, string $path, array $data = []): array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->withToken($this->requireModeConfig('secret_key'))
            ->{$method}(self::BASE_URL . $path, $data);

        $json = $response->json() ?? [];

        if (!$response->successful()) {
            throw new PaymentException('Flutterwave: ' . ($json['message'] ?? "HTTP {$response->status()}"));
        }

        return $json;
    }
}
