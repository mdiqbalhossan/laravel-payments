# Mollie Integration Guide

This guide explains how to integrate Mollie payment gateway with the Laravel Payments package.

## Overview

Mollie is a leading European payment gateway supporting multiple payment methods across 35+ countries. It offers:
- Credit/Debit Cards (Visa, Mastercard, American Express, Maestro)
- Local payment methods (iDEAL, Bancontact, SOFORT, giropay, EPS, etc.)
- Buy Now, Pay Later (Klarna, in3, Capayable)
- Digital wallets (PayPal, Apple Pay, Google Pay)
- Bank transfers and SEPA Direct Debit
- Vouchers and gift cards

## Installation

1. Install the Mollie package via Composer:

```bash
composer require mollie/laravel-mollie
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --tag=mollie-config
```

3. Publish the migration file (optional, for storing webhooks):

```bash
php artisan vendor:publish --tag=mollie-migrations
```

## Configuration

Add your Mollie credentials to your `.env` file:

```env
MOLLIE_KEY=your_mollie_api_key_here
MOLLIE_REDIRECT_URL=https://yoursite.com/payment/success
MOLLIE_WEBHOOK_URL=https://yoursite.com/mollie/webhook
```

You can obtain these credentials from your [Mollie dashboard](https://www.mollie.com/dashboard/).

### API Key Types

Mollie provides different types of API keys:

- **Live/Test API Keys**: For processing live or test payments
- **Organization Token**: For managing multiple profiles and accessing organization-wide features
- **Profile API Keys**: For accessing specific profile information

Add the appropriate key to your `.env`:

```env
# For test mode
MOLLIE_KEY=test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# For production
MOLLIE_KEY=live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

## Basic Usage

### Initialize Payment

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'EUR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'redirect_url' => 'https://yoursite.com/payment/callback',
    'payment_options' => ['ideal', 'creditcard', 'paypal'],
    'customer' => [
        'name' => 'John Doe',
        'email' => 'customer@example.com',
        'phone' => '+31201234567',
        'address' => '123 Main St',
        'city' => 'Amsterdam',
        'country' => 'NL',
        'postal_code' => '1012JS'
    ],
    'metadata' => [
        'order_id' => 'ORD123456',
        'user_id' => 789
    ]
];

$payment = Payment::gateway('mollie')->pay($paymentRequest);
```

### Verify Payment

```php
$verification = Payment::gateway('mollie')->verify($transactionId);

if ($verification['success']) {
    $status = $verification['status'];
    $amount = $verification['amount'];

    if ($status === 'paid') {
        // Payment was successful
    }
}
```

### Process Refund

```php
$refundData = [
    'payment_id' => 'tr_xxxxxxxxxxxxxxxx',
    'amount' => 50.00,
    'currency' => 'EUR',
    'reason' => 'Customer requested refund'
];

$refund = Payment::gateway('mollie')->refund($refundData);
```

## Advanced Features

### Create Customer

```php
$customerData = [
    'name' => 'John Doe',
    'email' => 'customer@example.com',
    'locale' => 'nl_NL',
    'metadata' => [
        'user_id' => 789,
        'source' => 'website'
    ]
];

$customer = Payment::gateway('mollie')->createCustomer($customerData);
```

### Create Subscription

```php
$subscriptionData = [
    'customer_id' => 'cst_xxxxxxxxxxxxxxxx',
    'amount' => 25.00,
    'currency' => 'EUR',
    'interval' => '1 month',
    'description' => 'Monthly subscription',
    'start_date' => '2024-02-01',
    'times' => 12,
    'mandate_id' => 'mdt_xxxxxxxxxxxxxxxx',
    'metadata' => [
        'plan_id' => 'basic'
    ]
];

$subscription = Payment::gateway('mollie')->createSubscription($subscriptionData);
```

### Create Payment Link

```php
$linkData = [
    'amount' => 100.00,
    'currency' => 'EUR',
    'description' => 'Payment for invoice #123',
    'redirect_url' => 'https://yoursite.com/success',
    'payment_options' => ['ideal', 'bancontact', 'creditcard'],
    'metadata' => [
        'invoice_id' => 'INV123456'
    ]
];

$paymentLink = Payment::gateway('mollie')->createPaymentLink($linkData);
```

### Get Available Payment Methods

```php
$methodsParams = [
    'amount' => 100.00,
    'currency' => 'EUR',
    'locale' => 'nl_NL',
    'billing_country' => 'NL'
];

$availableMethods = Payment::gateway('mollie')->getAvailablePaymentMethods($methodsParams);
```

### Create Mandate for Recurring Payments

```php
$mandateData = [
    'method' => 'directdebit',
    'consumer_account' => 'NL91ABNA0417164300',
    'consumer_name' => 'John Doe',
    'mandate_reference' => 'MANDATE_' . time(),
    'signature_date' => '2024-01-01'
];

$mandate = Payment::gateway('mollie')->createMandate($customerId, $mandateData);
```

### Get Customer Mandates

```php
$mandates = Payment::gateway('mollie')->getCustomerMandates($customerId);
```

## Webhook Setup

1. Set up your webhook endpoint in the Mollie dashboard

2. Create a route to handle webhooks:

```php
// routes/web.php
Route::post('/mollie/webhook', [MollieWebhookController::class, 'handleWebhook']);
```

3. Create the webhook controller:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class MollieWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        // Mollie webhooks don't contain the full payload, only the payment ID
        $paymentId = $request->input('id');

        if (!$paymentId) {
            return response()->json(['error' => 'Missing payment ID'], 400);
        }

        // Get full payment details
        $molliePayment = Mollie::api()->payments->get($paymentId, [
            'embed' => ['refunds', 'chargebacks']
        ]);

        // Process using our gateway
        $result = Payment::gateway('mollie')->processWebhook(['id' => $paymentId]);

        if ($result['success']) {
            $eventType = $result['event_type'];
            $transactionId = $result['transaction_id'];

            switch ($eventType) {
                case 'payment.paid':
                    $this->handlePaidPayment($result);
                    break;

                case 'payment.authorized':
                    $this->handleAuthorizedPayment($result);
                    break;

                case 'payment.failed':
                    $this->handleFailedPayment($result);
                    break;

                case 'payment.expired':
                    $this->handleExpiredPayment($result);
                    break;

                case 'payment.canceled':
                    $this->handleCanceledPayment($result);
                    break;
            }
        }

        return response()->json(['status' => 'ok']);
    }

    protected function handlePaidPayment($data)
    {
        // Update order status, send confirmation email, etc.
        $transactionId = $data['transaction_id'];
        $amount = $data['amount'];
        $currency = $data['currency'];
        $paymentMethod = $data['payment_method'];
        $refunds = $data['refunds'];
    }

    protected function handleFailedPayment($data)
    {
        // Log failed payment, notify user, etc.
    }

    protected function handleExpiredPayment($data)
    {
        // Handle expired payment
    }

    protected function handleCanceledPayment($data)
    {
        // Handle canceled payment
    }

    protected function handleAuthorizedPayment($data)
    {
        // Handle authorized payment (first charge)
    }
}
```

## Payment Methods Configuration

### Available Payment Methods

Mollie supports a wide range of payment methods:

- **creditcard** - Visa, Mastercard, American Express, Maestro
- **ideal** - iDEAL (Netherlands)
- **bancontact** - Bancontact (Belgium)
- **sofort** - SOFORT (Germany, Austria, Belgium)
- **giropay** - giropay (Germany)
- **eps** - EPS (Austria)
- **paypal** - PayPal
- **applepay** - Apple Pay
- **klarnapaylater** - Klarna Pay Later
- **klarnasliceit** - Klarna Slice It
- **przelewy24** - Przelewy24 (Poland)
- **belfius** - Belfius (Belgium)
- **kbc** - KBC/CBC (Belgium)
- **inghomepay** - ING Home'Pay (Netherlands)
- **directdebit** - SEPA Direct Debit
- **banktransfer** - Bank transfer
- **trustly** - Trustly
- **twint** - TWINT (Switzerland)
- **bacs** - BACS (UK)
- **mybank** - MyBank (Italy)

### Specifying Payment Methods

You can specify which payment methods to display:

```php
$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'EUR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'payment_options' => [
        'ideal',
        'creditcard',
        'bancontact'
    ],
    // ... other parameters
];
```

### Dynamic Payment Method Selection

```php
$country = request()->input('country', 'NL');
$amount = 100.00;

// Get available methods for specific country and amount
$methods = Payment::gateway('mollie')->getAvailablePaymentMethods([
    'amount' => $amount,
    'currency' => 'EUR',
    'billing_country' => $country,
    'locale' => 'en_US'
]);

// Use the first 3 available methods
$paymentMethods = array_slice(array_column($methods['methods'], 'id'), 0, 3);
```

## Subscription Management

### Subscription Intervals

```php
// Monthly
'interval' => '1 month'

// Every 3 months
'interval' => '3 months'

// Yearly
'interval' => '12 months'

// Weekly
'interval' => '1 week'

// Daily
'interval' => '1 day'
```

### Mandate Management

```php
// Create a mandate for SEPA Direct Debit
$mandateData = [
    'method' => 'directdebit',
    'consumer_account' => 'NL91ABNA0417164300',
    'consumer_name' => 'John Doe',
    'signatureDate' => '2024-01-15'
];

$mandate = Payment::gateway('mollie')->createMandate($customerId, $mandateData);

// Use the mandate for subscription
$subscriptionData = [
    'customer_id' => $customerId,
    'amount' => 25.00,
    'currency' => 'EUR',
    'interval' => '1 month',
    'mandate_id' => $mandate['mandate_id'],
    // ... other parameters
];
```

## Error Handling

The Mollie gateway provides detailed error messages:

```php
$payment = Payment::gateway('mollie')->pay($paymentRequest);

if (!$payment['success']) {
    $error = $payment['error'];
    $message = $error['message'];
    $code = $error['code'];

    // Handle error based on type
    if ($code === 'PAYMENT_FAILED') {
        // Payment initialization failed
    }
}
```

## Common Error Codes

- `PAYMENT_FAILED` - Payment initialization failed
- `VERIFICATION_FAILED` - Transaction verification failed
- `REFUND_FAILED` - Refund processing failed
- `SUBSCRIPTION_FAILED` - Subscription creation failed
- `CUSTOMER_FAILED` - Customer creation failed
- `MANDATE_FAILED` - Mandate creation failed
- `WEBHOOK_FAILED` - Webhook processing failed

## Testing

### Test Mode

Mollie provides a comprehensive test environment. Use test API keys:

```env
MOLLIE_KEY=test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### Test Payment Methods

Use these test payment methods in test mode:

1. **Test Cards**:
   - Visa: 5555 0000 0000 0004
   - Mastercard: 2223 0000 1000 0005
   - Amex: 3782 822463 10005

2. **Test Bank Accounts for SEPA**:
   - Netherlands: NL91ABNA0417164300
   - Germany: DE89370400440532013000
   - Belgium: BE68844010370034

3. **Test iDEAL Banks**:
   - Use any test issuer in the test environment

### Test Scenarios

- **Successful Payment**: Use valid test card details
- **Failed Payment**: Use card number 4000 3000 0000 0002
- **3D Secure**: Use card number 4000 2500 0000 3155
- **Insufficient Funds**: Use card number 4000 1111 1111 1115

## Best Practices

### Security

1. **Never expose your API keys** in frontend code
2. **Always validate webhook requests** by retrieving payment details from Mollie
3. **Use HTTPS** for all webhook endpoints
4. **Implement proper error handling** and logging

### Performance

1. **Cache payment method lists** to avoid repeated API calls
2. **Use customer objects** for repeat customers
3. **Implement retry logic** for failed API calls
4. **Monitor subscription statuses** regularly

### User Experience

1. **Show relevant payment methods** based on customer location
2. **Display proper error messages** in the user's language
3. **Implement loading states** during payment processing
4. **Send email confirmations** for successful payments

### Subscription Management

1. **Send payment failure notifications** before retrying
2. **Provide dashboard for customers** to manage subscriptions
3. **Handle dunning cycles** for failed payments
4. **Keep track of payment attempts** and success rates

## Supported Currencies

Mollie supports the following currencies:
- **EUR** (Euro) - Primary currency
- **USD** (US Dollar)
- **GBP** (British Pound)
- **CHF** (Swiss Franc)
- **SEK** (Swedish Krona)
- **NOK** (Norwegian Krone)
- **DKK** (Danish Krone)
- **PLN** (Polish Zloty)
- **HUF** (Hungarian Forint)
- **CZK** (Czech Koruna)
- **RON** (Romanian Leu)
- **BGN** (Bulgarian Lev)
- **HRK** (Croatian Kuna)
- **RUB** (Russian Ruble)
- **TRY** (Turkish Lira)
- And many more...

## Country Support

Mollie supports payments in the following countries:
- **Austria**, **Belgium**, **Bulgaria**, **Cyprus**, **Czech Republic**
- **Denmark**, **Estonia**, **Finland**, **France**, **Germany**
- **Greece**, **Hungary**, **Ireland**, **Italy**, **Latvia**
- **Lithuania**, **Luxembourg**, **Malta**, **Netherlands**, **Norway**
- **Poland**, **Portugal**, **Romania**, **Slovakia**, **Slovenia**
- **Spain**, **Sweden**, **United Kingdom**, and more...

## Rate Limits

Mollie implements rate limits:
- 1000 requests per minute per API key
- Additional rate limits for specific endpoints

Implement proper rate limiting in your application to avoid being blocked.

## SDK Methods Reference

### Payment Methods
- `pay()` - Initialize a payment
- `verify()` - Verify a transaction
- `refund()` - Process a refund
- `getTransactionStatus()` - Get transaction status

### Customer Management
- `createCustomer()` - Create a customer

### Subscriptions
- `createSubscription()` - Create a subscription
- `cancelSubscription()` - Cancel a subscription

### Mandates
- `createMandate()` - Create a mandate for recurring payments
- `getCustomerMandates()` - Get customer mandates

### Payment Links
- `createPaymentLink()` - Create a payment link

### Utilities
- `getAvailablePaymentMethods()` - Get available payment methods
- `getSupportedCurrencies()` - Get supported currencies
- `getGatewayConfig()` - Get gateway configuration

## Advanced Configuration

### Custom Profile

If you have multiple profiles in Mollie:

```php
// In config/services.php
'mollie' => [
    'key' => env('MOLLIE_KEY'),
    'profile_id' => env('MOLLIE_PROFILE_ID'), // Optional
],
```

### Custom Webhook Handler

```php
// Create custom webhook model
class MollieWebhook extends \Mollie\Laravel\Models\WebhookCall
{
    // Add custom methods or relationships
}

// In config/mollie.php
'models' => [
    'webhook_call' => \App\Models\MollieWebhook::class,
],
```

## Integration Tips

### Order Status Management

```php
// In your payment success controller
public function paymentSuccess(Request $request)
{
    $molliePaymentId = request()->get('id');

    if (!$molliePaymentId) {
        return redirect('/payment/failed');
    }

    $verification = Payment::gateway('mollie')->verify($molliePaymentId);

    if ($verification['success'] && $verification['status'] === 'paid') {
        // Update order
        $order = Order::where('transaction_id', $verification['transaction_id'])->first();
        $order->status = 'paid';
        $order->payment_method = $verification['payment_method'];
        $order->paid_at = now();
        $order->save();

        return redirect('/payment/success')->with('order', $order);
    }

    return redirect('/payment/failed');
}
```

### Multi-Language Support

```php
// Set locale based on user preference
$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'EUR',
    'email' => $customer->email,
    'locale' => app()->getLocale(), // e.g., 'nl_NL', 'de_DE', 'fr_FR'
    // ... other parameters
];
```

## Support

For Mollie-specific support:
- Email: support@mollie.com
- Documentation: https://docs.mollie.com/
- API Reference: https://docs.mollie.com/reference/v2/
- Status Page: https://status.mollie.com/

For Laravel Payments package support:
- GitHub Issues: https://github.com/your-username/laravel-payments/issues
- Email: your-email@example.com

## Changelog

### v1.0.0
- Initial Mollie integration
- Support for 20+ payment methods
- Customer and subscription management
- Mandate handling for recurring payments
- Comprehensive webhook support
- Multi-currency support
- Local payment methods for European markets