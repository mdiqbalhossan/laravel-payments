<?php

namespace Mdiqbal\LaravelPayments\Tests\QA;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\Events\WebhookHandled;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\Tests\TestCase;

/**
 * Happy-path flows against faked provider responses: pay() -> redirect,
 * verify() -> confirmed amount, signed webhook -> accepted.
 */
class GatewayFlowTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('payments.mode', 'sandbox');
        $app['config']->set('payments.gateways', [
            'stripe' => ['mode' => 'sandbox', 'sandbox' => ['secret_key' => 'sk_test_x'], 'webhook_secret' => 'whsec_test'],
            'razorpay' => ['mode' => 'sandbox', 'sandbox' => ['key_id' => 'rzp_test_x', 'key_secret' => 'rzp_secret'], 'webhook_secret' => 'rzp_whsec'],
            'paystack' => ['mode' => 'sandbox', 'sandbox' => ['secret_key' => 'sk_test_paystack']],
            'sslcommerz' => ['mode' => 'sandbox', 'sandbox' => ['store_id' => 'teststore', 'store_password' => 'storepass']],
            'bkash' => ['mode' => 'sandbox', 'sandbox' => ['app_key' => 'ak', 'app_secret' => 'as', 'username' => 'u', 'password' => 'p']],
        ]);
    }

    private function request(float $amount, string $currency): PaymentRequest
    {
        return new PaymentRequest(
            amount: $amount,
            currency: $currency,
            description: 'Order 1001',
            orderId: 'ORDER_1001',
            email: 'buyer@example.com',
            customer: ['name' => 'Buyer', 'phone' => '01712345678'],
            returnUrl: 'https://shop.test/return',
            cancelUrl: 'https://shop.test/cancel',
            notifyUrl: 'https://shop.test/ipn',
        );
    }

    public function test_paystack_pay_and_verify(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'abc', 'reference' => 'ORDER_1001',
            ]]),
            'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
                'id' => 99, 'status' => 'success', 'reference' => 'ORDER_1001', 'amount' => 500000, 'currency' => 'NGN',
            ]]),
        ]);

        $pay = Payment::pay('paystack', $this->request(5000, 'NGN'));
        $this->assertSame('https://checkout.paystack.com/abc', $pay->redirectUrl);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'initialize') && $r['email'] === 'buyer@example.com' && $r['amount'] === 500000);

        $verify = Payment::verify('paystack', ['reference' => 'ORDER_1001']);
        $this->assertTrue($verify->success);
        $this->assertTrue($verify->matchesAmount(5000, 'NGN'));
        $this->assertFalse($verify->matchesAmount(6000, 'NGN'));
    }

    public function test_paystack_signed_webhook(): void
    {
        Event::fake([WebhookHandled::class]);
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'reference' => 'ORDER_1001', 'amount' => 1000, 'currency' => 'NGN',
        ]])]);

        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'ORDER_1001']]);

        $this->call('POST', '/payments/webhook/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_paystack'),
        ], $body)->assertOk();

        $this->call('POST', '/payments/webhook/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => 'forged',
        ], $body)->assertStatus(401);

        Event::assertDispatchedTimes(WebhookHandled::class, 1);
    }

    public function test_stripe_signed_webhook(): void
    {
        $body = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => 'pi_1', 'object' => 'payment_intent', 'amount' => 1050, 'amount_received' => 1050, 'currency' => 'usd',
            'metadata' => ['transaction_id' => 'ORDER_1001'],
        ]]]);
        $t = time();
        $sig = "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", 'whsec_test');

        $ok = $this->call('POST', '/payments/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig], $body);
        $ok->assertOk();

        $this->call('POST', '/payments/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=forged"], $body)
            ->assertStatus(401);

        $response = Payment::gateway('stripe')->handleWebhook(json_decode($body, true), $body, ['stripe-signature' => $sig]);
        $this->assertTrue($response->matchesAmount(10.50, 'USD'));
    }

    public function test_razorpay_signed_webhook(): void
    {
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_1', 'order_id' => 'order_1', 'amount' => 49900, 'currency' => 'INR',
        ]]]]);

        $response = Payment::gateway('razorpay')->handleWebhook(json_decode($body, true), $body, [
            'x-razorpay-signature' => hash_hmac('sha256', $body, 'rzp_whsec'),
        ]);

        $this->assertTrue($response->success);
        $this->assertTrue($response->matchesAmount(499.00, 'INR'));

        $this->call('POST', '/payments/webhook/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => 'forged'], $body)
            ->assertStatus(401);
    }

    public function test_sslcommerz_pay_and_verify(): void
    {
        Http::fake([
            'sandbox.sslcommerz.com/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/pay/xyz', 'sessionkey' => 'xyz']),
            'sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'VALID', 'tran_id' => 'ORDER_1001', 'val_id' => 'v1', 'amount' => '1500.00', 'currency' => 'BDT',
                'currency_type' => 'BDT', 'currency_amount' => '1500.00', 'bank_tran_id' => 'B1',
            ]),
        ]);

        $pay = Payment::pay('sslcommerz', $this->request(1500, 'BDT'));
        $this->assertSame('https://sandbox.sslcommerz.com/pay/xyz', $pay->redirectUrl);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.php') && $r['store_passwd'] === 'storepass' && $r['tran_id'] === 'ORDER_1001');

        $verify = Payment::verify('sslcommerz', ['val_id' => 'v1', 'tran_id' => 'ORDER_1001']);
        $this->assertTrue($verify->success);
        $this->assertTrue($verify->matchesAmount(1500, 'BDT'));
        $this->assertSame('B1', $verify->gatewayReference);
    }

    public function test_sslcommerz_ipn_hash(): void
    {
        $ipn = ['tran_id' => 'ORDER_1001', 'val_id' => 'v1', 'amount' => '1500.00', 'status' => 'VALID'];
        $ipn['verify_key'] = 'amount,status,tran_id,val_id';
        $data = ['amount' => '1500.00', 'status' => 'VALID', 'tran_id' => 'ORDER_1001', 'val_id' => 'v1', 'store_passwd' => md5('storepass')];
        ksort($data);
        $ipn['verify_sign'] = md5(urldecode(http_build_query($data)));

        $gateway = Payment::gateway('sslcommerz');
        $this->assertTrue($gateway->validIpnHash($ipn));
        $this->assertFalse($gateway->validIpnHash(['verify_sign' => 'x'] + $ipn));
    }

    public function test_bkash_pay_and_verify(): void
    {
        Http::fake([
            '*/token/grant' => Http::response(['id_token' => 'tok', 'statusCode' => '0000']),
            '*/checkout/create' => Http::response(['statusCode' => '0000', 'paymentID' => 'PID1', 'bkashURL' => 'https://sandbox.bka.sh/pay/PID1']),
            '*/checkout/execute' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Completed', 'trxID' => 'TRX1', 'amount' => '250.00', 'currency' => 'BDT']),
        ]);

        $pay = Payment::pay('bkash', $this->request(250, 'BDT'));
        $this->assertSame('https://sandbox.bka.sh/pay/PID1', $pay->redirectUrl);
        $this->assertSame('PID1', $pay->transactionId);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/create') && $r->header('Authorization')[0] === 'tok' && $r['amount'] === '250.00');

        $verify = Payment::verify('bkash', ['paymentID' => 'PID1', 'status' => 'success']);
        $this->assertTrue($verify->success);
        $this->assertTrue($verify->matchesAmount(250, 'BDT'));

        $cancel = Payment::verify('bkash', ['paymentID' => 'PID1', 'status' => 'cancel']);
        $this->assertFalse($cancel->success);
    }
}
