<?php

namespace Mdiqbal\LaravelPayments\Facades;

use Illuminate\Support\Facades\Facade;
use Mdiqbal\LaravelPayments\Core\PaymentContext;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;

/**
 * @method static \Mdiqbal\LaravelPayments\Gateways\Paypal\PaypalGateway paypal()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Stripe\StripeGateway stripe()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Razorpay\RazorpayGateway razorpay()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Paystack\PaystackGateway paystack()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Paytm\PaytmGateway paytm()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Flutterwave\FlutterwaveGateway flutterwave()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Sslcommerz\SslcommerzGateway sslcommerz()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Mollie\MollieGateway mollie()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Senangpay\SenangpayGateway senangpay()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Bkash\BkashGateway bkash()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Mercadopago\MercadopagoGateway mercadopago()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Cashfree\CashfreeGateway cashfree()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Payfast\PayfastGateway payfast()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Skrill\SkrillGateway skrill()
 * @method static \Mdiqbal\LaravelPayments\Gateways\PhonePe\PhonePeGateway phonepe()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Telr\TelrGateway telr()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Iyzico\IyzicoGateway iyzico()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Pesapal\PesapalGateway pesapal()
 * @method static \Mdiqbal\LaravelPayments\Gateways\Midtrans\MidtransGateway midtrans()
 * @method static \Mdiqbal\LaravelPayments\Gateways\MyFatoorah\MyFatoorahGateway myfatoorah()
 * @method static \Mdiqbal\LaravelPayments\Gateways\EasyPaisa\EasyPaisaGateway easypaisa()
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
        return static::gateway($gateway)->pay($request);
    }

    /**
     * Verify webhook/callback
     */
    public static function verify(string $gateway, array $payload): PaymentResponse
    {
        return static::gateway($gateway)->verify($payload);
    }

    /**
     * Process refund
     */
    public static function refund(string $gateway, string $transactionId, float $amount): bool
    {
        return static::gateway($gateway)->refund($transactionId, $amount);
    }

    /**
     * Check if gateway supports refunds
     */
    public static function supportsRefund(string $gateway): bool
    {
        return static::gateway($gateway)->supportsRefund();
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