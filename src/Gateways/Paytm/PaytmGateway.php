<?php

namespace Mdiqbal\LaravelPayments\Gateways\Paytm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * Paytm Payment Gateway (Initiate Transaction API + PaytmChecksum).
 *
 * Flow: pay() initiates a transaction and returns the showPaymentPage URL with
 * the form fields (mid, orderId, txnToken) that must be POSTed to it
 * (data['form_params']), or use data['txn_token'] with the JS Checkout.
 * Paytm POSTs back to the callback URL; pass that data to verify(), which
 * checks CHECKSUMHASH and confirms the status with the Order Status API.
 */
class PaytmGateway extends AbstractGateway
{
    private const IV = '@@@@&&&&####$$$$';

    public function gatewayName(): string
    {
        return 'paytm';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $mid = $this->requireModeConfig('merchant_id');
        $orderId = $request->getTransactionId() ?? $this->generateOrderId();
        $currency = strtoupper($request->getCurrency() ?: 'INR');

        if ($currency !== 'INR') {
            throw new PaymentException('Paytm only supports INR');
        }

        $callbackUrl = $request->getReturnUrl() ?? $this->getModeConfig('callback_url');
        if (!$callbackUrl) {
            throw new PaymentException('Paytm requires a callback (return) URL');
        }

        $customer = $request->getCustomer() ?? [];
        $userInfo = array_filter([
            'custId' => (string) ($request->getMeta('customer_id') ?? $request->getEmail() ?? 'CUST_' . $orderId),
            'email' => $request->getEmail(),
            'mobile' => $customer['phone'] ?? null,
            'firstName' => $customer['name'] ?? null,
        ]);

        $body = [
            'requestType' => 'Payment',
            'mid' => $mid,
            'websiteName' => $this->getModeConfig('website') ?? ($this->isSandbox() ? 'WEBSTAGING' : 'DEFAULT'),
            'orderId' => $orderId,
            'callbackUrl' => $callbackUrl,
            'txnAmount' => [
                'value' => number_format($request->getAmount(), 2, '.', ''),
                'currency' => 'INR',
            ],
            'userInfo' => $userInfo,
        ];

        $result = $this->api('/theia/api/v1/initiateTransaction', $body, ['mid' => $mid, 'orderId' => $orderId]);
        $info = $result['body']['resultInfo'] ?? [];

        if (($info['resultStatus'] ?? null) !== 'S' || empty($result['body']['txnToken'])) {
            throw new PaymentException('Paytm: ' . ($info['resultMsg'] ?? 'failed to initiate transaction'));
        }

        $token = $result['body']['txnToken'];
        $url = $this->baseUrl() . '/theia/api/v1/showPaymentPage?' . http_build_query(['mid' => $mid, 'orderId' => $orderId]);

        return PaymentResponse::redirect($url, [
            'transaction_id' => $orderId,
            'txn_token' => $token,
            'redirect_method' => 'POST',
            'form_params' => ['mid' => $mid, 'orderId' => $orderId, 'txnToken' => $token],
            'message' => 'POST form_params to the Paytm payment page',
        ]);
    }

    /**
     * Verify a callback (checks CHECKSUMHASH when present) and confirm the
     * status with the Order Status API. Accepts ORDERID / orderId / order_id.
     */
    public function verify(array $payload): PaymentResponse
    {
        if (isset($payload['CHECKSUMHASH'])) {
            $params = $payload;
            unset($params['CHECKSUMHASH']);

            if (!$this->verifyChecksum($params, (string) $payload['CHECKSUMHASH'])) {
                throw new InvalidSignatureException('Invalid Paytm checksum', gateway: 'paytm');
            }
        }

        $orderId = $payload['ORDERID'] ?? $payload['orderId'] ?? $payload['order_id'] ?? null;
        if (!$orderId) {
            throw new PaymentException('Paytm ORDERID not found in payload');
        }

        return $this->getTransactionStatus($orderId);
    }

    /**
     * Order Status API
     */
    public function getTransactionStatus(string $orderId): PaymentResponse
    {
        $result = $this->api('/v3/order/status', [
            'mid' => $this->requireModeConfig('merchant_id'),
            'orderId' => $orderId,
        ]);

        $body = $result['body'] ?? [];
        $status = $body['resultInfo']['resultStatus'] ?? 'UNKNOWN';

        return new PaymentResponse(
            success: $status === 'TXN_SUCCESS',
            transactionId: $orderId,
            message: $body['resultInfo']['resultMsg'] ?? null,
            status: match ($status) {
                'TXN_SUCCESS' => 'completed',
                'PENDING' => 'pending',
                default => 'failed',
            },
            data: [
                'txn_id' => $body['txnId'] ?? null,
                'bank_txn_id' => $body['bankTxnId'] ?? null,
                'payment_mode' => $body['paymentMode'] ?? null,
                'result_code' => $body['resultInfo']['resultCode'] ?? null,
                'raw' => $body,
            ],
            gatewayReference: $body['txnId'] ?? null,
            amount: isset($body['txnAmount']) ? (float) $body['txnAmount'] : null,
            currency: 'INR'
        );
    }

    /**
     * Refund. $transactionId is your orderId. Refunds are asynchronous; true
     * means Paytm accepted the request.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        $status = $this->getTransactionStatus($transactionId);
        if (!$status->success || !$status->gatewayReference) {
            throw new PaymentException('Paytm refund: transaction is not successful');
        }

        if ($amount <= 0) {
            $amount = (float) $status->amount;
        }

        $result = $this->api('/refund/apply', [
            'mid' => $this->requireModeConfig('merchant_id'),
            'txnType' => 'REFUND',
            'orderId' => $transactionId,
            'txnId' => $status->gatewayReference,
            'refId' => 'REF_' . Str::upper(Str::random(12)),
            'refundAmount' => number_format($amount, 2, '.', ''),
        ]);

        $resultStatus = $result['body']['resultInfo']['resultStatus'] ?? null;

        $this->log('info', 'Paytm refund', ['order_id' => $transactionId, 'status' => $resultStatus]);

        if (in_array($resultStatus, ['PENDING', 'TXN_SUCCESS'], true)) {
            return true;
        }

        throw new PaymentException('Paytm refund failed: ' . ($result['body']['resultInfo']['resultMsg'] ?? 'unknown error'));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    // ---------------------------------------------------------------------
    // PaytmChecksum (port of Paytm's official PHP checksum library)
    // ---------------------------------------------------------------------

    /**
     * Generate a signature for a JSON body string or a params array.
     */
    public function generateChecksum(array|string $params): string
    {
        $key = $this->requireModeConfig('merchant_key');
        $string = is_array($params) ? $this->stringFromParams($params) : $params;

        return $this->encrypt($this->hashWithSalt($string, Str::random(4)), $key);
    }

    /**
     * Verify a checksum for a JSON body string or a params array.
     */
    public function verifyChecksum(array|string $params, string $checksum): bool
    {
        $key = $this->getModeConfig('merchant_key');
        if (!$key || $checksum === '') {
            return false;
        }

        $decrypted = $this->decrypt($checksum, $key);
        if (!$decrypted || strlen($decrypted) < 4) {
            return false;
        }

        $string = is_array($params) ? $this->stringFromParams($params) : $params;

        return hash_equals($this->hashWithSalt($string, substr($decrypted, -4)), $decrypted);
    }

    private function stringFromParams(array $params): string
    {
        ksort($params);

        return implode('|', array_map(
            fn ($v) => ($v === null || strtolower((string) $v) === 'null') ? '' : (string) $v,
            $params
        ));
    }

    private function hashWithSalt(string $string, string $salt): string
    {
        return hash('sha256', $string . '|' . $salt) . $salt;
    }

    private function encrypt(string $input, string $key): string
    {
        return (string) openssl_encrypt($input, 'AES-128-CBC', html_entity_decode($key), 0, self::IV);
    }

    private function decrypt(string $encrypted, string $key): string|false
    {
        return openssl_decrypt($encrypted, 'AES-128-CBC', html_entity_decode($key), 0, self::IV);
    }

    /**
     * POST {body, head: {signature}} to a Paytm API.
     */
    private function api(string $path, array $body, array $query = []): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $payload = '{"body":' . $json . ',"head":' . json_encode(['signature' => $this->generateChecksum($json)]) . '}';

        $url = $this->baseUrl() . $path . ($query ? '?' . http_build_query($query) : '');

        $response = Http::timeout(30)
            ->withBody($payload, 'application/json')
            ->post($url);

        if (!$response->successful()) {
            throw new PaymentException("Paytm API {$path} failed with HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    private function baseUrl(): string
    {
        return $this->getModeConfig('base_url')
            ?? ($this->isSandbox() ? 'https://securegw-stage.paytm.in' : 'https://securegw.paytm.in');
    }
}
