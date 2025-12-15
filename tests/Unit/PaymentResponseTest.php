<?php

namespace Mdiqbal\LaravelPayments\Tests\Unit;

use Mdiqbal\LaravelPayments\Tests\TestCase;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;

class PaymentResponseTest extends TestCase
{
    /** @test */
    public function it_can_create_successful_response()
    {
        $data = [
            'transaction_id' => 'TXN_123',
            'gateway_reference' => 'REF_456',
            'amount' => 100.00,
            'currency' => 'USD',
            'message' => 'Payment successful'
        ];

        $response = PaymentResponse::success($data);

        $this->assertTrue($response->success);
        $this->assertEquals('TXN_123', $response->transactionId);
        $this->assertEquals('REF_456', $response->gatewayReference);
        $this->assertEquals(100.00, $response->amount);
        $this->assertEquals('USD', $response->currency);
        $this->assertEquals('Payment successful', $response->message);
        $this->assertEquals('success', $response->status);
    }

    /** @test */
    public function it_can_create_failure_response()
    {
        $response = PaymentResponse::failure('Insufficient funds', [
            'error_code' => 'ERR_001',
            'status' => 'failed'
        ]);

        $this->assertFalse($response->success);
        $this->assertEquals('Insufficient funds', $response->message);
        $this->assertEquals('failed', $response->status);
        $this->assertNull($response->transactionId);
    }

    /** @test */
    public function it_can_create_redirect_response()
    {
        $url = 'https://gateway.example.com/pay';
        $response = PaymentResponse::redirect($url, [
            'transaction_id' => 'TXN_789',
            'message' => 'Please complete payment'
        ]);

        $this->assertTrue($response->success);
        $this->assertEquals($url, $response->redirectUrl);
        $this->assertTrue($response->requiresRedirect());
        $this->assertEquals('TXN_789', $response->transactionId);
        $this->assertEquals('pending', $response->status);
    }

    /** @test */
    public function it_can_convert_to_array()
    {
        $response = new PaymentResponse(
            success: true,
            transactionId: 'TXN_123',
            redirectUrl: 'https://example.com/redirect',
            message: 'Processing payment',
            status: 'pending',
            data: ['key' => 'value'],
            gatewayReference: 'REF_456',
            amount: 100.50,
            currency: 'EUR',
            meta: ['custom' => 'data']
        );

        $array = $response->toArray();

        $this->assertIsArray($array);
        $this->assertTrue($array['success']);
        $this->assertEquals('TXN_123', $array['transaction_id']);
        $this->assertEquals('https://example.com/redirect', $array['redirect_url']);
        $this->assertEquals('Processing payment', $array['message']);
        $this->assertEquals('pending', $array['status']);
        $this->assertEquals(['key' => 'value'], $array['data']);
        $this->assertEquals('REF_456', $array['gateway_reference']);
        $this->assertEquals(100.50, $array['amount']);
        $this->assertEquals('EUR', $array['currency']);
        $this->assertEquals(['custom' => 'data'], $array['meta']);
    }

    /** @test */
    public function it_can_convert_to_json()
    {
        $response = new PaymentResponse(
            success: true,
            transactionId: 'TXN_123',
            message: 'Success'
        );

        $json = $response->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['success']);
        $this->assertEquals('TXN_123', $decoded['transaction_id']);
        $this->assertEquals('Success', $decoded['message']);
    }

    /** @test */
    public function it_can_get_data_value()
    {
        $response = new PaymentResponse(
            success: true,
            data: [
                'payment_url' => 'https://example.com/pay',
                'expires_at' => '2024-12-31T23:59:59Z'
            ]
        );

        $this->assertEquals('https://example.com/pay', $response->getData('payment_url'));
        $this->assertEquals('2024-12-31T23:59:59Z', $response->getData('expires_at'));
    }

    /** @test */
    public function it_can_get_meta_value()
    {
        $response = new PaymentResponse(
            success: true,
            meta: [
                'processor' => 'gateway_x',
                'version' => '2.1.0'
            ]
        );

        $this->assertEquals('gateway_x', $response->getMeta('processor'));
        $this->assertEquals('2.1.0', $response->getMeta('version'));
    }

    /** @test */
    public function it_can_check_if_redirect_is_required()
    {
        $redirectResponse = new PaymentResponse(
            success: true,
            redirectUrl: 'https://example.com/pay'
        );
        $this->assertTrue($redirectResponse->requiresRedirect());

        $successResponse = new PaymentResponse(
            success: true,
            transactionId: 'TXN_123'
        );
        $this->assertFalse($successResponse->requiresRedirect());
    }
}