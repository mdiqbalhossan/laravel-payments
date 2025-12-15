<?php

namespace Mdiqbal\LaravelPayments\Events;

use Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentSucceeded
{
    use Dispatchable;

    public function __construct(
        public PaymentGatewayInterface $gateway,
        public PaymentRequest $request,
        public PaymentResponse $response
    ) {}

    /**
     * Get the gateway name
     */
    public function getGatewayName(): string
    {
        return $this->gateway->gatewayName();
    }

    /**
     * Get the order ID
     */
    public function getOrderId(): string
    {
        return $this->request->orderId;
    }

    /**
     * Get the transaction ID
     */
    public function getTransactionId(): ?string
    {
        return $this->response->transactionId;
    }

    /**
     * Get the amount
     */
    public function getAmount(): ?float
    {
        return $this->response->amount ?? $this->request->amount;
    }

    /**
     * Get the currency
     */
    public function getCurrency(): ?string
    {
        return $this->response->currency ?? $this->request->currency;
    }

    /**
     * Get the customer email
     */
    public function getCustomerEmail(): string
    {
        return $this->request->customerEmail;
    }

    /**
     * Get the gateway reference
     */
    public function getGatewayReference(): ?string
    {
        return $this->response->gatewayReference;
    }
}