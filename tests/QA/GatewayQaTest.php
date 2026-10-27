<?php

namespace Mdiqbal\LaravelPayments\Tests\QA;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface;
use Mdiqbal\LaravelPayments\Core\GatewayResolver;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * QA suite: exercises every registered gateway the way a Laravel app would,
 * using the published config shape (mode + sandbox/live credential blocks).
 *
 * No real network traffic: Laravel HTTP calls are faked and raw Guzzle/SDK
 * calls are pointed at a dead proxy (see phpunit.qa.xml), so they fail fast.
 */
class GatewayQaTest extends TestCase
{
    /**
     * Gateway => [native currency, sandbox credentials, forged "paid" callback payload]
     */
    public static function gatewayMatrix(): array
    {
        return [
            'paypal' => ['USD', ['client_id' => 'QA_ID', 'client_secret' => 'QA_SECRET'],
                ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAP-1', 'amount' => ['total' => '100.00']]]],
            'stripe' => ['USD', ['secret_key' => 'sk_test_QA_SECRET', 'api_key' => 'pk_test_QA'],
                ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_forged', 'amount' => 10000]]]],
            'razorpay' => ['INR', ['key_id' => 'rzp_test_QA', 'key_secret' => 'QA_SECRET'],
                ['razorpay_order_id' => 'order_1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'forged']],
            'paystack' => ['NGN', ['secret_key' => 'sk_test_QA_SECRET', 'public_key' => 'pk_test_QA'],
                ['event' => 'charge.success', 'data' => ['reference' => 'ref_forged', 'status' => 'success']]],
            'paytm' => ['INR', ['merchant_id' => 'QA_MID', 'merchant_key' => 'QA_SECRET_KEY_16'],
                ['ORDERID' => 'ORD1', 'TXNID' => 'TXN1', 'STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '100.00', 'CHECKSUMHASH' => 'forged']],
            'flutterwave' => ['NGN', ['public_key' => 'FLWPUBK_TEST-QA', 'secret_key' => 'FLWSECK_TEST-QA_SECRET'],
                ['status' => 'successful', 'tx_ref' => 'ORD1', 'transaction_id' => '123456']],
            'sslcommerz' => ['BDT', ['store_name' => 'qa', 'store_id' => 'qa_store', 'store_password' => 'QA_SECRET'],
                ['tran_id' => 'ORD1', 'val_id' => 'forged', 'status' => 'VALID', 'amount' => '100.00', 'currency' => 'BDT']],
            'mollie' => ['EUR', ['api_key' => 'test_QAQAQAQAQAQAQAQAQAQAQAQAQAQAQA'],
                ['id' => 'tr_forged']],
            'senangpay' => ['MYR', ['merchant_id' => 'QA_MID', 'secret_key' => 'QA_SECRET'],
                ['status_id' => '1', 'order_id' => 'ORD1', 'transaction_id' => 'T1', 'msg' => 'Payment_was_successful', 'hash' => 'forged']],
            'bkash' => ['BDT', ['app_key' => 'QA_KEY', 'app_secret' => 'QA_SECRET', 'username' => 'qa', 'password' => 'qa'],
                ['paymentID' => 'PID1', 'trxID' => 'TRX1', 'transaction_id' => 'ORD1', 'status' => 'success']],
            'mercadopago' => ['BRL', ['access_token' => 'TEST-QA_SECRET'],
                ['type' => 'payment', 'data' => ['id' => '999']]],
            'cashfree' => ['INR', ['app_id' => 'QA_APP', 'secret_key' => 'QA_SECRET'],
                ['orderId' => 'ORD1', 'orderAmount' => '100.00', 'txStatus' => 'SUCCESS', 'signature' => 'forged']],
            'payfast' => ['ZAR', ['merchant_id' => '10000100', 'merchant_key' => 'QA_KEY', 'pass_phrase' => 'QA_SECRET'],
                ['m_payment_id' => 'ORD1', 'pf_payment_id' => '1', 'payment_status' => 'COMPLETE', 'amount_gross' => '100.00', 'signature' => 'forged']],
            'skrill' => ['EUR', ['merchant_email' => 'qa@example.com', 'api_password' => 'QA_SECRET'],
                ['session_id' => 'S1', 'transaction_id' => 'ORD1', 'status' => '2', 'md5sig' => 'forged']],
            'phonepe' => ['INR', ['client_id' => 'QA_MID', 'merchant_user_id' => 'U1', 'key_index' => '1', 'secret_key' => 'QA_SECRET'],
                ['merchantTransactionId' => 'ORD1', 'code' => 'PAYMENT_SUCCESS', 'success' => true]],
            'telr' => ['AED', ['store_id' => 'QA_STORE', 'store_auth_key' => 'QA_SECRET'],
                ['transaction_ref' => 'REF1', 'cart_id' => 'ORD1', 'status' => 'paid']],
            'iyzico' => ['TRY', ['api_key' => 'sandbox-QA', 'secret_key' => 'sandbox-QA_SECRET'],
                ['payment_id' => 'P1', 'conversation_id' => 'ORD1', 'status' => 'success']],
            'pesapal' => ['KES', ['consumer_key' => 'QA_KEY', 'consumer_secret' => 'QA_SECRET', 'ipn_id' => 'IPN1'],
                ['order_tracking_id' => 'TRK1', 'OrderTrackingId' => 'TRK1', 'status' => 'COMPLETED']],
            'midtrans' => ['IDR', ['server_key' => 'SB-Mid-server-QA_SECRET', 'client_key' => 'SB-Mid-client-QA'],
                ['order_id' => 'ORD1', 'transaction_status' => 'settlement', 'status_code' => '200', 'gross_amount' => '100000.00', 'signature_key' => 'forged']],
            'myfatoorah' => ['KWD', ['api_key' => 'QA_SECRET'],
                ['invoice_id' => 'INV1', 'payment_id' => 'P1', 'paymentId' => 'P1', 'status' => 'Paid']],
            'easypaisa' => ['PKR', ['store_id' => 'QA_STORE', 'hash_key' => 'QA_SECRET', 'username' => 'qa', 'password' => 'qa'],
                ['orderRefNum' => 'ORD1', 'transactionId' => 'T1', 'responseCode' => '0000', 'status' => 'PAID']],
        ];
    }

    public static function gateways(): array
    {
        $rows = [];
        foreach (self::gatewayMatrix() as $name => [$currency, $creds, $forged]) {
            $rows[$name] = [$name, $currency, $creds, $forged];
        }
        return $rows;
    }

    protected function getEnvironmentSetUp($app)
    {
        $gateways = [];
        foreach (self::gatewayMatrix() as $name => [, $creds]) {
            $gateways[$name] = [
                'mode' => 'sandbox',
                'sandbox' => $creds,
                'live' => array_map(fn () => null, $creds),
            ];
        }

        $app['config']->set('payments.mode', 'sandbox');
        $app['config']->set('payments.gateways', $gateways);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Routes a typical app defines; several gateways fall back to them.
        Route::get('payment/success', fn () => 'ok')->name('payment.success');
        Route::get('payment/cancel', fn () => 'ok')->name('payment.cancel');
        Route::get('payment/callback', fn () => 'ok')->name('payment.callback');
        Route::get('payment/easypaisa/callback', fn () => 'ok')->name('payment.easypaisa.callback');
        app('router')->getRoutes()->refreshNameLookups();
    }

    private function request(string $currency): PaymentRequest
    {
        return new PaymentRequest(
            amount: 100.00,
            currency: $currency,
            description: 'Test order',
            orderId: 'ORDER_1001',
            email: 'buyer@example.com',
            customer: ['name' => 'Test Buyer', 'email' => 'buyer@example.com', 'phone' => '01712345678'],
            returnUrl: 'https://shop.test/return',
            cancelUrl: 'https://shop.test/cancel',
            notifyUrl: 'https://shop.test/webhook',
        );
    }

    /**
     * Run a gateway call; PHP Errors (TypeError, undefined method, unknown named
     * parameter...) are defects. PaymentException is an acceptable, handled outcome.
     */
    private function attempt(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (PaymentException $e) {
            return $e;
        } catch (\Throwable $e) {
            $this->fail(get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    #[DataProvider('gateways')]
    public function test_class_file_matches_psr4(string $name): void
    {
        $class = app(GatewayResolver::class)->getGatewayClass($name);
        $relative = str_replace(['Mdiqbal\\LaravelPayments\\', '\\'], ['', '/'], $class) . '.php';

        $this->assertFileExists(
            dirname(__DIR__, 2) . '/src/' . $relative,
            "PSR-4 autoload (case-sensitive filesystems) cannot find {$class}"
        );
    }

    #[DataProvider('gateways')]
    public function test_resolves_from_container(string $name): void
    {
        $gateway = $this->attempt(fn () => Payment::gateway($name));

        $this->assertInstanceOf(PaymentGatewayInterface::class, $gateway);
        $this->assertSame($name, $gateway->gatewayName());
    }

    #[DataProvider('gateways')]
    public function test_respects_live_mode(string $name): void
    {
        config()->set("payments.gateways.{$name}.mode", 'live');

        $gateway = $this->attempt(fn () => app(GatewayResolver::class)->resolve($name));

        $this->assertSame('live', $gateway->getMode(), 'Gateway ignores configured live mode');
    }

    #[DataProvider('gateways')]
    public function test_pay_sends_configured_credentials(string $name, string $currency): void
    {
        Http::fake(fn () => Http::response(['status' => 'error', 'message' => 'qa-fake'], 401));

        $result = $this->attempt(fn () => Payment::gateway($name)->pay($this->request($currency)));

        $recorded = Http::recorded();
        if ($recorded->isEmpty()) {
            $this->markTestSkipped('Gateway does not use the Laravel HTTP client; credentials checked via SDK path only');
        }

        $seen = $recorded->contains(function ($pair) {
            $req = $pair[0];
            $haystack = json_encode($req->headers()) . $req->body() . $req->url();
            foreach ($req->header('Authorization') as $auth) {
                if (str_starts_with($auth, 'Basic ')) {
                    $haystack .= base64_decode(substr($auth, 6));
                }
            }
            return str_contains($haystack, 'QA') || str_contains($haystack, base64_encode('SB-Mid-server-QA_SECRET:'));
        });

        $this->assertTrue($seen, 'Outgoing request does not carry the credentials from config/payments.php (sandbox block)');
        $this->assertFalse($result instanceof PaymentResponse && $result->success, 'pay() reported success on a 401 from the provider');
    }

    #[DataProvider('gateways')]
    public function test_pay_without_credentials_is_not_successful(string $name, string $currency): void
    {
        config()->set("payments.gateways.{$name}.sandbox", []);
        Http::fake(fn () => Http::response(['message' => 'unauthorized'], 401));

        $result = $this->attempt(fn () => app(GatewayResolver::class)->resolve($name)->pay($this->request($currency)));

        $this->assertFalse(
            $result instanceof PaymentResponse && $result->success,
            'pay() returned success with no credentials configured (mock/fallback in production path)'
        );
    }

    #[DataProvider('gateways')]
    public function test_forged_callback_is_rejected(string $name, string $currency, array $creds, array $forged): void
    {
        Http::fake(fn () => Http::response(['message' => 'not found'], 404));

        $result = $this->attempt(fn () => Payment::gateway($name)->verify($forged));

        $this->assertFalse(
            $result instanceof PaymentResponse && $result->success,
            'verify() accepted a forged/unsigned "paid" callback as a successful payment'
        );
    }

    #[DataProvider('gateways')]
    public function test_refund_reports_truthfully(string $name): void
    {
        Http::fake(fn () => Http::response(['message' => 'refund rejected'], 400));

        $gateway = Payment::gateway($name);
        $result = $this->attempt(fn () => $gateway->refund('TXN_1001', 10.00));

        if (! $gateway->supportsRefund()) {
            $this->assertInstanceOf(PaymentException::class, $result, 'Unsupported refund should throw PaymentException');
            return;
        }

        $this->assertNotTrue($result, 'refund() returned true although the provider rejected it / no API call was made');
    }

    #[DataProvider('gateways')]
    public function test_webhook_route_handles_forged_request(string $name, string $currency, array $creds, array $forged): void
    {
        Http::fake(fn () => Http::response(['message' => 'not found'], 404));

        $response = $this->postJson("/payments/webhook/{$name}", $forged, ['X-Signature' => 'forged']);

        $this->assertNotSame(200, $response->status(), 'Webhook route answered 200 (payment accepted) to a forged request');
        $this->assertNotSame(500, $response->status(), 'Webhook route crashed: ' . $response->getContent());
    }
}
