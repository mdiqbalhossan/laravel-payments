<?php

namespace Mdiqbal\LaravelPayments\Tests\Unit;

use Mdiqbal\LaravelPayments\Tests\TestCase;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

class PaymentRequestTest extends TestCase
{
    /** @test */
    public function it_can_create_payment_request()
    {
        $request = new PaymentRequest(
            amount: 100.50,
            currency: 'USD',
            orderId: 'ORDER_123',
            email: 'test@example.com',
            returnUrl: 'https://example.com/callback'
        );

        $this->assertEquals('ORDER_123', $request->getOrderId());
        $this->assertEquals('ORDER_123', $request->getTransactionId());
        $this->assertEquals(100.50, $request->getAmount());
        $this->assertEquals('USD', $request->getCurrency());
        $this->assertEquals('test@example.com', $request->getEmail());
        $this->assertEquals('https://example.com/callback', $request->getReturnUrl());
    }

    /** @test */
    public function it_can_create_from_array()
    {
        $request = PaymentRequest::fromArray([
            'order_id' => 'ORDER_456',
            'amount' => '200.00',
            'currency' => 'EUR',
            'customer_email' => 'user@test.com',
            'callback_url' => 'https://example.com/success',
            'webhook_url' => 'https://example.com/webhook',
            'customer' => ['name' => 'John Doe', 'phone' => '+1234567890'],
            'description' => 'Test payment',
            'meta' => ['key' => 'value'],
        ]);

        $this->assertEquals('ORDER_456', $request->getOrderId());
        $this->assertEquals(200.00, $request->getAmount());
        $this->assertEquals('EUR', $request->getCurrency());
        $this->assertEquals('user@test.com', $request->getEmail());
        $this->assertEquals('https://example.com/success', $request->getReturnUrl());
        $this->assertEquals('https://example.com/webhook', $request->getNotifyUrl());
        $this->assertEquals('John Doe', $request->getCustomer()['name']);
        $this->assertEquals('Test payment', $request->getDescription());
        $this->assertEquals(['key' => 'value'], $request->getMetadata());
    }

    /** @test */
    public function it_can_convert_to_array()
    {
        $request = new PaymentRequest(
            amount: 150.75,
            currency: 'GBP',
            orderId: 'ORDER_789',
            email: 'test@uk.com',
            returnUrl: 'https://example.co.uk/callback',
            metadata: ['order_type' => 'premium']
        );

        $array = $request->toArray();

        $this->assertEquals('ORDER_789', $array['order_id']);
        $this->assertEquals(150.75, $array['amount']);
        $this->assertEquals('GBP', $array['currency']);
        $this->assertEquals('test@uk.com', $array['email']);
        $this->assertEquals('https://example.co.uk/callback', $array['return_url']);
        $this->assertEquals(['order_type' => 'premium'], $array['metadata']);
    }

    /** @test */
    public function it_can_get_amount_in_cents()
    {
        $this->assertEquals(9999, (new PaymentRequest(amount: 99.99))->getAmountInCents());
    }

    /** @test */
    public function it_can_get_meta_value()
    {
        $request = new PaymentRequest(amount: 100.00, metadata: ['product_id' => '123', 'category' => 'electronics']);

        $this->assertEquals('123', $request->getMeta('product_id'));
        $this->assertEquals('electronics', $request->getMeta('category'));
        $this->assertEquals('default', $request->getMeta('non_existent', 'default'));
    }

    /** @test */
    public function it_falls_back_to_customer_email()
    {
        $request = new PaymentRequest(amount: 1, customer: ['email' => 'c@example.com']);

        $this->assertEquals('c@example.com', $request->getEmail());
    }
}
