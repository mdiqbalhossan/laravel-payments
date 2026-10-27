# Laravel Payments Package

A comprehensive Laravel package for integrating multiple payment gateways into your application. This package provides a unified interface for processing payments through various payment providers including Stripe, PayPal, Razorpay, Paystack, and many more.

## Table of Contents

- [Features](#features)
- [Supported Gateways](#supported-gateways)
- [Installation](#installation)
- [Upgrading to 3.x](#upgrading-to-3x)
- [Configuration](#configuration)
- [Basic Usage](#basic-usage)
- [Gateway reference](#gateway-reference)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## Features

- **Unified Interface**: Single API for all payment gateways
- **Multiple Gateways**: Support for 21 payment gateways
- **Secure**: Built with security best practices
- **Flexible**: Easy to extend with new gateways
- **Laravel Ready**: Built specifically for Laravel
- **Webhook Support**: Built-in webhook handling
- **Error Handling**: Comprehensive error management
- **Test Friendly**: Full testing support

## Supported Gateways

| Gateway | Regions | Supported Methods | Documentation |
|---------|---------|------------------|---------------|
| **Stripe** | Global | Cards, Apple Pay, Google Pay, ACH | [Guide](docs/stripe-integration.md) |
| **PayPal** | Global | PayPal, Cards, Venmo | [Guide](docs/paypal-integration.md) |
| **Paystack** | Africa | Cards, Bank Transfer, USSD | [Guide](docs/paystack-integration.md) |
| **Razorpay** | India | Cards, UPI, NetBanking, Wallets | [Guide](docs/razorpay-integration.md) |
| **PayTM** | India | Wallet, Cards, NetBanking, UPI | [Guide](docs/paytm-integration.md) |
| **Flutterwave** | Africa | Cards, Mobile Money, Bank Transfer | [Guide](docs/flutterwave-integration.md) |
| **SSLCommerz** | Bangladesh | Cards, Mobile Banking, Bank Transfer | [Guide](docs/sslcommerz-integration.md) |
| **Mollie** | Europe | Cards, iDEAL, Bank Transfer, Klarna | [Guide](docs/mollie-integration.md) |
| **SenangPay** | Malaysia | Cards, FPX, Online Banking | [Guide](docs/senangpay-integration.md) |
| **bKash** | Bangladesh | Mobile Wallet | [Guide](docs/bkash-integration.md) |
| **Mercado Pago** | Latin America | Cards, Bank Transfer, Wallets | [Guide](docs/mercadopago-integration.md) |
| **Cashfree** | India | Cards, UPI, NetBanking, Wallets | [Guide](docs/cashfree-integration.md) |
| **PayFast** | South Africa | Cards, EFT, Zapper, Masterpass | [Guide](docs/payfast-integration.md) |
| **Skrill** | Global | Wallet, Cards, Bank Transfer | [Guide](docs/skrill-integration.md) |
| **PhonePe** | India | UPI, Cards, Wallets | [Guide](docs/phonepe-integration.md) |
| **Telr** | Middle East | Cards, Knet, Sadad, Fawry | [Guide](docs/telr-integration.md) |
| **iyzico** | Turkey | Cards, Bank Transfer, Wallets | [Guide](docs/iyzico-integration.md) |
| **Pesapal** | Africa | Cards, Mobile Money, Bank Transfer | [Guide](docs/pesapal-integration.md) |
| **Midtrans** | Indonesia | Cards, Bank Transfer, E-Wallets | [Guide](docs/midtrans-integration.md) |
| **MyFatoorah** | Middle East | Cards, Knet, Benefit, Sadad | [Guide](docs/myfatoorah-integration.md) |
| **EasyPaisa** | Pakistan | Mobile Wallet, Bank Transfer | [Guide](docs/easypaisa-integration.md) |

## Installation

1. Install the package via Composer:

```bash
composer require mdiqbal/laravel-payments:^3.0
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --provider="Mdiqbal\LaravelPayments\PaymentsServiceProvider"
```

3. Add your payment gateway credentials to your `.env` file:

```env
# Example for Stripe
STRIPE_MODE=sandbox  # sandbox | live
STRIPE_SANDBOX_SECRET=your_sandbox_secret_key
STRIPE_SANDBOX_KEY=your_sandbox_publishable_key
STRIPE_LIVE_SECRET=your_live_secret_key
STRIPE_LIVE_KEY=your_live_publishable_key
STRIPE_WEBHOOK_SECRET=your_webhook_secret

# Example for PayPal
PAYPAL_MODE=sandbox
PAYPAL_SANDBOX_CLIENT_ID=your_sandbox_client_id
PAYPAL_SANDBOX_CLIENT_SECRET=your_sandbox_client_secret
PAYPAL_LIVE_CLIENT_ID=your_live_client_id
PAYPAL_LIVE_CLIENT_SECRET=your_live_client_secret

# Example for Mercado Pago
MERCADOPAGO_MODE=sandbox
MERCADOPAGO_SANDBOX_ACCESS_TOKEN=your_sandbox_access_token
MERCADOPAGO_LIVE_ACCESS_TOKEN=your_live_access_token
MERCADOPAGO_COUNTRY=MX
MERCADOPAGO_WEBHOOK_SECRET=your_webhook_secret
```

## Upgrading to 3.x

Version 3.0 fixes the issues found in the QA pass over all 21 gateways (see
[QA_REPORT.md](QA_REPORT.md) and [CHANGELOG.md](CHANGELOG.md)). To update an existing project:

```bash
# 1. Update the package
composer require mdiqbal/laravel-payments:^3.0

# 2. Remove the gateway SDKs 2.x pulled in (3.0 no longer needs them).
#    Skip any your own code still uses.
composer remove srmklive/paypal flutterwavedev/flutterwave-v3 raziul/sslcommerz-laravel \
    mollie/laravel-mollie sabitahmad/laravel-bkash loveycom/cashfree yogeshgupta/phonepe-laravel \
    njoguamos/laravel-pesapal midtrans/midtrans-php myfatoorah/laravel-package zfhassaan/easypaisa

# 3. Re-publish the config (back up config/payments.php first if you changed it)
php artisan vendor:publish --provider="Mdiqbal\LaravelPayments\PaymentsServiceProvider" --tag=config --force

# 4. Clear cached config
php artisan config:clear
```

If you only want bug fixes inside the 3.x line later, `composer update mdiqbal/laravel-payments` is enough.

**Breaking changes to check in your code:**

- Credentials must be in the `sandbox` / `live` blocks of `config/payments.php`; missing ones now
  throw `PaymentException` instead of falling back to fake "mock" responses.
- `{GATEWAY}_MODE` must be `sandbox` or `live` (`test`/`production` are accepted as aliases).
- Webhooks are signature-checked. Add the new `.env` keys for the gateways you use:
  `STRIPE_WEBHOOK_SECRET`, `PAYPAL_WEBHOOK_SECRET` (the PayPal Webhook ID), `RAZORPAY_WEBHOOK_SECRET`,
  `FLUTTERWAVE_SECRET_HASH`, `SKRILL_*_SECRET_WORD`, `EASYPAISA_*_ACCOUNT_NUM`,
  `PHONEPE_*_MERCHANT_ID` / `PHONEPE_*_SALT_KEY` / `PHONEPE_*_SALT_INDEX` (the old PhonePe names still work).
- Use `Payment::pay($gateway, $request)` / `Payment::verify($gateway, $payload)`; see the
  [Gateway reference](#gateway-reference) for the payload each gateway's `verify()` expects.
- Check `$response->matchesAmount($order->total, $order->currency)` before marking an order paid.
- Gateway-specific helpers that returned fake data (e.g. SSLCommerz `createSubscription()`,
  Flutterwave payment-link and transfer helpers) were removed.
- senangPay, PayFast, Skrill, Telr and Easypaisa report `supportsRefund() === false`.

## Configuration

The package configuration file is located at `config/payments.php`. Here you can configure all supported gateways:

```php
return [
    'default' => env('PAYMENT_DEFAULT_GATEWAY', 'stripe'),

    'gateways' => [
        'stripe' => [
            'mode' => env('STRIPE_MODE', 'sandbox'),
            'sandbox' => [
                'secret_key' => env('STRIPE_SANDBOX_SECRET'),
                'api_key' => env('STRIPE_SANDBOX_KEY'),
            ],
            'live' => [
                'secret_key' => env('STRIPE_LIVE_SECRET'),
                'api_key' => env('STRIPE_LIVE_KEY'),
            ],
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],

        'paypal' => [
            'mode' => env('PAYPAL_MODE', 'sandbox'),
            'sandbox' => [
                'client_id' => env('PAYPAL_SANDBOX_CLIENT_ID'),
                'client_secret' => env('PAYPAL_SANDBOX_CLIENT_SECRET'),
            ],
            'live' => [
                'client_id' => env('PAYPAL_LIVE_CLIENT_ID'),
                'client_secret' => env('PAYPAL_LIVE_CLIENT_SECRET'),
            ],
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        ],

        // ... other gateways
    ],
];
```

## Basic Usage

### 1. Start a payment

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$request = new PaymentRequest(
    amount: 1500.00,
    currency: 'BDT',
    description: 'Order #1001',
    orderId: (string) $order->id,          // your reference (tran_id / tx_ref / m_payment_id ...)
    email: $user->email,
    customer: ['name' => $user->name, 'phone' => $user->phone],
    returnUrl: route('payment.return', 'sslcommerz'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/sslcommerz'), // IPN / webhook (package route)
);

$response = Payment::pay('sslcommerz', $request);   // throws PaymentException on failure

// Store the gateway transaction id with the order: you need it for verify()/refund()
$order->update(['gateway_txn' => $response->transactionId]);

if ($response->requiresRedirect()) {
    // Some gateways need a form POST instead of a GET redirect:
    // $response->getData('redirect_method') === 'POST' with $response->getData('form_params')
    return redirect()->away($response->redirectUrl);
}

// Stripe (client_secret) and Cashfree (payment_session_id) return data for their JS SDKs
```

### 2. Verify when the customer returns

`verify()` never trusts the callback data alone: it checks the gateway signature and/or
re-reads the transaction from the provider's API.

```php
public function return(Request $request, string $gateway)
{
    $result = Payment::verify($gateway, $request->all());

    // Always compare the confirmed amount with your order before fulfilling it
    if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
        $order->markPaid($result->transactionId);
    }
}
```

### 3. Webhooks / IPN

The package registers `POST /payments/webhook/{gateway}`. Each gateway verifies its own
signature header against the raw request body (configure the secrets below), then
`Mdiqbal\LaravelPayments\Events\WebhookHandled` is dispatched:

```php
Event::listen(WebhookHandled::class, function (WebhookHandled $event) {
    if ($event->response->success) {
        // look up the order by $event->response->transactionId and check matchesAmount()
    }
});
```

Exclude the route from CSRF if you use the `web` middleware group. Responses: `200`
accepted, `400` rejected/not paid, `401` bad signature.

### 4. Refunds

```php
if (Payment::supportsRefund('stripe')) {
    Payment::refund('stripe', $paymentIntentId, 50.00);   // 0 = full refund where supported
}
```

## Gateway reference

| Gateway | Transaction id returned by `pay()` | `verify()` input | Webhook secret / check | Refund |
|---|---|---|---|---|
| Stripe | PaymentIntent id (`client_secret` in data) | `payment_intent` or `session_id` | `webhook_secret` (whsec) | ✔ |
| PayPal | Order id (approve link) | `token` (order id); captures APPROVED orders | `webhook_secret` = Webhook ID (verified via PayPal API) | ✔ capture id or order id |
| Razorpay | Order id (use with Checkout.js) | `razorpay_order_id`, `razorpay_payment_id`, `razorpay_signature` | `webhook_secret` | ✔ payment id |
| Paystack | reference | `reference` | secret key (`x-paystack-signature`) | ✔ |
| Paytm | orderId (POST `form_params` to payment page) | callback POST (CHECKSUMHASH) | — | ✔ |
| Flutterwave | tx_ref | `transaction_id` / `tx_ref` | `secret_hash` (`verif-hash`) | ✔ |
| SSLCommerz | tran_id | callback POST (`val_id`) | IPN `verify_sign` | ✔ |
| Mollie | `tr_...` id | `id` | not signed; API re-check | ✔ |
| senangPay | order_id | return/callback params (`hash`) | HMAC-SHA256 hash | ✘ |
| bKash | paymentID | `paymentID`, `status` (executes payment) | API re-check | ✔ paymentID |
| Mercado Pago | order id (preference) | `payment_id` / `data.id` | optional `webhook_secret` (`x-signature`) | ✔ payment id |
| Cashfree | order_id (`payment_session_id` for JS SDK) | `order_id` | secret key (`x-webhook-signature`) | ✔ |
| PayFast | m_payment_id (POST `form_params`) | ITN POST | ITN signature + server validation | ✘ |
| Skrill | transaction_id | status_url POST (`md5sig`) | `secret_word` | ✘ |
| PhonePe | merchantTransactionId | `merchantTransactionId` | salt key (`X-VERIFY`) | ✔ |
| Telr | order ref (store it!) | `order_ref` | API re-check | ✘ |
| iyzico | checkout token | `token` | API re-check | ✔ paymentId |
| Pesapal | order_tracking_id | `OrderTrackingId` | API re-check (set `ipn_id`) | ✔ |
| Midtrans | order_id (`snap_token` in data) | `order_id` | server key (`signature_key`) | ✔ |
| MyFatoorah | your order id (InvoiceId in data) | `paymentId` | optional `webhook_secret` | ✔ InvoiceId |
| Easypaisa | orderRefNum (POST `form_params`) | `orderRefNumber` | API re-check | ✘ |

Credentials always live in the `sandbox` / `live` block of `config/payments.php`;
missing credentials throw a `PaymentException` (there are no silent mock fallbacks).
Test every gateway you enable against its sandbox before going live.

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run specific test
composer test -- --filter StripePaymentTest

# Generate coverage report
composer test -- --coverage
```

### Test Configuration

Set test mode in your `.env.testing` file:

```env
PAYMENT_DEFAULT_GATEWAY=stripe
STRIPE_MODE=sandbox  # sandbox | live
STRIPE_SANDBOX_SECRET=sk_test_xxxxxxxxxxxxxx
STRIPE_SANDBOX_KEY=pk_test_xxxxxxxxxxxxxx
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request. For major changes, please open an issue first to discuss what you would like to change.

### Development Setup

1. Clone the repository
2. Install dependencies: `composer install`
3. Run tests: `composer test`

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).

## Support

- 📧 Email: support@laravel-payments.com
- 🐛 Issues: [GitHub Issues](https://github.com/your-username/laravel-payments/issues)
- 📖 Documentation: [Full Documentation](https://laravel-payments.com/docs)
- 💬 Discord: [Join our Discord](https://discord.gg/laravel-payments)

## Security

If you discover any security related issues, please email security@laravel-payments.com instead of using the issue tracker.