<?php

namespace Mdiqbal\LaravelPayments\Events;

use Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentRefunded
{
    use Dispatchable;

    public function __construct(
        public PaymentGatewayInterface $gateway,
        public string $transactionId,
        public float $amount
    ) {}

    /**
     * Get the gateway name
     */
    public function getGatewayName(): string
    {
        return $this->gateway->gatewayName();
    }

    /**
     * Get the transaction ID
     */
    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    /**
     * Get the refunded amount
     */
    public function getRefundedAmount(): float
    {
        return $this->amount;
    }

    /**
     * Get the gateway name in lowercase
     */
    public function getGateway(): string
    {
        return strtolower($this->gateway->gatewayName());
    }
}