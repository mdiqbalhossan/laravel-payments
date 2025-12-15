<?php

namespace Mdiqbal\LaravelPayments\Core;

use Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface;
use Mdiqbal\LaravelPayments\Exceptions\GatewayNotFoundException;
use Illuminate\Support\Str;

class GatewayResolver
{
    protected array $gatewayClasses = [
        'paypal' => \Mdiqbal\LaravelPayments\Gateways\Paypal\PaypalGateway::class,
        'stripe' => \Mdiqbal\LaravelPayments\Gateways\Stripe\StripeGateway::class,
        'razorpay' => \Mdiqbal\LaravelPayments\Gateways\Razorpay\RazorpayGateway::class,
        'paystack' => \Mdiqbal\LaravelPayments\Gateways\Paystack\PaystackGateway::class,
        'paytm' => \Mdiqbal\LaravelPayments\Gateways\Paytm\PaytmGateway::class,
        'flutterwave' => \Mdiqbal\LaravelPayments\Gateways\Flutterwave\FlutterwaveGateway::class,
        'sslcommerz' => \Mdiqbal\LaravelPayments\Gateways\Sslcommerz\SslcommerzGateway::class,
        'mollie' => \Mdiqbal\LaravelPayments\Gateways\Mollie\MollieGateway::class,
        'senangpay' => \Mdiqbal\LaravelPayments\Gateways\Senangpay\SenangpayGateway::class,
        'bkash' => \Mdiqbal\LaravelPayments\Gateways\Bkash\BkashGateway::class,
        'mercadopago' => \Mdiqbal\LaravelPayments\Gateways\Mercadopago\MercadopagoGateway::class,
        'cashfree' => \Mdiqbal\LaravelPayments\Gateways\Cashfree\CashfreeGateway::class,
        'payfast' => \Mdiqbal\LaravelPayments\Gateways\Payfast\PayfastGateway::class,
        'skrill' => \Mdiqbal\LaravelPayments\Gateways\Skrill\SkrillGateway::class,
        'phonepe' => \Mdiqbal\LaravelPayments\Gateways\PhonePe\PhonePeGateway::class,
        'telr' => \Mdiqbal\LaravelPayments\Gateways\Telr\TelrGateway::class,
        'iyzico' => \Mdiqbal\LaravelPayments\Gateways\Iyzico\IyzicoGateway::class,
        'pesapal' => \Mdiqbal\LaravelPayments\Gateways\Pesapal\PesapalGateway::class,
        'midtrans' => \Mdiqbal\LaravelPayments\Gateways\Midtrans\MidtransGateway::class,
        'myfatoorah' => \Mdiqbal\LaravelPayments\Gateways\MyFatoorah\MyFatoorahGateway::class,
        'easypaisa' => \Mdiqbal\LaravelPayments\Gateways\EasyPaisa\EasyPaisaGateway::class,
    ];

    protected array $instances = [];

    /**
     * Resolve gateway instance by name
     */
    public function resolve(string $name): PaymentGatewayInterface
    {
        $name = strtolower($name);

        if (!isset($this->gatewayClasses[$name])) {
            throw new GatewayNotFoundException("Gateway '{$name}' not found");
        }

        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $class = $this->gatewayClasses[$name];

        if (!class_exists($class)) {
            throw new GatewayNotFoundException("Gateway class '{$class}' not found");
        }

        $instance = new $class($this->getGatewayConfig($name));

        if (!$instance instanceof PaymentGatewayInterface) {
            throw new GatewayNotFoundException("Gateway '{$class}' must implement PaymentGatewayInterface");
        }

        $this->instances[$name] = $instance;

        return $instance;
    }

    /**
     * Get configuration for gateway
     */
    protected function getGatewayConfig(string $name): array
    {
        $config = config("payments.gateways.{$name}", []);

        // Set default mode
        $config['mode'] = $config['mode'] ?? config('payments.mode', 'sandbox');

        return $config;
    }

    /**
     * Get available gateways
     */
    public function getAvailableGateways(): array
    {
        return array_keys($this->gatewayClasses);
    }

    /**
     * Check if gateway exists
     */
    public function hasGateway(string $name): bool
    {
        return isset($this->gatewayClasses[strtolower($name)]);
    }

    /**
     * Register new gateway
     */
    public function registerGateway(string $name, string $class): void
    {
        $name = strtolower($name);
        $this->gatewayClasses[$name] = $class;

        // Remove cached instance if exists
        unset($this->instances[$name]);
    }

    /**
     * Get gateway class by name
     */
    public function getGatewayClass(string $name): ?string
    {
        return $this->gatewayClasses[strtolower($name)] ?? null;
    }

    /**
     * Clear cached instances
     */
    public function clearCache(): void
    {
        $this->instances = [];
    }

    /**
     * Get all gateway classes
     */
    public function all(): array
    {
        return $this->gatewayClasses;
    }
}