<?php

namespace Mdiqbal\LaravelPayments\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Events\PaymentFailed;
use Mdiqbal\LaravelPayments\Events\PaymentInitiated;
use Mdiqbal\LaravelPayments\Events\PaymentSucceeded;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\Tests\TestCase;

class GatewayIntegrationTest extends TestCase
{
    private function request(): PaymentRequest
    {
        return new PaymentRequest(
            amount: 50.00,
            currency: 'NGN',
            orderId: 'TEST_ORDER_001',
            email: 'test@example.com',
            returnUrl: 'https://example.com/callback'
        );
    }

    /** @test */
    public function payment_context_fires_events()
    {
        Event::fake([PaymentInitiated::class, PaymentSucceeded::class, PaymentFailed::class]);
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => 'TEST_ORDER_001',
        ]])]);

        $response = Payment::context()->using('paystack')->with($this->request())->execute();

        $this->assertInstanceOf(PaymentResponse::class, $response);
        Event::assertDispatched(PaymentInitiated::class);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function payment_context_fires_failed_event_on_error()
    {
        Event::fake([PaymentFailed::class]);
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

        try {
            Payment::context()->using('paystack')->with($this->request())->execute();
            $this->fail('Expected PaymentException');
        } catch (PaymentException $e) {
            Event::assertDispatched(PaymentFailed::class);
        }
    }

    /** @test */
    public function webhook_route_rejects_unsigned_requests()
    {
        $this->postJson('/payments/webhook/stripe', ['type' => 'payment_intent.succeeded'])->assertStatus(400);
        $this->postJson('/payments/webhook/stripe', ['type' => 'payment_intent.succeeded'], ['X-Signature' => 'x'])->assertStatus(400);
        $this->postJson('/payments/webhook/unknown', [])->assertNotFound();
    }

    /** @test */
    public function refund_is_rejected_when_provider_fails()
    {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Transaction not found'], 404)]);

        $this->expectException(PaymentException::class);

        Payment::refund('paystack', 'TXN_REFUND_TEST', 25.00);
    }

    /** @test */
    public function it_can_check_refund_support()
    {
        $this->assertTrue(Payment::supportsRefund('stripe'));
        $this->assertTrue(Payment::supportsRefund('paypal'));
        $this->assertFalse(Payment::supportsRefund('telr'));
    }
}
