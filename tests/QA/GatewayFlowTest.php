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
            'paypal' => ['mode' => 'sandbox', 'sandbox' => ['client_id' => 'cid', 'client_secret' => 'csec']],
            'paytm' => ['mode' => 'sandbox', 'sandbox' => ['merchant_id' => 'MID123', 'merchant_key' => 'abcdefgh12345678']],
            'flutterwave' => ['mode' => 'sandbox', 'sandbox' => ['secret_key' => 'FLWSECK_TEST-x'], 'secret_hash' => 'my-hash'],
            'mollie' => ['mode' => 'sandbox', 'sandbox' => ['api_key' => 'test_abc']],
            'midtrans' => ['mode' => 'sandbox', 'sandbox' => ['server_key' => 'SB-Mid-server-x']],
            'cashfree' => ['mode' => 'sandbox', 'sandbox' => ['app_id' => 'cfid', 'secret_key' => 'cfsecret']],
            'mercadopago' => ['mode' => 'sandbox', 'sandbox' => ['access_token' => 'TEST-token'], 'webhook_secret' => 'mpsecret'],
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

    public function test_paytm_checksum_roundtrip_and_flow(): void
    {
        $gateway = Payment::gateway('paytm');
        $params = ['ORDERID' => 'ORDER_1001', 'MID' => 'MID123', 'TXNAMOUNT' => '10.00', 'STATUS' => 'TXN_SUCCESS'];
        $checksum = $gateway->generateChecksum($params);

        $this->assertTrue($gateway->verifyChecksum($params, $checksum));
        $this->assertFalse($gateway->verifyChecksum(['TXNAMOUNT' => '1.00'] + $params, $checksum));

        Http::fake([
            '*/theia/api/v1/initiateTransaction*' => Http::response(['body' => ['resultInfo' => ['resultStatus' => 'S'], 'txnToken' => 'TOKEN1']]),
            '*/v3/order/status' => Http::response(['body' => ['resultInfo' => ['resultStatus' => 'TXN_SUCCESS'], 'txnId' => 'T1', 'txnAmount' => '10.00']]),
        ]);

        $pay = Payment::pay('paytm', $this->request(10, 'INR'));
        $this->assertSame('TOKEN1', $pay->getData('form_params.txnToken'));
        Http::assertSent(function ($r) use ($gateway) {
            if (!str_contains($r->url(), 'initiateTransaction')) {
                return false;
            }
            $json = json_decode($r->body(), true);
            return $json['body']['txnAmount']['value'] === '10.00'
                && $gateway->verifyChecksum(json_encode($json['body'], JSON_UNESCAPED_SLASHES), $json['head']['signature']);
        });

        $callback = $params + ['CHECKSUMHASH' => $checksum];
        $this->assertTrue(Payment::verify('paytm', $callback)->matchesAmount(10, 'INR'));

        $this->expectException(\Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException::class);
        Payment::verify('paytm', ['CHECKSUMHASH' => 'forged'] + $params);
    }

    public function test_flutterwave_pay_verify_and_webhook(): void
    {
        Http::fake([
            'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/x']]),
            'api.flutterwave.com/v3/transactions/555/verify' => Http::response(['status' => 'success', 'data' => [
                'id' => 555, 'tx_ref' => 'ORDER_1001', 'status' => 'successful', 'amount' => 2000, 'currency' => 'NGN',
            ]]),
        ]);

        $this->assertSame('https://checkout.flutterwave.com/x', Payment::pay('flutterwave', $this->request(2000, 'NGN'))->redirectUrl);

        $verify = Payment::verify('flutterwave', ['status' => 'successful', 'tx_ref' => 'ORDER_1001', 'transaction_id' => '555']);
        $this->assertTrue($verify->matchesAmount(2000, 'NGN'));

        $body = json_encode(['event' => 'charge.completed', 'data' => ['id' => 555, 'tx_ref' => 'ORDER_1001']]);
        $this->call('POST', '/payments/webhook/flutterwave', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_VERIF_HASH' => 'my-hash'], $body)->assertOk();
        $this->call('POST', '/payments/webhook/flutterwave', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_VERIF_HASH' => 'nope'], $body)->assertStatus(401);
    }

    public function test_mollie_pay_and_verify(): void
    {
        Http::fake([
            'api.mollie.com/v2/payments' => Http::response(['id' => 'tr_1', 'status' => 'open', '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/x']]], 201),
            'api.mollie.com/v2/payments/tr_1' => Http::response(['id' => 'tr_1', 'status' => 'paid', 'amount' => ['value' => '12.50', 'currency' => 'EUR'], 'metadata' => ['order_id' => 'ORDER_1001']]),
        ]);

        $pay = Payment::pay('mollie', $this->request(12.5, 'EUR'));
        $this->assertSame('tr_1', $pay->transactionId);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['amount']['value'] === '12.50' && $r->hasHeader('Authorization', 'Bearer test_abc'));

        $this->assertTrue(Payment::verify('mollie', ['id' => 'tr_1'])->matchesAmount(12.5, 'EUR'));
    }

    public function test_midtrans_pay_and_signed_notification(): void
    {
        Http::fake([
            'app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap1', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap1'], 201),
            'api.sandbox.midtrans.com/v2/ORDER_1001/status' => Http::response([
                'order_id' => 'ORDER_1001', 'transaction_status' => 'settlement', 'status_code' => '200', 'gross_amount' => '150000.00', 'currency' => 'IDR',
            ]),
        ]);

        $this->assertSame('snap1', Payment::pay('midtrans', $this->request(150000, 'IDR'))->getData('snap_token'));

        $note = ['order_id' => 'ORDER_1001', 'status_code' => '200', 'gross_amount' => '150000.00', 'transaction_status' => 'settlement'];
        $note['signature_key'] = hash('sha512', '200' === '200' ? 'ORDER_1001200150000.00SB-Mid-server-x' : '');

        $this->postJson('/payments/webhook/midtrans', $note)->assertOk();
        $this->postJson('/payments/webhook/midtrans', ['signature_key' => 'forged'] + $note)->assertStatus(401);
        $this->assertTrue(Payment::verify('midtrans', ['order_id' => 'ORDER_1001'])->matchesAmount(150000, 'IDR'));
    }

    public function test_cashfree_pay_verify_and_webhook(): void
    {
        Http::fake([
            'sandbox.cashfree.com/pg/orders' => Http::response(['order_id' => 'ORDER_1001', 'cf_order_id' => 1, 'payment_session_id' => 'sess_1', 'order_amount' => 99.0, 'order_currency' => 'INR']),
            'sandbox.cashfree.com/pg/orders/ORDER_1001' => Http::response(['order_id' => 'ORDER_1001', 'order_status' => 'PAID', 'order_amount' => 99.0, 'order_currency' => 'INR']),
        ]);

        $this->assertSame('sess_1', Payment::pay('cashfree', $this->request(99, 'INR'))->getData('payment_session_id'));
        Http::assertSent(fn ($r) => $r->hasHeader('x-client-secret', 'cfsecret') && $r->hasHeader('x-api-version', '2023-08-01'));

        $body = json_encode(['type' => 'PAYMENT_SUCCESS_WEBHOOK', 'data' => ['order' => ['order_id' => 'ORDER_1001']]]);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts . $body, 'cfsecret', true));

        $this->call('POST', '/payments/webhook/cashfree', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_X_WEBHOOK_SIGNATURE' => $sig], $body)->assertOk();
        $this->call('POST', '/payments/webhook/cashfree', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_X_WEBHOOK_SIGNATURE' => 'x'], $body)->assertStatus(401);
    }

    public function test_mercadopago_signed_webhook(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/123' => Http::response([
            'id' => 123, 'status' => 'approved', 'external_reference' => 'ORDER_1001', 'transaction_amount' => 80, 'currency_id' => 'BRL',
        ])]);

        $ts = (string) time();
        $sig = 'ts=' . $ts . ',v1=' . hash_hmac('sha256', "id:123;request-id:req-1;ts:{$ts};", 'mpsecret');
        $payload = ['type' => 'payment', 'data' => ['id' => '123']];

        $this->postJson('/payments/webhook/mercadopago', $payload, ['x-signature' => $sig, 'x-request-id' => 'req-1'])->assertOk();
        $this->postJson('/payments/webhook/mercadopago', $payload, ['x-signature' => 'ts=1,v1=bad', 'x-request-id' => 'req-1'])->assertStatus(401);

        $this->assertTrue(Payment::verify('mercadopago', ['payment_id' => '123'])->matchesAmount(80, 'BRL'));
    }

    public function test_paypal_pay_capture_and_refund(): void
    {
        $captured = ['id' => 'O1', 'status' => 'COMPLETED', 'purchase_units' => [[
            'reference_id' => 'ORDER_1001',
            'payments' => ['captures' => [['id' => 'C1', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'EUR']]]],
        ]]];

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'AT', 'expires_in' => 3600]),
            '*/v2/checkout/orders' => Http::response(['id' => 'O1', 'status' => 'CREATED', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=O1']]], 201),
            '*/v2/checkout/orders/O1' => Http::response(['id' => 'O1', 'status' => 'APPROVED', 'purchase_units' => [['reference_id' => 'ORDER_1001']]]),
            '*/v2/checkout/orders/O1/capture' => Http::response($captured, 201),
            '*/v2/payments/captures/C1' => Http::response(['id' => 'C1', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'EUR']]),
            '*/v2/payments/captures/C1/refund' => Http::response(['id' => 'R1', 'status' => 'COMPLETED'], 201),
        ]);

        $pay = Payment::pay('paypal', $this->request(20, 'EUR'));
        $this->assertSame('O1', $pay->transactionId);
        $this->assertStringContainsString('token=O1', $pay->redirectUrl);

        $verify = Payment::verify('paypal', ['token' => 'O1', 'PayerID' => 'P']);
        $this->assertTrue($verify->success);
        $this->assertSame('C1', $verify->transactionId);
        $this->assertTrue($verify->matchesAmount(20, 'EUR'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/O1/capture'));

        $this->assertTrue(Payment::refund('paypal', 'C1', 5));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/C1/refund') && $r['amount']['currency_code'] === 'EUR');
    }
}
