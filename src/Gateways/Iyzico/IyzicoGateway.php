<?php

namespace Mdiqbal\LaravelPayments\Gateways\Iyzico;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * iyzico Checkout Form.
 *
 * Flow: pay() initializes a checkout form and returns paymentPageUrl (the
 * checkoutFormContent script is in data for embedding); the form token is
 * the transaction id. iyzico POSTs ?token=... to the callback URL; verify()
 * retrieves the checkout form result from the API. The paymentId from
 * verify() (gatewayReference) is what refund() expects.
 *
 * Buyer fields iyzico requires can be passed in customer[]: name, surname,
 * phone, identity_number, address, city, country.
 */
class IyzicoGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'iyzico';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $callback = $request->getReturnUrl();
        if (!$callback) {
            throw new PaymentException('iyzico requires a return (callback) URL');
        }

        if (!$request->getEmail()) {
            throw new PaymentException('iyzico requires a customer email');
        }

        $orderId = $request->getTransactionId() ?? $this->generateOrderId();
        $price = $this->price($request->getAmount());
        $customer = $request->getCustomer() ?? [];
        [$first, $last] = $this->splitName($customer['name'] ?? 'Customer');

        $address = [
            'contactName' => trim(($customer['name'] ?? "{$first} {$last}")),
            'city' => $customer['city'] ?? 'Istanbul',
            'country' => $customer['country'] ?? 'Turkey',
            'address' => $customer['address'] ?? 'N/A',
        ];

        $body = [
            'locale' => $request->getMeta('locale', 'en'),
            'conversationId' => $orderId,
            'price' => $price,
            'paidPrice' => $price,
            'currency' => strtoupper($request->getCurrency() ?: 'TRY'),
            'basketId' => $orderId,
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => $callback,
            'buyer' => [
                'id' => (string) ($request->getMeta('customer_id') ?? md5((string) ($request->getEmail() ?? $orderId))),
                'name' => $customer['first_name'] ?? $first,
                'surname' => $customer['surname'] ?? $customer['last_name'] ?? $last,
                'gsmNumber' => $customer['phone'] ?? null,
                'email' => $request->getEmail(),
                'identityNumber' => (string) ($customer['identity_number'] ?? '11111111111'),
                'registrationAddress' => $address['address'],
                'ip' => request()->ip() ?? '127.0.0.1',
                'city' => $address['city'],
                'country' => $address['country'],
            ],
            'billingAddress' => $address,
            'basketItems' => [[
                'id' => $orderId,
                'name' => $request->getDescription() ?? 'Order ' . $orderId,
                'category1' => $request->getMeta('category', 'General'),
                'itemType' => $request->getMeta('item_type', 'VIRTUAL'),
                'price' => $price,
            ]],
        ];
        $body['buyer'] = array_filter($body['buyer'], fn ($v) => $v !== null);

        $result = $this->api('/payment/iyzipos/checkoutform/initialize/auth/ecom', $body);

        if (($result['status'] ?? null) !== 'success' || empty($result['token'])) {
            throw new PaymentException('iyzico: ' . ($result['errorMessage'] ?? 'failed to initialize checkout form'));
        }

        return PaymentResponse::redirect($result['paymentPageUrl'], [
            'transaction_id' => $result['token'],
            'gateway_reference' => $orderId,
            'checkout_form_content' => $result['checkoutFormContent'] ?? null,
            'message' => 'Redirect to iyzico checkout',
        ]);
    }

    /**
     * Retrieve the checkout form result. Accepts token (callback POST).
     */
    public function verify(array $payload): PaymentResponse
    {
        $token = $payload['token'] ?? $payload['transaction_id'] ?? null;
        if (!$token) {
            throw new PaymentException('iyzico token not found in payload');
        }

        $result = $this->api('/payment/iyzipos/checkoutform/auth/ecom/detail', [
            'locale' => 'en',
            'token' => $token,
        ]);

        $paid = ($result['status'] ?? null) === 'success' && ($result['paymentStatus'] ?? null) === 'SUCCESS';

        return new PaymentResponse(
            success: $paid,
            transactionId: $token,
            message: $result['errorMessage'] ?? $result['paymentStatus'] ?? null,
            status: $paid ? 'completed' : (($result['paymentStatus'] ?? null) === 'INIT_THREEDS' ? 'pending' : 'failed'),
            data: [
                'basket_id' => $result['basketId'] ?? null,
                'conversation_id' => $result['conversationId'] ?? null,
                'fraud_status' => $result['fraudStatus'] ?? null,
                'raw' => $result,
            ],
            gatewayReference: $result['paymentId'] ?? null,
            amount: isset($result['paidPrice']) ? (float) $result['paidPrice'] : null,
            currency: $result['currency'] ?? null
        );
    }

    /**
     * Refund. $transactionId is the iyzico paymentId (verify()->gatewayReference).
     */
    public function refund(string $transactionId, float $amount): bool
    {
        $detail = $this->api('/payment/detail', ['locale' => 'en', 'paymentId' => $transactionId]);
        $item = $detail['itemTransactions'][0] ?? null;

        if (($detail['status'] ?? null) !== 'success' || !$item) {
            throw new PaymentException('iyzico refund: payment not found');
        }

        $result = $this->api('/payment/refund', [
            'locale' => 'en',
            'paymentTransactionId' => $item['paymentTransactionId'],
            'price' => $this->price($amount > 0 ? $amount : (float) $item['paidPrice']),
            'currency' => $detail['currency'] ?? 'TRY',
            'ip' => request()->ip() ?? '127.0.0.1',
        ]);

        if (($result['status'] ?? null) === 'success') {
            return true;
        }

        throw new PaymentException('iyzico refund failed: ' . ($result['errorMessage'] ?? 'unknown error'));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * IYZWSv2 authorization header value for a request.
     */
    public function authorization(string $uriPath, string $jsonBody, string $randomKey): string
    {
        $signature = hash_hmac('sha256', $randomKey . $uriPath . $jsonBody, $this->requireModeConfig('secret_key'));
        $auth = 'apiKey:' . $this->requireModeConfig('api_key') . '&randomKey:' . $randomKey . '&signature:' . $signature;

        return 'IYZWSv2 ' . base64_encode($auth);
    }

    private function api(string $path, array $body): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $random = (string) (int) (microtime(true) * 1000) . Str::random(8);

        $response = Http::timeout(30)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => $this->authorization($path, $json, $random),
                'x-iyzi-rnd' => $random,
            ])
            ->withBody($json, 'application/json')
            ->post($this->baseUrl() . $path);

        $result = $response->json() ?? [];

        if (!$response->successful() && !isset($result['status'])) {
            throw new PaymentException("iyzico API {$path} failed with HTTP {$response->status()}");
        }

        return $result;
    }

    private function price(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?: 'Customer', $parts[1] ?? $parts[0]];
    }

    private function baseUrl(): string
    {
        return $this->isSandbox() ? 'https://sandbox-api.iyzipay.com' : 'https://api.iyzipay.com';
    }
}
