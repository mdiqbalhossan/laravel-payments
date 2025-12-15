<?php

namespace Mdiqbal\LaravelPayments\Core;

use Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Events\PaymentInitiated;
use Mdiqbal\LaravelPayments\Events\PaymentSucceeded;
use Mdiqbal\LaravelPayments\Events\PaymentFailed;
use Mdiqbal\LaravelPayments\Events\PaymentRefunded;

class PaymentContext
{
    protected ?PaymentGatewayInterface $gateway = null;
    protected ?PaymentRequest $request = null;

    public function __construct(
        private PaymentManager $manager
    ) {}

    /**
     * Set the gateway for this context
     */
    public function using(string $gateway): self
    {
        $this->gateway = $this->manager->gateway($gateway);
        return $this;
    }

    /**
     * Set the payment request
     */
    public function with(PaymentRequest $request): self
    {
        $this->request = $request;
        return $this;
    }

    /**
     * Execute the payment
     */
    public function execute(): PaymentResponse
    {
        if (!$this->gateway) {
            throw new \InvalidArgumentException('Gateway not set. Call using() first.');
        }

        if (!$this->request) {
            throw new \InvalidArgumentException('Payment request not set. Call with() first.');
        }

        // Fire payment initiated event
        event(new PaymentInitiated($this->gateway, $this->request));

        try {
            $response = $this->gateway->pay($this->request);

            if ($response->success) {
                event(new PaymentSucceeded($this->gateway, $this->request, $response));
            } else {
                event(new PaymentFailed($this->gateway, $this->request, $response));
            }

            return $response;
        } catch (\Exception $e) {
            $response = PaymentResponse::failure($e->getMessage());
            event(new PaymentFailed($this->gateway, $this->request, $response));
            throw $e;
        }
    }

    /**
     * Execute refund
     */
    public function refund(string $transactionId, float $amount): bool
    {
        if (!$this->gateway) {
            throw new \InvalidArgumentException('Gateway not set. Call using() first.');
        }

        $result = $this->gateway->refund($transactionId, $amount);

        if ($result) {
            event(new PaymentRefunded($this->gateway, $transactionId, $amount));
        }

        return $result;
    }

    /**
     * Get current gateway
     */
    public function getGateway(): ?PaymentGatewayInterface
    {
        return $this->gateway;
    }

    /**
     * Get current request
     */
    public function getRequest(): ?PaymentRequest
    {
        return $this->request;
    }

    /**
     * Reset context
     */
    public function reset(): self
    {
        $this->gateway = null;
        $this->request = null;
        return $this;
    }

    /**
     * Execute payment using fluent interface
     */
    public static function make(PaymentManager $manager): self
    {
        return new self($manager);
    }
}