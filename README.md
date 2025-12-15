# Laravel Payments Package

A comprehensive Laravel package for integrating multiple payment gateways into your application. This package provides a unified interface for processing payments through various payment providers including Stripe, PayPal, Razorpay, Paystack, and many more.

## Table of Contents

- [Features](#features)
- [Supported Gateways](#supported-gateways)
- [Installation](#installation)
- [Configuration](#configuration)
- [Basic Usage](#basic-usage)
- [Gateway Documentation](#gateway-documentation)
  - [Stripe](#stripe)
  - [PayPal](#paypal)
  - [Paystack](#paystack)
  - [Razorpay](#razorpay)
  - [PayTM](#paytm)
  - [Flutterwave](#flutterwave)
  - [SSLCommerz](#sslcommerz)
  - [Mollie](#mollie)
  - [SenangPay](#senangpay)
  - [bKash](#bkash)
  - [Mercado Pago](#mercado-pago)
  - [Cashfree](#cashfree)
  - [PayFast](#payfast)
  - [Skrill](#skrill)
  - [PhonePe](#phonepe)
  - [Telr](#telr)
  - [iyzico](#iyzico)
  - [Pesapal](#pesapal)
  - [Midtrans](#midtrans)
  - [MyFatoorah](#myfatoorah)
  - [EasyPaisa](#easypaisa)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## Features

- **Unified Interface**: Single API for all payment gateways
- **Multiple Gateways**: Support for 20+ payment gateways
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
composer require mdiqbal/laravel-payments
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --provider="Mdiqbal\LaravelPayments\PaymentsServiceProvider"
```

3. Add your payment gateway credentials to your `.env` file:

```env
# Example for Stripe
STRIPE_MODE=test
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

### Making a Payment

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

// Create a payment request
$paymentRequest = new PaymentRequest(
    amount: 100.00,
    currency: 'USD',
    description: 'Payment for Order #12345'
);

// Set optional parameters
$paymentRequest->setTransactionId('order_' . uniqid());
$paymentRequest->setReturnUrl(route('payment.success'));
$paymentRequest->setCancelUrl(route('payment.cancel'));

// Process payment with default gateway
$response = Payment::pay($paymentRequest);

// Or process with a specific gateway
$response = Payment::gateway('stripe')->pay($paymentRequest);

// Handle response
if ($response->isSuccess()) {
    if ($response->isRedirect()) {
        return redirect($response->getRedirectUrl());
    }

    $transactionId = $response->getTransactionId();
    // Save transaction ID to database
} else {
    $errorMessage = $response->getMessage();
    // Handle error
}
```

### Retrieving Payment Status

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

$transactionId = 'txn_1234567890';
$response = Payment::gateway('stripe')->retrievePayment($transactionId);

if ($response->isSuccess()) {
    $status = $response->getData()['status'];
    $amount = $response->getData()['amount'];
    // Update order status
}
```

### Processing Refunds

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

$transactionId = 'txn_1234567890';
$refundAmount = 50.00;
$reason = 'Customer requested refund';

try {
    $success = Payment::gateway('stripe')->refund($transactionId, $refundAmount, $reason);

    if ($success) {
        // Refund processed successfully
    }
} catch (\Exception $e) {
    // Handle refund failure
}
```

## Gateway Documentation

### Stripe
[View Full Documentation →](docs/stripe-integration.md)

Stripe is a global payment gateway accepting cards, digital wallets, and local payment methods. Ideal for international businesses.

**Quick Start:**
```php
$response = Payment::gateway('stripe')->pay($paymentRequest);
$clientSecret = $response->getData()['client_secret'];
```

### PayPal
[View Full Documentation →](docs/paypal-integration.md)

PayPal provides a trusted payment solution with support for PayPal accounts, cards, and alternative payment methods.

**Quick Start:**
```php
$response = Payment::gateway('paypal')->pay($paymentRequest);
$approvalUrl = $response->getRedirectUrl();
```

### Paystack
[View Full Documentation →](docs/paystack-integration.md)

Paystack is the leading payment gateway in Africa, supporting cards, bank transfers, and mobile money.

**Quick Start:**
```php
$response = Payment::gateway('paystack')->pay($paymentRequest);
$authorizationUrl = $response->getRedirectUrl();
```

### Razorpay
[View Full Documentation →](docs/razorpay-integration.md)

Razorpay is India's payment solution supporting UPI, cards, net banking, and popular wallets.

**Quick Start:**
```php
$response = Payment::gateway('razorpay')->pay($paymentRequest);
$orderId = $response->getData()['id'];
```

### PayTM
[View Full Documentation →](docs/paytm-integration.md)

PayTM is one of India's largest digital payment platforms supporting wallet, cards, and UPI payments.

**Quick Start:**
```php
$response = Payment::gateway('paytm')->pay($paymentRequest);
$txnToken = $response->getData()['txnToken'];
```

### Flutterwave
[View Full Documentation →](docs/flutterwave-integration.md)

Flutterwave powers payments across Africa with support for cards, mobile money, and bank transfers.

**Quick Start:**
```php
$response = Payment::gateway('flutterwave')->pay($paymentRequest);
$paymentLink = $response->getRedirectUrl();
```

### SSLCommerz
[View Full Documentation →](docs/sslcommerz-integration.md)

SSLCommerz is Bangladesh's leading payment gateway with support for cards, mobile banking, and bank transfers.

**Quick Start:**
```php
$response = Payment::gateway('sslcommerz')->pay($paymentRequest);
$gatewayUrl = $response->getRedirectUrl();
```

### Mollie
[View Full Documentation →](docs/mollie-integration.md)

Mollie is a European payment gateway supporting iDEAL, credit cards, Bancontact, and other local payment methods.

**Quick Start:**
```php
$response = Payment::gateway('mollie')->pay($paymentRequest);
$checkoutUrl = $response->getRedirectUrl();
```

### SenangPay
[View Full Documentation →](docs/senangpay-integration.md)

SenangPay is Malaysia's payment gateway supporting FPX, online banking, and credit cards.

**Quick Start:**
```php
$response = Payment::gateway('senangpay')->pay($paymentRequest);
$paymentUrl = $response->getRedirectUrl();
```

### bKash
[View Full Documentation →](docs/bkash-integration.md)

bKash is Bangladesh's largest mobile financial service providing mobile wallet payments.

**Quick Start:**
```php
$response = Payment::gateway('bkash')->pay($paymentRequest);
$paymentID = $response->getData()['paymentID'];
```

### Mercado Pago
[View Full Documentation →](docs/mercadopago-integration.md)

Mercado Pago is Latin America's leading payment platform supporting various local payment methods.

**Quick Start:**
```php
$response = Payment::gateway('mercadopago')->pay($paymentRequest);
$initPoint = $response->getData()['init_point'];
```

### Cashfree
[View Full Documentation →](docs/cashfree-integration.md)

Cashfree is an Indian payment gateway supporting UPI, cards, net banking, and wallets.

**Quick Start:**
```php
$response = Payment::gateway('cashfree')->pay($paymentRequest);
$paymentLink = $response->getRedirectUrl();
```

### PayFast
[View Full Documentation →](docs/payfast-integration.md)

PayFast is South Africa's payment gateway supporting cards, EFT, and various payment methods.

**Quick Start:**
```php
$response = Payment::gateway('payfast')->pay($paymentRequest);
$redirectUrl = $response->getRedirectUrl();
```

### Skrill
[View Full Documentation →](docs/skrill-integration.md)

Skrill is a global payment solution supporting wallet transfers, cards, and bank transfers.

**Quick Start:**
```php
$response = Payment::gateway('skrill')->pay($paymentRequest);
$sessionId = $response->getData()['sessionid'];
```

### PhonePe
[View Full Documentation →](docs/phonepe-integration.md)

PhonePe is India's UPI-based payment platform supporting UPI, cards, and wallets.

**Quick Start:**
```php
$response = Payment::gateway('phonepe')->pay($paymentRequest);
$paymentUrl = $response->getRedirectUrl();
```

### Telr
[View Full Documentation →](docs/telr-integration.md)

Telr is a Middle East payment gateway supporting cards, Knet, Sadad, and other local methods.

**Quick Start:**
```php
$response = Payment::gateway('telr')->pay($paymentRequest);
$orderUrl = $response->getRedirectUrl();
```

### iyzico
[View Full Documentation →](docs/iyzico-integration.md)

iyzico is Turkey's payment platform supporting cards, bank transfers, and digital wallets.

**Quick Start:**
```php
$response = Payment::gateway('iyzico')->pay($paymentRequest);
$paymentPageUrl = $response->getData()['paymentPageUrl'];
```

### Pesapal
[View Full Documentation →](docs/pesapal-integration.md)

Pesapal provides payment solutions across Africa with support for cards and mobile money.

**Quick Start:**
```php
$response = Payment::gateway('pesapal')->pay($paymentRequest);
$redirectUrl = $response->getRedirectUrl();
```

### Midtrans
[View Full Documentation →](docs/midtrans-integration.md)

Midtrans is Indonesia's payment gateway supporting cards, bank transfers, and e-wallets.

**Quick Start:**
```php
$response = Payment::gateway('midtrans')->pay($paymentRequest);
$redirectUrl = $response->getRedirectUrl();
```

### MyFatoorah
[View Full Documentation →](docs/myfatoorah-integration.md)

MyFatoorah is a Middle East payment platform supporting Knet, Benefit, and other local methods.

**Quick Start:**
```php
$response = Payment::gateway('myfatoorah')->pay($paymentRequest);
$invoiceURL = $response->getData()['InvoiceURL'];
```

### EasyPaisa
[View Full Documentation →](docs/easypaisa-integration.md)

EasyPaisa is Pakistan's mobile banking platform supporting wallet payments and bank transfers.

**Quick Start:**
```php
$response = Payment::gateway('easypaisa')->pay($paymentRequest);
$transactionUrl = $response->getRedirectUrl();
```

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
STRIPE_MODE=test
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