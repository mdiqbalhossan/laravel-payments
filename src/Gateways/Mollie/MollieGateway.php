<?php

namespace Mdiqbal\LaravelPayments\Gateways\Mollie;

use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * Mollie Payments API (v2).
 *
 * Flow: pay() returns the Mollie checkout URL; the Mollie payment id (tr_...)
 * is the transaction id. Mollie webhooks only POST id=tr_...; verify() always
 * fetches the payment from the API, so webhooks need no signature. Test vs
 * live is decided by the API key (test_... / live_...).
 */
class MollieGateway extends AbstractGateway
{
    private const BASE_URL = 'https://api.mollie.com/v2';

    public function gatewayName(): string
    {
        return 'mollie';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $currency = strtoupper($request->getCurrency() ?: 'EUR');
        $redirectUrl = $request->getReturnUrl() ?? $this->getModeConfig('redirect_url');

        if (!$redirectUrl) {
            throw new PaymentException('Mollie requires a return (redirect) URL');
        }

        $metadata = $request->getMetadata();

        $body = array_filter([
            'amount' => ['currency' => $currency, 'value' => $this->formatValue($request->getAmount(), $currency)],
            'description' => $request->getDescription() ?? ('Order ' . $request->getTransactionId()),
            'redirectUrl' => $redirectUrl,
            'cancelUrl' => $request->getCancelUrl(),
            'webhookUrl' => $request->getNotifyUrl() ?? $this->getModeConfig('webhook_url'),
            'metadata' => ['order_id' => $request->getTransactionId()],
            'method' => $metadata['method'] ?? null,
            'locale' => $metadata['locale'] ?? null,
        ], fn ($v) => $v !== null);

        $payment = $this->api('post', '/payments', $body);
        $checkout = $payment['_links']['checkout']['href'] ?? null;

        if (!$checkout) {
            throw new PaymentException('Mollie did not return a checkout URL');
        }

        return PaymentResponse::redirect($checkout, [
            'transaction_id' => $payment['id'],
            'gateway_reference' => $request->getTransactionId(),
            'status' => $payment['status'] ?? 'open',
            'message' => 'Redirect to Mollie checkout',
        ]);
    }

    /**
     * Fetch the payment from Mollie. Accepts id (webhook) / payment_id / transaction_id.
     */
    public function verify(array $payload): PaymentResponse
    {
        $id = $payload['id'] ?? $payload['payment_id'] ?? $payload['transaction_id'] ?? null;

        if (!$id || !str_starts_with((string) $id, 'tr_')) {
            throw new PaymentException('Mollie payment id (tr_...) not found in payload');
        }

        $payment = $this->api('get', '/payments/' . rawurlencode($id));
        $status = $payment['status'] ?? 'unknown';

        return new PaymentResponse(
            success: $status === 'paid',
            transactionId: $payment['id'],
            message: "Mollie status: {$status}",
            status: match ($status) {
                'paid' => 'completed',
                'open', 'pending', 'authorized' => 'pending',
                'canceled' => 'canceled',
                'expired' => 'expired',
                default => 'failed',
            },
            data: [
                'order_id' => $payment['metadata']['order_id'] ?? null,
                'method' => $payment['method'] ?? null,
                'amount_refunded' => $payment['amountRefunded']['value'] ?? null,
                'paid_at' => $payment['paidAt'] ?? null,
                'raw' => $payment,
            ],
            gatewayReference: $payment['metadata']['order_id'] ?? null,
            amount: isset($payment['amount']['value']) ? (float) $payment['amount']['value'] : null,
            currency: $payment['amount']['currency'] ?? null
        );
    }

    /**
     * Refund a payment (tr_...). An amount of 0 refunds the remaining amount.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        $payment = $this->api('get', '/payments/' . rawurlencode($transactionId));
        $currency = $payment['amount']['currency'];

        $value = $amount > 0
            ? $this->formatValue($amount, $currency)
            : ($payment['amountRemaining']['value'] ?? $payment['amount']['value']);

        $refund = $this->api('post', '/payments/' . rawurlencode($transactionId) . '/refunds', [
            'amount' => ['currency' => $currency, 'value' => $value],
        ]);

        return in_array($refund['status'] ?? null, ['queued', 'pending', 'processing', 'refunded'], true);
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    private function formatValue(float $amount, string $currency): string
    {
        return number_format($amount, $this->currencyDecimals($currency), '.', '');
    }

    private function api(string $method, string $path, array $data = []): array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->withToken($this->requireModeConfig('api_key'))
            ->{$method}(self::BASE_URL . $path, $data);

        $json = $response->json() ?? [];

        if (!$response->successful()) {
            throw new PaymentException('Mollie: ' . ($json['detail'] ?? "HTTP {$response->status()}"));
        }

        return $json;
    }
}
