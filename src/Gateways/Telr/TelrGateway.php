<?php

namespace Mdiqbal\LaravelPayments\Gateways\Telr;

use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * Telr Hosted Payment Page (order.json API).
 *
 * Config (per mode): store_id, store_auth_key. Sandbox mode sends ivp_test=1.
 *
 * Flow: pay() creates an order and returns its URL; the Telr order ref is
 * the transaction id and must be stored, because Telr's return URLs carry no
 * reference. verify(['order_ref' => ...]) runs the "check" method.
 * Refunds use Telr's separate remote API and are not supported here.
 */
class TelrGateway extends AbstractGateway
{
    private const ENDPOINT = 'https://secure.telr.com/gateway/order.json';

    public function gatewayName(): string
    {
        return 'telr';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $return = $request->getReturnUrl();
        if (!$return) {
            throw new PaymentException('Telr requires a return URL');
        }

        $customer = $request->getCustomer() ?? [];

        $result = $this->call(array_filter([
            'ivp_method' => 'create',
            'ivp_cart' => $request->getTransactionId() ?? $this->generateOrderId(),
            'ivp_amount' => number_format($request->getAmount(), 2, '.', ''),
            'ivp_currency' => strtoupper($request->getCurrency() ?: 'AED'),
            'ivp_desc' => $request->getDescription() ?? 'Payment',
            'return_auth' => $return,
            'return_decl' => $request->getCancelUrl() ?? $return,
            'return_can' => $request->getCancelUrl() ?? $return,
            'bill_email' => $request->getEmail(),
            'bill_fname' => $customer['name'] ?? null,
            'bill_phone' => $customer['phone'] ?? null,
        ], fn ($v) => $v !== null));

        $order = $result['order'] ?? [];
        if (empty($order['ref']) || empty($order['url'])) {
            throw new PaymentException('Telr: ' . ($result['error']['message'] ?? $result['error']['note'] ?? 'failed to create order'));
        }

        return PaymentResponse::redirect($order['url'], [
            'transaction_id' => $order['ref'],
            'gateway_reference' => $request->getTransactionId(),
            'message' => 'Redirect to Telr; store transaction_id to verify the order later',
        ]);
    }

    /**
     * Check an order. Accepts order_ref / ref / transaction_id.
     */
    public function verify(array $payload): PaymentResponse
    {
        $ref = $payload['order_ref'] ?? $payload['ref'] ?? $payload['transaction_id'] ?? null;
        if (!$ref) {
            throw new PaymentException('Telr order_ref not found in payload');
        }

        $result = $this->call(['ivp_method' => 'check', 'order_ref' => $ref]);
        $order = $result['order'] ?? null;

        if (!$order) {
            throw new PaymentException('Telr: ' . ($result['error']['message'] ?? 'order not found'));
        }

        $code = (int) ($order['status']['code'] ?? 0);

        return new PaymentResponse(
            success: $code === 3,
            transactionId: $order['ref'] ?? $ref,
            message: $order['status']['text'] ?? null,
            status: match ($code) {
                3 => 'completed',
                1, 2 => 'pending',
                -2 => 'canceled',
                -1 => 'expired',
                default => 'failed',
            },
            data: [
                'cart_id' => $order['cartid'] ?? null,
                'transaction' => $order['transaction'] ?? null,
                'raw' => $order,
            ],
            gatewayReference: $order['transaction']['ref'] ?? null,
            amount: isset($order['amount']) ? (float) $order['amount'] : null,
            currency: $order['currency'] ?? null
        );
    }

    private function call(array $params): array
    {
        $params += [
            'ivp_store' => $this->requireModeConfig('store_id'),
            'ivp_authkey' => $this->getModeConfig('store_auth_key') ?? $this->requireModeConfig('auth_key'),
            'ivp_test' => $this->isSandbox() ? '1' : '0',
        ];

        $response = Http::asForm()->acceptJson()->timeout(30)->post(self::ENDPOINT, $params);

        if (!$response->successful()) {
            throw new PaymentException("Telr API failed with HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }
}
