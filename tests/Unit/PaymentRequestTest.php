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
            orderId: 'ORDER_123',
            amount: 100.50,
            currency: 'USD',
            customerEmail: 'test@example.com',
            callbackUrl: 'https://example.com/callback'
        );

        $this->assertEquals('ORDER_123', $request->orderId);
        $this->assertEquals(100.50, $request->amount);
        $this->assertEquals('USD', $request->currency);
        $this->assertEquals('test@example.com', $request->customerEmail);
        $this->assertEquals('https://example.com/callback', $request->callbackUrl);
    }

    /** @test */
    public function it_can_create_from_array()
    {
        $data = [
            'order_id' => 'ORDER_456',
            'amount' => '200.00',
            'currency' => 'EUR',
            'customer_email' => 'user@test.com',
            'callback_url' => 'https://example.com/success',
            'webhook_url' => 'https://example.com/webhook',
            'customer_name' => 'John Doe',
            'customer_phone' => '+1234567890',
            'description' => 'Test payment',
            'meta' => ['key' => 'value'],
        ];

        $request = PaymentRequest::fromArray($data);

        $this->assertEquals('ORDER_456', $request->orderId);
        $this->assertEquals(200.00, $request->amount);
        $this->assertEquals('EUR', $request->currency);
        $this->assertEquals('user@test.com', $request->customerEmail);
        $this->assertEquals('https://example.com/success', $request->callbackUrl);
        $this->assertEquals('https://example.com/webhook', $request->webhookUrl);
        $this->assertEquals('John Doe', $request->customerName);
        $this->assertEquals('+1234567890', $request->customerPhone);
        $this->assertEquals('Test payment', $request->description);
        $this->assertEquals(['key' => 'value'], $request->meta);
    }

    /** @test */
    public function it_can_convert_to_array()
    {
        $request = new PaymentRequest(
            orderId: 'ORDER_789',
            amount: 150.75,
            currency: 'GBP',
            customerEmail: 'test@uk.com',
            callbackUrl: 'https://example.co.uk/callback',
            meta: ['order_type' => 'premium']
        );

        $array = $request->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('ORDER_789', $array['order_id']);
        $this->assertEquals(150.75, $array['amount']);
        $this->assertEquals('GBP', $array['currency']);
        $this->assertEquals('test@uk.com', $array['customer_email']);
        $this->assertEquals('https://example.co.uk/callback', $array['callback_url']);
        $this->assertEquals(['order_type' => 'premium'], $array['meta']);
    }

    /** @test */
    public function it_can_get_amount_in_cents()
    {
        $request = new PaymentRequest(
            orderId: 'TEST',
            amount: 99.99,
            currency: 'USD',
            customerEmail: 'test@example.com',
            callbackUrl: 'https://example.com/callback'
        );

        $this->assertEquals(9999, $request->getAmountInCents());
    }

    /** @test */
    public function it_can_get_meta_value()
    {
        $request = new PaymentRequest(
            orderId: 'TEST',
            amount: 100.00,
            currency: 'USD',
            customerEmail: 'test@example.com',
            callbackUrl: 'https://example.com/callback',
            meta: ['product_id' => '123', 'category' => 'electronics']
        );

        $this->assertEquals('123', $request->getMeta('product_id'));
        $this->assertEquals('electronics', $request->getMeta('category'));
        $this->assertEquals('default', $request->getMeta('non_existent', 'default'));
    }

    /** @test */
    public function it_can_get_custom_data_value()
    {
        $request = new PaymentRequest(
            orderId: 'TEST',
            amount: 100.00,
            currency: 'USD',
            customerEmail: 'test@example.com',
            callbackUrl: 'https://example.com/callback',
            customData: ['shipping_address' => '123 Main St']
        );

        $this->assertEquals('123 Main St', $request->getCustomData('shipping_address'));
    }
}