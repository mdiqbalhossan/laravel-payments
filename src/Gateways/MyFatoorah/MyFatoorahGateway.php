<?php

namespace Mdiqbal\LaravelPayments\Gateways\MyFatoorah;

use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * MyFatoorah (API v2, SendPayment / GetPaymentStatus / MakeRefund).
 *
 * Live accounts are regional: set payments.gateways.myfatoorah.country to
 * SA, QA or EG (default: KW/AE/BH/JO/OM host), or set base_url directly.
 *
 * Flow: pay() returns the invoice URL; MyFatoorah redirects to the callback
 * URL with ?paymentId=...; pass it to verify(), which reads GetPaymentStatus.
 */
class MyFatoorahGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'myfatoorah';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $callback = $request->getReturnUrl();
        if (!$callback) {
            throw new PaymentException('MyFatoorah requires a return (callback) URL');
        }

        $customer = $request->getCustomer() ?? [];

        $body = array_filter([
            'InvoiceValue' => round($request->getAmount(), 3),
            'DisplayCurrencyIso' => strtoupper($request->getCurrency() ?: 'KWD'),
            'CustomerName' => $customer['name'] ?? 'Customer',
            'CustomerEmail' => $request->getEmail(),
            'CustomerMobile' => $customer['phone'] ?? null,
            'NotificationOption' => 'LNK',
            'CallBackUrl' => $callback,
            'ErrorUrl' => $request->getCancelUrl() ?? $callback,
            'CustomerReference' => $request->getTransactionId(),
            'Language' => $request->getMeta('language', 'en'),
            'WebhookUrl' => $request->getNotifyUrl(),
        ], fn ($v) => $v !== null);

        $data = $this->api('/v2/SendPayment', $body);

        if (empty($data['InvoiceURL'])) {
            throw new PaymentException('MyFatoorah did not return an invoice URL');
        }

        return PaymentResponse::redirect($data['InvoiceURL'], [
            'transaction_id' => $request->getTransactionId(),
            'gateway_reference' => (string) $data['InvoiceId'],
            'invoice_id' => $data['InvoiceId'],
            'message' => 'Redirect to MyFatoorah',
        ]);
    }

    /**
     * Read the invoice status. Accepts paymentId (callback) or invoice_id/InvoiceId.
     */
    public function verify(array $payload): PaymentResponse
    {
        if ($key = $payload['paymentId'] ?? $payload['payment_id'] ?? null) {
            $keyType = 'PaymentId';
        } elseif ($key = $payload['invoice_id'] ?? $payload['InvoiceId'] ?? $payload['Id'] ?? null) {
            $keyType = 'InvoiceId';
        } else {
            throw new PaymentException('MyFatoorah paymentId or invoice id not found in payload');
        }

        $data = $this->api('/v2/GetPaymentStatus', ['Key' => (string) $key, 'KeyType' => $keyType]);
        $status = $data['InvoiceStatus'] ?? 'Unknown';

        $paidTx = null;
        foreach ($data['InvoiceTransactions'] ?? [] as $tx) {
            if (($tx['TransactionStatus'] ?? null) === 'Succss' || ($tx['TransactionStatus'] ?? null) === 'Success') {
                $paidTx = $tx;
            }
        }

        return new PaymentResponse(
            success: $status === 'Paid',
            transactionId: $data['CustomerReference'] ?? (string) ($data['InvoiceId'] ?? $key),
            message: "MyFatoorah invoice status: {$status}",
            status: match ($status) {
                'Paid' => 'completed',
                'Pending' => 'pending',
                'Canceled' => 'canceled',
                default => 'failed',
            },
            data: [
                'invoice_id' => $data['InvoiceId'] ?? null,
                'payment_id' => $paidTx['PaymentId'] ?? null,
                'payment_gateway' => $paidTx['PaymentGateway'] ?? null,
                'raw' => $data,
            ],
            gatewayReference: isset($data['InvoiceId']) ? (string) $data['InvoiceId'] : null,
            amount: isset($data['InvoiceValue']) ? (float) $data['InvoiceValue'] : null,
            currency: $paidTx['Currency'] ?? $this->getModeConfig('currency')
        );
    }

    /**
     * Webhook: when webhook_secret is set, MyFatoorah-Signature must be
     * base64(hmac_sha256("Key=Value,..." of the alphabetically sorted Data fields)).
     * The invoice is then always re-read from the API.
     */
    public function handleWebhook(array $payload, string $rawBody, array $headers): PaymentResponse
    {
        $data = $payload['Data'] ?? [];

        if ($secret = $this->getModeConfig('webhook_secret')) {
            ksort($data, SORT_STRING | SORT_FLAG_CASE);
            $pairs = [];
            foreach ($data as $k => $v) {
                $pairs[] = $k . '=' . (is_scalar($v) || $v === null ? (string) $v : json_encode($v));
            }
            $expected = base64_encode(hash_hmac('sha256', implode(',', $pairs), $secret, true));

            if (!hash_equals($expected, $headers['myfatoorah-signature'] ?? '')) {
                throw new InvalidSignatureException('Invalid MyFatoorah webhook signature', gateway: 'myfatoorah');
            }
        }

        return $this->verify(['invoice_id' => $data['InvoiceId'] ?? null]);
    }

    /**
     * Refund. $transactionId is the MyFatoorah InvoiceId.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        if ($amount <= 0) {
            $amount = (float) $this->verify(['invoice_id' => $transactionId])->amount;
        }

        $this->api('/v2/MakeRefund', [
            'Key' => $transactionId,
            'KeyType' => 'InvoiceId',
            'RefundChargeOnCustomer' => false,
            'ServiceChargeOnCustomer' => false,
            'Amount' => round($amount, 3),
            'Comment' => 'Refund requested',
        ]);

        return true;
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    private function api(string $path, array $body): array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->withToken($this->requireModeConfig('api_key'))
            ->post($this->baseUrl() . $path, $body);

        $json = $response->json() ?? [];

        if (!$response->successful() || !($json['IsSuccess'] ?? false)) {
            $error = $json['ValidationErrors'][0]['Error'] ?? $json['Message'] ?? "HTTP {$response->status()}";
            throw new PaymentException("MyFatoorah: {$error}");
        }

        return $json['Data'] ?? [];
    }

    private function baseUrl(): string
    {
        if ($url = $this->getModeConfig('base_url')) {
            return rtrim($url, '/');
        }

        if ($this->isSandbox()) {
            return 'https://apitest.myfatoorah.com';
        }

        return match (strtoupper((string) $this->getModeConfig('country'))) {
            'SA' => 'https://api-sa.myfatoorah.com',
            'QA' => 'https://api-qa.myfatoorah.com',
            'EG' => 'https://api-eg.myfatoorah.com',
            default => 'https://api.myfatoorah.com',
        };
    }
}
