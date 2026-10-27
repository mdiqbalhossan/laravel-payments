<?php

namespace Mdiqbal\LaravelPayments\Gateways\Senangpay;

use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * senangPay (Malaysia) open API payment form, HMAC-SHA256 hash type.
 *
 * Config (per mode): merchant_id, secret_key. Set the dashboard hash type to
 * SHA256 and configure the return/callback URLs there.
 *
 * Flow: pay() returns the payment URL with signed query parameters.
 * senangPay returns/calls back with status_id, order_id, transaction_id, msg
 * and hash; verify() checks that hash. The amount is covered by the payment
 * hash senangPay validated, so a valid status_id=1 callback for an order
 * means that order's amount was paid. Only MYR is supported; refunds are not.
 */
class SenangpayGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'senangpay';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        if (strtoupper($request->getCurrency() ?: 'MYR') !== 'MYR') {
            throw new PaymentException('senangPay only supports MYR');
        }

        $merchantId = $this->requireModeConfig('merchant_id');
        $secret = $this->requireModeConfig('secret_key');
        $customer = $request->getCustomer() ?? [];

        $detail = preg_replace('/[^A-Za-z0-9 _.,-]/', '_', $request->getDescription() ?? 'Payment');
        $amount = number_format($request->getAmount(), 2, '.', '');
        $orderId = $request->getTransactionId() ?? $this->generateOrderId();

        $params = array_filter([
            'detail' => $detail,
            'amount' => $amount,
            'order_id' => $orderId,
            'name' => $customer['name'] ?? null,
            'email' => $request->getEmail(),
            'phone' => $customer['phone'] ?? null,
            'hash' => hash_hmac('sha256', $secret . $detail . $amount . $orderId, $secret),
        ], fn ($v) => $v !== null);

        $url = $this->baseUrl() . '/payment/' . rawurlencode($merchantId) . '?' . http_build_query($params);

        return PaymentResponse::redirect($url, [
            'transaction_id' => $orderId,
            'message' => 'Redirect to senangPay',
        ]);
    }

    /**
     * Verify return/callback parameters (status_id, order_id, transaction_id, msg, hash).
     */
    public function verify(array $payload): PaymentResponse
    {
        foreach (['status_id', 'order_id', 'transaction_id', 'msg', 'hash'] as $field) {
            if (!isset($payload[$field])) {
                throw new PaymentException("senangPay callback is missing {$field}");
            }
        }

        $secret = $this->requireModeConfig('secret_key');
        $expected = hash_hmac('sha256', $secret . $payload['status_id'] . $payload['order_id'] . $payload['transaction_id'] . $payload['msg'], $secret);

        if (!hash_equals($expected, (string) $payload['hash'])) {
            throw new InvalidSignatureException('Invalid senangPay hash', gateway: 'senangpay');
        }

        $paid = (string) $payload['status_id'] === '1';

        return new PaymentResponse(
            success: $paid,
            transactionId: $payload['order_id'],
            message: str_replace('_', ' ', (string) $payload['msg']),
            status: $paid ? 'completed' : ((string) $payload['status_id'] === '2' ? 'pending' : 'failed'),
            data: ['raw' => $payload],
            gatewayReference: (string) $payload['transaction_id'],
            currency: 'MYR'
        );
    }

    private function baseUrl(): string
    {
        return $this->isSandbox() ? 'https://sandbox.senangpay.my' : 'https://app.senangpay.my';
    }
}
