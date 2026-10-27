<?php

namespace Mdiqbal\LaravelPayments\Facades;

use Illuminate\Support\Facades\Facade;
use Mdiqbal\LaravelPayments\Core\PaymentContext;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;

/**
 * @method static \Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface gateway(string $name)
 *
 * @see \Mdiqbal\LaravelPayments\Core\PaymentManager
 */
class Payment extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'payment';
    }

    /**
     * Create a new payment context
     */
    public static function context(): PaymentContext
    {
        return app(PaymentContext::class);
    }

    /**
     * Process payment with fluent interface
     */
    public static function pay(string $gateway, PaymentRequest $request): PaymentResponse
    {
        return app('payment')->pay($gateway, $request);
    }

    /**
     * Verify webhook/callback
     */
    public static function verify(string $gateway, array $payload): PaymentResponse
    {
        return app('payment')->verify($gateway, $payload);
    }

    /**
     * Process refund
     */
    public static function refund(string $gateway, string $transactionId, float $amount): bool
    {
        return app('payment')->refund($gateway, $transactionId, $amount);
    }

    /**
     * Check if gateway supports refunds
     */
    public static function supportsRefund(string $gateway): bool
    {
        return app('payment')->supportsRefund($gateway);
    }

    /**
     * Get all available gateways
     */
    public static function getAvailableGateways(): array
    {
        return app('payment')->getAvailableGateways();
    }

    /**
     * Check if gateway exists
     */
    public static function hasGateway(string $gateway): bool
    {
        return app('payment')->hasGateway($gateway);
    }

    /**
     * Set default gateway
     */
    public static function setDefaultGateway(string $gateway): void
    {
        app('payment')->setDefaultGateway($gateway);
    }

    /**
     * Get default gateway
     */
    public static function getDefaultGateway(): ?string
    {
        return app('payment')->getDefaultGateway();
    }
}