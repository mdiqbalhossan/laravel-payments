<?php

namespace Mdiqbal\LaravelPayments\Tests\Feature;

use Mdiqbal\LaravelPayments\Tests\TestCase;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GatewayIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_process_payment_with_multiple_gateways()
    {
        $request = new PaymentRequest(
            orderId: 'TEST_ORDER_001',
            amount: 50.00,
            currency: 'USD',
            customerEmail: 'test@example.com',
            callbackUrl: 'https://example.com/callback'
        );

        // Test with Stripe
        $stripeResponse = Payment::pay('stripe', $request);
        $this->assertNotNull($stripeResponse);
        $this->assertInstanceOf(\Mdiqbal\LaravelPayments\DTO\PaymentResponse::class, $stripeResponse);

        // Test with PayPal
        $paypalResponse = Payment::pay('paypal', $request);
        $this->assertNotNull($paypalResponse);
        $this->assertInstanceOf(\Mdiqbal\LaravelPayments\DTO\PaymentResponse::class, $paypalResponse);
    }

    /** @test */
    public function it_can_verify_webhook_payloads()
    {
        // Stripe webhook payload
        $stripePayload = [
            'id' => 'evt_test',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test',
                    'status' => 'succeeded',
                    'amount' => 10000,
                    'currency' => 'usd'
                ]
            ]
        ];

        $response = Payment::verify('stripe', $stripePayload);
        $this->assertInstanceOf(\Mdiqbal\LaravelPayments\DTO\PaymentResponse::class, $response);

        // PayPal webhook payload
        $paypalPayload = [
            'id' => 'WH-123456789',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'PAY-123',
                'status' => 'COMPLETED'
            ]
        ];

        $response = Payment::verify('paypal', $paypalPayload);
        $this->assertInstanceOf(\Mdiqbal\LaravelPayments\DTO\PaymentResponse::class, $response);
    }

    /** @test */
    public function it_can_handle_payment_context()
    {
        $request = new PaymentRequest(
            orderId: 'CONTEXT_TEST',
            amount: 75.00,
            currency: 'EUR',
            customerEmail: 'context@example.com',
            callbackUrl: 'https://example.com/callback'
        );

        $response = Payment::context()
            ->using('stripe')
            ->with($request)
            ->execute();

        $this->assertInstanceOf(\Mdiqbal\LaravelPayments\DTO\PaymentResponse::class, $response);
    }

    /** @test */
    public function it_can_process_refunds()
    {
        $transactionId = 'TXN_REFUND_TEST';
        $amount = 25.00;

        $result = Payment::refund('stripe', $transactionId, $amount);
        $this->assertIsBool($result);
    }

    /** @test */
    public function it_can_check_refund_support()
    {
        $this->assertTrue(Payment::supportsRefund('stripe'));
        $this->assertTrue(Payment::supportsRefund('paypal'));
    }
}