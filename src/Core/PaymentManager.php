<?php

namespace Mdiqbal\LaravelPayments\Core;

use Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\Exceptions\GatewayNotFoundException;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;

class PaymentManager
{
    protected array $gateways = [];
    protected ?string $defaultGateway = null;

    public function __construct(
        private GatewayResolver $resolver
    ) {}

    /**
     * Set default gateway
     */
    public function gateway(string $name): PaymentGatewayInterface
    {
        if (!isset($this->gateways[$name])) {
            $this->gateways[$name] = $this->resolver->resolve($name);
        }

        return $this->gateways[$name];
    }

    /**
     * Process payment using specified gateway
     */
    public function pay(string $gateway, PaymentRequest $request): PaymentResponse
    {
        try {
            $gatewayInstance = $this->gateway($gateway);
            return $gatewayInstance->pay($request);
        } catch (\Exception $e) {
            throw new PaymentException("Payment failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Verify webhook/callback for specified gateway
     */
    public function verify(string $gateway, array $payload): PaymentResponse
    {
        try {
            $gatewayInstance = $this->gateway($gateway);
            return $gatewayInstance->verify($payload);
        } catch (\Exception $e) {
            throw new PaymentException("Verification failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Process refund using specified gateway
     */
    public function refund(string $gateway, string $transactionId, float $amount): bool
    {
        try {
            $gatewayInstance = $this->gateway($gateway);

            if (!$gatewayInstance->supportsRefund()) {
                throw new PaymentException("Gateway {$gateway} does not support refunds");
            }

            return $gatewayInstance->refund($transactionId, $amount);
        } catch (\Exception $e) {
            throw new PaymentException("Refund failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Check if gateway supports refunds
     */
    public function supportsRefund(string $gateway): bool
    {
        try {
            $gatewayInstance = $this->gateway($gateway);
            return $gatewayInstance->supportsRefund();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get available gateways
     */
    public function getAvailableGateways(): array
    {
        return $this->resolver->getAvailableGateways();
    }

    /**
     * Check if gateway exists
     */
    public function hasGateway(string $gateway): bool
    {
        return $this->resolver->hasGateway($gateway);
    }

    /**
     * Set default gateway
     */
    public function setDefaultGateway(string $gateway): self
    {
        if (!$this->hasGateway($gateway)) {
            throw new GatewayNotFoundException("Gateway {$gateway} not found");
        }

        $this->defaultGateway = $gateway;
        return $this;
    }

    /**
     * Get default gateway
     */
    public function getDefaultGateway(): ?string
    {
        return $this->defaultGateway ?? config('payments.default');
    }

    /**
     * Process payment using default gateway
     */
    public function payWithDefault(PaymentRequest $request): PaymentResponse
    {
        $gateway = $this->getDefaultGateway();

        if (!$gateway) {
            throw new PaymentException("No default gateway configured");
        }

        return $this->pay($gateway, $request);
    }
}