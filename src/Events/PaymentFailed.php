<?php

namespace Mdiqbal\LaravelPayments\Events;

use Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentFailed
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
     * Get the error message
     */
    public function getErrorMessage(): ?string
    {
        return $this->response->message;
    }

    /**
     * Get the amount
     */
    public function getAmount(): float
    {
        return $this->request->amount;
    }

    /**
     * Get the currency
     */
    public function getCurrency(): string
    {
        return $this->request->currency;
    }

    /**
     * Get the customer email
     */
    public function getCustomerEmail(): string
    {
        return $this->request->customerEmail;
    }

    /**
     * Check if it's a validation error
     */
    public function isValidationError(): bool
    {
        return $this->response->status === 'validation_error';
    }

    /**
     * Check if it's a gateway error
     */
    public function isGatewayError(): bool
    {
        return $this->response->status === 'gateway_error';
    }
}