<?php

namespace Mdiqbal\LaravelPayments\Gateways\Payfast;

use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * PayFast (South Africa) custom integration.
 *
 * Config (per mode): merchant_id, merchant_key, pass_phrase (if set in the
 * PayFast dashboard).
 *
 * Flow: pay() returns the process URL plus signed form fields
 * (data['form_params']) that must be POSTed to it. PayFast then sends an ITN
 * to notify_url; verify()/the webhook route check the signature, confirm the
 * ITN with PayFast's validate endpoint and require payment_status COMPLETE.
 * Refunds are not supported by this integration.
 */
class PayfastGateway extends AbstractGateway
{
    /**
     * Field order matters for the signature.
     */
    private const FIELD_ORDER = [
        'merchant_id', 'merchant_key', 'return_url', 'cancel_url', 'notify_url',
        'name_first', 'name_last', 'email_address', 'cell_number',
        'm_payment_id', 'amount', 'item_name', 'item_description',
        'custom_int1', 'custom_int2', 'custom_int3', 'custom_int4', 'custom_int5',
        'custom_str1', 'custom_str2', 'custom_str3', 'custom_str4', 'custom_str5',
        'email_confirmation', 'confirmation_address', 'payment_method',
    ];

    public function gatewayName(): string
    {
        return 'payfast';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        if (strtoupper($request->getCurrency() ?: 'ZAR') !== 'ZAR') {
            throw new PaymentException('PayFast only supports ZAR');
        }

        if (!$request->getReturnUrl() || !$request->getNotifyUrl()) {
            throw new PaymentException('PayFast requires return and notify (ITN) URLs');
        }

        $customer = $request->getCustomer() ?? [];
        [$first, $last] = array_pad(preg_split('/\s+/', trim($customer['name'] ?? ''), 2), 2, null);

        $fields = [
            'merchant_id' => $this->requireModeConfig('merchant_id'),
            'merchant_key' => $this->requireModeConfig('merchant_key'),
            'return_url' => $request->getReturnUrl(),
            'cancel_url' => $request->getCancelUrl() ?? $request->getReturnUrl(),
            'notify_url' => $request->getNotifyUrl(),
            'name_first' => $first ?: null,
            'name_last' => $last,
            'email_address' => $request->getEmail(),
            'm_payment_id' => $request->getTransactionId() ?? $this->generateOrderId(),
            'amount' => number_format($request->getAmount(), 2, '.', ''),
            'item_name' => mb_substr($request->getDescription() ?? 'Payment', 0, 100),
        ];

        $fields = $this->ordered($fields);
        $fields['signature'] = $this->signature($fields);

        return PaymentResponse::redirect($this->host() . '/eng/process', [
            'transaction_id' => $fields['m_payment_id'],
            'redirect_method' => 'POST',
            'form_params' => $fields,
            'message' => 'POST form_params to PayFast',
        ]);
    }

    /**
     * Validate an ITN (the POST PayFast sends to notify_url).
     */
    public function verify(array $payload): PaymentResponse
    {
        if (!isset($payload['signature'], $payload['m_payment_id'])) {
            throw new PaymentException('Not a PayFast ITN payload');
        }

        $received = $payload;
        unset($received['signature']);

        if (!hash_equals($this->signature($received), (string) $payload['signature'])) {
            throw new InvalidSignatureException('Invalid PayFast ITN signature', gateway: 'payfast');
        }

        if (($payload['merchant_id'] ?? null) !== (string) $this->requireModeConfig('merchant_id')) {
            throw new PaymentException('PayFast ITN merchant_id mismatch');
        }

        $validation = Http::asForm()->timeout(30)->post($this->host() . '/eng/query/validate', $received);
        if (trim($validation->body()) !== 'VALID') {
            throw new PaymentException('PayFast did not confirm the ITN');
        }

        $status = $payload['payment_status'] ?? 'UNKNOWN';

        return new PaymentResponse(
            success: $status === 'COMPLETE',
            transactionId: $payload['m_payment_id'],
            message: "PayFast payment status: {$status}",
            status: match ($status) {
                'COMPLETE' => 'completed',
                'CANCELLED' => 'canceled',
                default => 'failed',
            },
            data: ['raw' => $payload],
            gatewayReference: $payload['pf_payment_id'] ?? null,
            amount: isset($payload['amount_gross']) ? (float) $payload['amount_gross'] : null,
            currency: 'ZAR'
        );
    }

    /**
     * PayFast signature: md5 of "key=urlencode(value)&..." (non-empty fields,
     * in the given order) plus "&passphrase=..." when a passphrase is set.
     */
    public function signature(array $fields): string
    {
        $pairs = [];
        foreach ($fields as $key => $value) {
            if ($value !== null && $value !== '') {
                $pairs[] = $key . '=' . urlencode(trim((string) $value));
            }
        }

        if ($passphrase = $this->getModeConfig('pass_phrase')) {
            $pairs[] = 'passphrase=' . urlencode(trim($passphrase));
        }

        return md5(implode('&', $pairs));
    }

    private function ordered(array $fields): array
    {
        $ordered = [];
        foreach (self::FIELD_ORDER as $key) {
            if (isset($fields[$key]) && $fields[$key] !== '') {
                $ordered[$key] = $fields[$key];
            }
        }

        return $ordered;
    }

    private function host(): string
    {
        return $this->isSandbox() ? 'https://sandbox.payfast.co.za' : 'https://www.payfast.co.za';
    }
}
