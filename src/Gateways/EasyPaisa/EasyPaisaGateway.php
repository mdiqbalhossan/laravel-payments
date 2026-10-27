<?php

namespace Mdiqbal\LaravelPayments\Gateways\EasyPaisa;

use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * Easypaisa "Easypay" hosted checkout (Pakistan).
 *
 * Config (per mode): store_id, hash_key, plus username, password and
 * account_num for the Inquire Transaction API.
 *
 * Flow: pay() returns the Easypay Index.jsf URL and form fields
 * (data['form_params'], incl. merchantHashedReq) to POST. Easypay
 * redirects to postBackURL with auth_token; POST it to the Confirm.jsf URL
 * in data['confirm_url'] together with postBackURL. Finally verify() calls
 * the Inquire Transaction API (transactionStatus PAID = success).
 * Refunds are not supported by this integration.
 */
class EasyPaisaGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'easypaisa';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        if (strtoupper($request->getCurrency() ?: 'PKR') !== 'PKR') {
            throw new PaymentException('Easypaisa only supports PKR');
        }

        $postBack = $request->getReturnUrl();
        if (!$postBack) {
            throw new PaymentException('Easypaisa requires a return (postBackURL) URL');
        }

        $customer = $request->getCustomer() ?? [];
        $orderRef = $request->getTransactionId() ?? $this->generateOrderId();

        $fields = array_filter([
            'amount' => number_format($request->getAmount(), 1, '.', ''),
            'autoRedirect' => '1',
            'emailAddr' => $request->getEmail(),
            'expiryDate' => now()->addDay()->format('Ymd His'),
            'mobileNum' => $customer['phone'] ?? null,
            'orderRefNum' => $orderRef,
            'paymentMethod' => $request->getMeta('payment_method', 'MA_PAYMENT_METHOD'),
            'postBackURL' => $postBack,
            'storeId' => (string) $this->requireModeConfig('store_id'),
        ], fn ($v) => $v !== null && $v !== '');

        ksort($fields);
        $fields['merchantHashedReq'] = $this->hashRequest($fields);

        return PaymentResponse::redirect($this->host() . '/easypay/Index.jsf', [
            'transaction_id' => $orderRef,
            'redirect_method' => 'POST',
            'form_params' => $fields,
            'confirm_url' => $this->host() . '/easypay/Confirm.jsf',
            'message' => 'POST form_params to Easypay',
        ]);
    }

    /**
     * Inquire the transaction. Accepts orderRefNumber / orderRefNum / order_id.
     */
    public function verify(array $payload): PaymentResponse
    {
        $orderRef = $payload['orderRefNumber'] ?? $payload['orderRefNum'] ?? $payload['order_id'] ?? $payload['transaction_id'] ?? null;
        if (!$orderRef) {
            throw new PaymentException('Easypaisa orderRefNumber not found in payload');
        }

        $response = Http::timeout(30)
            ->acceptJson()
            ->withHeaders([
                'Credentials' => base64_encode($this->requireModeConfig('username') . ':' . $this->requireModeConfig('password')),
            ])
            ->post($this->host() . '/easypay-service/rest/v4/inquire-transaction', [
                'orderId' => $orderRef,
                'storeId' => (string) $this->requireModeConfig('store_id'),
                'accountNum' => (string) $this->requireModeConfig('account_num'),
            ]);

        $json = $response->json() ?? [];

        if (!$response->successful() || ($json['responseCode'] ?? null) !== '0000') {
            throw new PaymentException('Easypaisa inquiry failed: ' . ($json['responseDesc'] ?? "HTTP {$response->status()}"));
        }

        $status = $json['transactionStatus'] ?? 'UNKNOWN';

        return new PaymentResponse(
            success: $status === 'PAID',
            transactionId: $orderRef,
            message: "Easypaisa status: {$status}",
            status: match ($status) {
                'PAID' => 'completed',
                'PENDING' => 'pending',
                'REVERSED' => 'refunded',
                default => 'failed',
            },
            data: ['raw' => $json],
            gatewayReference: $json['transactionId'] ?? null,
            amount: isset($json['transactionAmount']) ? (float) $json['transactionAmount'] : null,
            currency: 'PKR'
        );
    }

    /**
     * merchantHashedReq = base64(AES-128-ECB("k=v&k=v..." sorted by key, hash_key))
     */
    public function hashRequest(array $fields): string
    {
        ksort($fields);
        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        $encrypted = openssl_encrypt(implode('&', $pairs), 'AES-128-ECB', $this->requireModeConfig('hash_key'), OPENSSL_RAW_DATA);

        return base64_encode((string) $encrypted);
    }

    private function host(): string
    {
        return $this->isSandbox() ? 'https://easypaystg.easypaisa.com.pk' : 'https://easypay.easypaisa.com.pk';
    }
}
