<?php

namespace Mdiqbal\LaravelPayments\Gateways\Sslcommerz;

use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * SSLCommerz hosted checkout (API v4).
 *
 * Flow: pay() returns GatewayPageURL to redirect to. SSLCommerz then POSTs the
 * customer back to success/fail/cancel URLs and sends an IPN. Call verify()
 * with that POST data (it must contain val_id, or at least tran_id): the
 * transaction is always confirmed with the SSLCommerz validation API.
 */
class SslcommerzGateway extends AbstractGateway
{
    protected array $supportedCurrencies = [
        'BDT', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'MYR', 'INR',
        'THB', 'AED', 'SAR', 'QAR', 'OMR', 'KWD', 'BHD', 'JPY', 'CHF',
    ];

    public function gatewayName(): string
    {
        return 'sslcommerz';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $currency = strtoupper($request->getCurrency() ?: 'BDT');

        if (!in_array($currency, $this->supportedCurrencies, true)) {
            throw new PaymentException("Currency {$currency} is not supported by SSLCommerz");
        }

        $tranId = $request->getTransactionId();
        if (!$tranId) {
            throw new PaymentException('SSLCommerz requires an order/transaction id (tran_id)');
        }

        $email = $request->getEmail();
        if (!$email) {
            throw new PaymentException('SSLCommerz requires a customer email');
        }

        $successUrl = $request->getReturnUrl() ?? $this->getModeConfig('success_url');
        $failUrl = $this->getModeConfig('fail_url') ?? $request->getCancelUrl() ?? $successUrl;
        $cancelUrl = $request->getCancelUrl() ?? $this->getModeConfig('cancel_url') ?? $failUrl;

        if (!$successUrl || !$failUrl || !$cancelUrl) {
            throw new PaymentException('SSLCommerz requires return (success), fail and cancel URLs');
        }

        $customer = $request->getCustomer() ?? [];
        $metadata = $request->getMetadata();

        $data = [
            'store_id' => $this->requireModeConfig('store_id'),
            'store_passwd' => $this->requireModeConfig('store_password'),
            'total_amount' => number_format($request->getAmount(), 2, '.', ''),
            'currency' => $currency,
            'tran_id' => $tranId,
            'success_url' => $successUrl,
            'fail_url' => $failUrl,
            'cancel_url' => $cancelUrl,
            'ipn_url' => $request->getNotifyUrl() ?? $this->getModeConfig('ipn_url'),
            'cus_name' => $customer['name'] ?? 'Customer',
            'cus_email' => $email,
            'cus_phone' => $customer['phone'] ?? $metadata['phone'] ?? '',
            'cus_add1' => $customer['address'] ?? 'N/A',
            'cus_city' => $customer['city'] ?? 'Dhaka',
            'cus_postcode' => $customer['postal_code'] ?? '1000',
            'cus_country' => $customer['country'] ?? 'Bangladesh',
            'shipping_method' => 'NO',
            'num_of_item' => 1,
            'product_name' => $request->getDescription() ?? 'Payment',
            'product_category' => $metadata['category'] ?? 'General',
            'product_profile' => $metadata['product_profile'] ?? 'general',
        ];

        if (!empty($metadata['multi_card_name'])) {
            $data['multi_card_name'] = $metadata['multi_card_name'];
        }

        foreach (['value_a', 'value_b', 'value_c', 'value_d'] as $field) {
            if (isset($metadata[$field])) {
                $data[$field] = (string) $metadata[$field];
            }
        }

        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

        $response = Http::asForm()->timeout(30)->post($this->baseUrl() . '/gwprocess/v4/api.php', $data);
        $result = $response->json() ?? [];

        if (($result['status'] ?? null) !== 'SUCCESS' || empty($result['GatewayPageURL'])) {
            $this->log('error', 'SSLCommerz session creation failed', [
                'tran_id' => $tranId,
                'reason' => $result['failedreason'] ?? $response->status(),
            ]);

            throw new PaymentException('SSLCommerz: ' . ($result['failedreason'] ?? 'failed to create payment session'));
        }

        return PaymentResponse::redirect($result['GatewayPageURL'], [
            'transaction_id' => $tranId,
            'gateway_reference' => $result['sessionkey'] ?? null,
            'session_key' => $result['sessionkey'] ?? null,
            'message' => 'Redirect to SSLCommerz payment page',
        ]);
    }

    /**
     * Confirm a payment with the SSLCommerz validation API.
     *
     * Uses val_id when present (success/IPN POST), otherwise looks the
     * transaction up by tran_id.
     */
    public function verify(array $payload): PaymentResponse
    {
        $valId = $payload['val_id'] ?? null;
        $tranId = $payload['tran_id'] ?? $payload['transaction_id'] ?? null;

        if ($valId) {
            $result = $this->validator('/validator/api/validationserverAPI.php', ['val_id' => $valId]);

            if ($tranId !== null && ($result['tran_id'] ?? null) !== $tranId) {
                throw new PaymentException('SSLCommerz validation tran_id does not match the callback');
            }
        } elseif ($tranId) {
            $result = $this->findValidTransaction($tranId);
        } else {
            throw new PaymentException('SSLCommerz verification needs val_id or tran_id');
        }

        return $this->toResponse($result ?? [], $tranId);
    }

    /**
     * Handle an SSLCommerz IPN: checks verify_sign, then validates val_id.
     */
    public function handleWebhook(array $payload, string $rawBody, array $headers): PaymentResponse
    {
        if (!$this->validIpnHash($payload)) {
            throw new InvalidSignatureException('Invalid SSLCommerz IPN signature', gateway: 'sslcommerz');
        }

        return $this->verify($payload);
    }

    /**
     * Refund. $transactionId may be the bank_tran_id or your tran_id (the
     * bank_tran_id is then looked up). Refunds are processed asynchronously.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        $bankTranId = $transactionId;

        $found = $this->findValidTransaction($transactionId);
        if ($found && !empty($found['bank_tran_id'])) {
            $bankTranId = $found['bank_tran_id'];
            if ($amount <= 0) {
                $amount = (float) ($found['amount'] ?? 0);
            }
        }

        if ($amount <= 0) {
            throw new PaymentException('SSLCommerz refund needs a positive amount');
        }

        $result = $this->validator('/validator/api/merchantTransIDvalidationAPI.php', [
            'bank_tran_id' => $bankTranId,
            'refund_amount' => number_format($amount, 2, '.', ''),
            'refund_remarks' => 'Refund requested',
        ]);

        $this->log('info', 'SSLCommerz refund requested', [
            'bank_tran_id' => $bankTranId,
            'amount' => $amount,
            'status' => $result['status'] ?? null,
            'refund_ref_id' => $result['refund_ref_id'] ?? null,
        ]);

        if (($result['APIConnect'] ?? null) === 'DONE' && in_array($result['status'] ?? null, ['success', 'processing'], true)) {
            return true;
        }

        throw new PaymentException('SSLCommerz refund failed: ' . ($result['errorReason'] ?? $result['status'] ?? 'unknown error'));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * Look up a transaction by tran_id and return its VALID/VALIDATED entry.
     */
    public function findValidTransaction(string $tranId): ?array
    {
        $result = $this->validator('/validator/api/merchantTransIDvalidationAPI.php', ['tran_id' => $tranId]);

        foreach ($result['element'] ?? [] as $element) {
            if (in_array($element['status'] ?? null, ['VALID', 'VALIDATED'], true)) {
                return $element;
            }
        }

        return $result['element'][0] ?? null;
    }

    public function getSupportedCurrencies(): array
    {
        return $this->supportedCurrencies;
    }

    /**
     * Verify the IPN verify_sign/verify_key hash.
     */
    public function validIpnHash(array $payload): bool
    {
        $sign = $payload['verify_sign'] ?? '';
        $keys = $payload['verify_key'] ?? '';
        $password = $this->getModeConfig('store_password');

        if ($sign === '' || $keys === '' || !$password) {
            return false;
        }

        $data = [];
        foreach (explode(',', $keys) as $key) {
            $data[$key] = $payload[$key] ?? '';
        }
        $data['store_passwd'] = md5($password);
        ksort($data);

        $string = '';
        foreach ($data as $key => $value) {
            $string .= $key . '=' . $value . '&';
        }

        return hash_equals(md5(rtrim($string, '&')), $sign);
    }

    protected function toResponse(array $result, ?string $tranId): PaymentResponse
    {
        $status = $result['status'] ?? 'UNKNOWN';
        $success = in_array($status, ['VALID', 'VALIDATED'], true);

        // currency_type/currency_amount are what the customer was charged in;
        // amount/currency are the BDT settlement values
        $currency = $result['currency_type'] ?? $result['currency'] ?? null;
        $amount = $result['currency_amount'] ?? $result['amount'] ?? null;

        return new PaymentResponse(
            success: $success,
            transactionId: $result['tran_id'] ?? $tranId,
            message: $success ? 'Payment validated' : "SSLCommerz status: {$status}",
            status: $success ? 'completed' : strtolower($status),
            data: [
                'val_id' => $result['val_id'] ?? null,
                'bank_tran_id' => $result['bank_tran_id'] ?? null,
                'card_type' => $result['card_type'] ?? null,
                'store_amount' => $result['store_amount'] ?? null,
                'risk_level' => $result['risk_level'] ?? null,
                'risk_title' => $result['risk_title'] ?? null,
                'validation' => $result,
            ],
            gatewayReference: $result['bank_tran_id'] ?? null,
            amount: $amount !== null ? (float) $amount : null,
            currency: $currency
        );
    }

    protected function validator(string $path, array $query): array
    {
        $query += [
            'store_id' => $this->requireModeConfig('store_id'),
            'store_passwd' => $this->requireModeConfig('store_password'),
            'format' => 'json',
            'v' => 1,
        ];

        $response = Http::timeout(30)->get($this->baseUrl() . $path, $query);

        if (!$response->successful()) {
            throw new PaymentException('SSLCommerz API request failed with HTTP ' . $response->status());
        }

        return $response->json() ?? [];
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox() ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
    }
}
