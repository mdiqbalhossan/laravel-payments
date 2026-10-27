<?php

namespace Mdiqbal\LaravelPayments\Gateways\Bkash;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Core\AbstractGateway;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

/**
 * bKash Tokenized Checkout (v1.2.0-beta).
 *
 * Flow: pay() creates a payment and returns bkashURL to redirect to. bKash
 * redirects back to the callback URL with ?paymentID=...&status=success|failure|cancel.
 * Call verify() with that query: it executes the payment (or queries it if
 * already executed). The paymentID is used as the transaction id throughout;
 * pass it to refund().
 */
class BkashGateway extends AbstractGateway
{
    public function gatewayName(): string
    {
        return 'bkash';
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $currency = strtoupper($request->getCurrency() ?: 'BDT');
        if ($currency !== 'BDT') {
            throw new PaymentException('bKash only supports BDT');
        }

        $callbackUrl = $request->getReturnUrl() ?? $this->getModeConfig('callback_url');
        if (!$callbackUrl) {
            throw new PaymentException('bKash requires a callback (return) URL');
        }

        $invoice = $request->getTransactionId() ?? $this->generateOrderId();
        $customer = $request->getCustomer() ?? [];

        $result = $this->api('/create', [
            'mode' => '0011',
            'payerReference' => (string) ($customer['phone'] ?? $request->getMeta('payer_reference', $invoice)),
            'callbackURL' => $callbackUrl,
            'amount' => number_format($request->getAmount(), 2, '.', ''),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $invoice,
        ]);

        if (($result['statusCode'] ?? null) !== '0000' || empty($result['bkashURL'])) {
            throw new PaymentException('bKash: ' . ($result['statusMessage'] ?? $result['errorMessage'] ?? 'failed to create payment'));
        }

        return PaymentResponse::redirect($result['bkashURL'], [
            'transaction_id' => $result['paymentID'],
            'gateway_reference' => $invoice,
            'payment_id' => $result['paymentID'],
            'message' => 'Redirect to bKash payment page',
        ]);
    }

    /**
     * Execute/confirm a payment from the bKash callback (paymentID + status).
     */
    public function verify(array $payload): PaymentResponse
    {
        $paymentId = $payload['paymentID'] ?? $payload['payment_id'] ?? $payload['transaction_id'] ?? null;
        if (!$paymentId) {
            throw new PaymentException('bKash paymentID not found in payload');
        }

        $callbackStatus = $payload['status'] ?? 'success';
        if (in_array($callbackStatus, ['failure', 'cancel'], true)) {
            return new PaymentResponse(
                success: false,
                transactionId: $paymentId,
                message: "bKash payment {$callbackStatus}",
                status: $callbackStatus === 'cancel' ? 'canceled' : 'failed'
            );
        }

        $result = $this->api('/execute', ['paymentID' => $paymentId]);

        // Already executed (e.g. callback hit twice) or execute timed out: query instead
        if (empty($result['transactionStatus'])) {
            $result = $this->api('/payment/status', ['paymentID' => $paymentId]);
        }

        return $this->toResponse($result, $paymentId);
    }

    /**
     * Query a payment's current status without executing it.
     */
    public function queryPayment(string $paymentId): PaymentResponse
    {
        return $this->toResponse($this->api('/payment/status', ['paymentID' => $paymentId]), $paymentId);
    }

    /**
     * Refund a completed payment. $transactionId is the bKash paymentID.
     */
    public function refund(string $transactionId, float $amount): bool
    {
        $payment = $this->api('/payment/status', ['paymentID' => $transactionId]);

        if (($payment['transactionStatus'] ?? null) !== 'Completed' || empty($payment['trxID'])) {
            throw new PaymentException('bKash refund: payment is not completed');
        }

        if ($amount <= 0) {
            $amount = (float) $payment['amount'];
        }

        $result = $this->api('/payment/refund', [
            'paymentID' => $transactionId,
            'trxID' => $payment['trxID'],
            'amount' => number_format($amount, 2, '.', ''),
            'sku' => 'refund',
            'reason' => 'Refund requested',
        ]);

        $this->log('info', 'bKash refund', [
            'payment_id' => $transactionId,
            'refund_trx_id' => $result['refundTrxID'] ?? null,
            'status' => $result['transactionStatus'] ?? null,
        ]);

        if (($result['transactionStatus'] ?? null) === 'Completed') {
            return true;
        }

        throw new PaymentException('bKash refund failed: ' . ($result['statusMessage'] ?? $result['errorMessage'] ?? 'unknown error'));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function getSupportedCurrencies(): array
    {
        return ['BDT'];
    }

    /**
     * Extract paymentID/status from the callback request.
     */
    public function parseCallback(Request $request): array
    {
        return [
            'paymentID' => $request->input('paymentID'),
            'status' => $request->input('status'),
        ];
    }

    protected function toResponse(array $result, string $paymentId): PaymentResponse
    {
        $txStatus = $result['transactionStatus'] ?? null;
        $success = $txStatus === 'Completed';

        return new PaymentResponse(
            success: $success,
            transactionId: $paymentId,
            message: $result['statusMessage'] ?? $result['errorMessage'] ?? $txStatus,
            status: match ($txStatus) {
                'Completed' => 'completed',
                'Initiated', 'Pending Authorized' => 'pending',
                'Cancelled', 'Expired' => 'canceled',
                default => 'failed',
            },
            data: [
                'payment_id' => $paymentId,
                'trx_id' => $result['trxID'] ?? null,
                'merchant_invoice_number' => $result['merchantInvoiceNumber'] ?? null,
                'customer_msisdn' => $result['customerMsisdn'] ?? $result['payerAccount'] ?? null,
                'status_code' => $result['statusCode'] ?? null,
                'raw' => $result,
            ],
            gatewayReference: $result['trxID'] ?? null,
            amount: isset($result['amount']) ? (float) $result['amount'] : null,
            currency: $result['currency'] ?? 'BDT'
        );
    }

    /**
     * Call a tokenized checkout endpoint with a (cached) id_token.
     */
    protected function api(string $path, array $body): array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => $this->idToken(),
                'X-App-Key' => $this->requireModeConfig('app_key'),
            ])
            ->post($this->baseUrl() . $path, $body);

        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey());
        }

        if (!$response->successful()) {
            throw new PaymentException("bKash API {$path} failed with HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    protected function idToken(): string
    {
        return Cache::remember($this->tokenCacheKey(), now()->addMinutes(50), function () {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withHeaders([
                    'username' => $this->requireModeConfig('username'),
                    'password' => $this->requireModeConfig('password'),
                ])
                ->post($this->baseUrl() . '/token/grant', [
                    'app_key' => $this->requireModeConfig('app_key'),
                    'app_secret' => $this->requireModeConfig('app_secret'),
                ]);

            $token = $response->json('id_token');
            if (!$response->successful() || !$token) {
                throw new PaymentException('bKash token grant failed: ' . ($response->json('statusMessage') ?? $response->status()));
            }

            return $token;
        });
    }

    protected function tokenCacheKey(): string
    {
        return 'payments.bkash.token.' . $this->mode . '.' . md5((string) $this->getModeConfig('app_key'));
    }

    protected function baseUrl(): string
    {
        return $this->getModeConfig('base_url') ?? ($this->isSandbox()
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout');
    }
}
