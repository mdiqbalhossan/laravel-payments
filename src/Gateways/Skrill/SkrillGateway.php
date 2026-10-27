<?php

namespace Mdiqbal\LaravelPayments\Gateways\Skrill;

use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * Skrill Quick Checkout.
 *
 * Config (per mode): merchant_email (pay_to_email), secret_word (Settings >
 * Developer Settings), optional api_password (MQI) for status queries.
 * Skrill has no separate sandbox host; use a test merchant account.
 *
 * Flow: pay() prepares a session (prepare_only=1) and returns the checkout
 * URL. Skrill POSTs the result to status_url (notify URL); verify() checks
 * md5sig. Without md5sig, verify() queries the MQI API (needs api_password).
 * Refunds are not supported by this integration.
 */
class SkrillGateway extends AbstractGateway
{
    private const PAY_URL = 'https://pay.skrill.com';
    private const MQI_URL = 'https://www.skrill.com/app/query.pl';

    public function gatewayName(): string
    {
        return 'skrill';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $transactionId = $request->getTransactionId() ?? $this->generateOrderId();

        $fields = array_filter([
            'pay_to_email' => $this->requireModeConfig('merchant_email'),
            'transaction_id' => $transactionId,
            'amount' => number_format($request->getAmount(), 2, '.', ''),
            'currency' => strtoupper($request->getCurrency() ?: 'EUR'),
            'return_url' => $request->getReturnUrl(),
            'cancel_url' => $request->getCancelUrl(),
            'status_url' => $request->getNotifyUrl(),
            'pay_from_email' => $request->getEmail(),
            'language' => strtoupper($request->getMeta('language', 'EN')),
            'detail1_description' => 'Order',
            'detail1_text' => mb_substr($request->getDescription() ?? $transactionId, 0, 240),
            'prepare_only' => '1',
        ], fn ($v) => $v !== null);

        $response = Http::asForm()->timeout(30)->post(self::PAY_URL, $fields);
        $sid = trim($response->body());

        if (!$response->successful() || $sid === '' || str_starts_with($sid, '{') || str_starts_with($sid, '<')) {
            throw new PaymentException('Skrill: failed to prepare payment session (' . $response->status() . ')');
        }

        return PaymentResponse::redirect(self::PAY_URL . '/?sid=' . urlencode($sid), [
            'transaction_id' => $transactionId,
            'gateway_reference' => $sid,
            'message' => 'Redirect to Skrill',
        ]);
    }

    /**
     * Verify a status_url POST (md5sig) or, without md5sig, query MQI by transaction_id.
     */
    public function verify(array $payload): PaymentResponse
    {
        if (isset($payload['md5sig'])) {
            return $this->verifyStatusPost($payload);
        }

        $transactionId = $payload['transaction_id'] ?? null;
        if (!$transactionId) {
            throw new PaymentException('Skrill transaction_id not found in payload');
        }

        return $this->queryStatus($transactionId);
    }

    /**
     * md5sig = strtoupper(md5(merchant_id . transaction_id . strtoupper(md5(secret_word)) . mb_amount . mb_currency . status))
     */
    public function md5sig(array $payload): string
    {
        return strtoupper(md5(
            ($payload['merchant_id'] ?? '')
            . ($payload['transaction_id'] ?? '')
            . strtoupper(md5($this->requireModeConfig('secret_word')))
            . ($payload['mb_amount'] ?? '')
            . ($payload['mb_currency'] ?? '')
            . ($payload['status'] ?? '')
        ));
    }

    private function verifyStatusPost(array $payload): PaymentResponse
    {
        if (!hash_equals($this->md5sig($payload), strtoupper((string) $payload['md5sig']))) {
            throw new InvalidSignatureException('Invalid Skrill md5sig', gateway: 'skrill');
        }

        if (isset($payload['pay_to_email']) && strcasecmp($payload['pay_to_email'], $this->requireModeConfig('merchant_email')) !== 0) {
            throw new PaymentException('Skrill pay_to_email does not match the configured merchant');
        }

        return $this->toResponse($payload);
    }

    private function queryStatus(string $transactionId): PaymentResponse
    {
        $response = Http::timeout(30)->get(self::MQI_URL, [
            'action' => 'status_trn',
            'email' => $this->requireModeConfig('merchant_email'),
            'password' => md5($this->requireModeConfig('api_password')),
            'trn_id' => $transactionId,
        ]);

        $lines = preg_split('/\r?\n/', trim($response->body()));
        if (!$response->successful() || !str_starts_with($lines[0] ?? '', '200') || !isset($lines[1])) {
            throw new PaymentException('Skrill MQI status query failed: ' . ($lines[0] ?? $response->status()));
        }

        parse_str($lines[1], $data);

        return $this->toResponse($data + ['transaction_id' => $transactionId]);
    }

    private function toResponse(array $data): PaymentResponse
    {
        $status = (string) ($data['status'] ?? '');

        return new PaymentResponse(
            success: $status === '2',
            transactionId: $data['transaction_id'] ?? null,
            message: "Skrill status: {$status}",
            status: match ($status) {
                '2' => 'completed',
                '0' => 'pending',
                '-1' => 'canceled',
                '-3' => 'chargeback',
                default => 'failed',
            },
            data: ['raw' => $data],
            gatewayReference: $data['mb_transaction_id'] ?? null,
            amount: isset($data['amount']) ? (float) $data['amount'] : (isset($data['mb_amount']) ? (float) $data['mb_amount'] : null),
            currency: $data['currency'] ?? $data['mb_currency'] ?? null
        );
    }
}
