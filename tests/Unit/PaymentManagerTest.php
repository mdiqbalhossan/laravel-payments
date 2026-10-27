<?php

namespace Mdiqbal\LaravelPayments\Tests\Unit;

use Mdiqbal\LaravelPayments\Tests\TestCase;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\GatewayNotFoundException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use Illuminate\Support\Facades\Http;

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
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => 'ORDER_123',
        ]])]);

        $response = Payment::pay('paystack', new PaymentRequest(
            amount: 100.00,
            currency: 'NGN',
            orderId: 'ORDER_123',
            email: 'test@example.com',
            returnUrl: 'https://example.com/callback'
        ));

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertTrue($response->requiresRedirect());
    }

    /** @test */
    public function it_rejects_unverified_payloads()
    {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Transaction reference not found'], 404)]);

        $this->expectException(PaymentException::class);

        Payment::verify('paystack', ['reference' => 'forged']);
    }

    /** @test */
    public function it_wraps_gateway_errors_in_payment_exception()
    {
        $this->expectException(PaymentException::class);

        Payment::pay('paystack', new PaymentRequest(amount: 10, currency: 'NGN'));
    }
}