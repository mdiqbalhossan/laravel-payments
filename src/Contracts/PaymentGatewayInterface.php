<?php

namespace Mdiqbal\LaravelPayments\Contracts;

use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;

interface PaymentGatewayInterface
{
    /**
     * Process a payment request
     *
     * @param PaymentRequest $request
     * @return PaymentResponse
     */
    public function pay(PaymentRequest $request): PaymentResponse;

    /**
     * Verify a webhook or callback payload
     *
     * @param array $payload
     * @return PaymentResponse
     */
    public function verify(array $payload): PaymentResponse;

    /**
     * Refund a transaction
     *
     * @param string $transactionId
     * @param float $amount
     * @return bool
     */
    public function refund(string $transactionId, float $amount): bool;

    /**
     * Check if the gateway supports refunds
     *
     * @return bool
     */
    public function supportsRefund(): bool;

    /**
     * Get the gateway name
     *
     * @return string
     */
    public function gatewayName(): string;

    /**
     * Get gateway configuration
     *
     * @param string|null $key
     * @param string|null $default
     * @return mixed
     */
    public function getConfig(?string $key = null, ?string $default = null): mixed;

    /**
     * Set gateway configuration
     *
     * @param array $config
     * @return self
     */
    public function setConfig(array $config): self;

    /**
     * Get the current mode (sandbox/live)
     *
     * @return string
     */
    public function getMode(): string;

    /**
     * Set the mode (sandbox/live)
     *
     * @param string $mode
     * @return self
     */
    public function setMode(string $mode): self;
}