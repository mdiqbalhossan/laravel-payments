<?php

namespace Mdiqbal\LaravelPayments\Tests\Unit;

use Mdiqbal\LaravelPayments\Tests\TestCase;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\GatewayNotFoundException;

class PaymentManagerTest extends TestCase
{
    /** @test */
    public function it_can_resolve_available_gateways()
    {
        $gateways = Payment::getAvailableGateways();

        $this->assertIsArray($gateways);
        $this->assertContains('paypal', $gateways);
        $this->assertContains('stripe', $gateways);
        $this->assertContains('razorpay', $gateways);
    }

    /** @test */
    public function it_can_check_if_gateway_exists()
    {
        $this->assertTrue(Payment::hasGateway('stripe'));
        $this->assertTrue(Payment::hasGateway('paypal'));
        $this->assertFalse(Payment::hasGateway('invalid_gateway'));
    }

    /** @test */
    public function it_can_get_gateway_instance()
    {
        $gateway = Payment::gateway('stripe');

        $this->assertInstanceOf(
            \Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface::class,
            $gateway
        );
        $this->assertEquals('stripe', $gateway->gatewayName());
    }

    /** @test */
    public function it_throws_exception_for_invalid_gateway()
    {
        $this->expectException(GatewayNotFoundException::class);

        Payment::gateway('invalid_gateway');
    }

    /** @test */
    public function it_can_set_and_get_default_gateway()
    {
        Payment::setDefaultGateway('paypal');
        $this->assertEquals('paypal', Payment::getDefaultGateway());
    }

    /** @test */
    public function it_can_check_if_gateway_supports_refunds()
    {
        $this->assertTrue(Payment::supportsRefund('stripe'));
        $this->assertTrue(Payment::supportsRefund('paypal'));
    }

    /** @test */
    public function it_can_process_payment()
    {
        $request = new PaymentRequest(
            orderId: 'ORDER_123',
            amount: 100.00,
            currency: 'USD',
            customerEmail: 'test@example.com',
            callbackUrl: 'https://example.com/callback'
        );

        $response = Payment::pay('stripe', $request);

        $this->assertInstanceOf(PaymentResponse::class, $response);
    }

    /** @test */
    public function it_can_verify_webhook()
    {
        $payload = [
            'id' => 'pi_test',
            'status' => 'succeeded',
            'amount' => 10000,
            'currency' => 'usd'
        ];

        $response = Payment::verify('stripe', $payload);

        $this->assertInstanceOf(PaymentResponse::class, $response);
    }
}