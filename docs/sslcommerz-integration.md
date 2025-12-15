# SSLCommerz Integration Guide

This guide explains how to integrate SSLCommerz payment gateway with the Laravel Payments package.

## Overview

SSLCommerz is the largest payment gateway in Bangladesh, supporting multiple payment methods including:
- Credit/Debit Cards (Visa, Mastercard, American Express)
- Mobile Banking (bKash, Rocket, Nagad, DBBL Mobile Banking)
- Internet Banking
- QR Code Payments
- Bank Transfers
- USSD Payments

## Installation

1. Install the SSLCommerz package via Composer:

```bash
composer require raziul/sslcommerz-laravel
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --tag=sslcommerz-config
```

## Configuration

Add your SSLCommerz credentials to your `.env` file:

```env
SSLCOMMERZ_STORE_ID=your_store_id_here
SSLCOMMERZ_STORE_PASSWORD=your_store_password_here
SSLCOMMERZ_TEST_MODE=true
SSLCOMMERZ_SUCCESS_URL=https://yoursite.com/payment/success
SSLCOMMERZ_FAIL_URL=https://yoursite.com/payment/fail
SSLCOMMERZ_CANCEL_URL=https://yoursite.com/payment/cancel
SSLCOMMERZ_IPN_URL=https://yoursite.com/payment/ipn
```

You can obtain these credentials from your [SSLCommerz dashboard](https://developer.sslcommerz.com/).

### Environment-Specific Settings

For development/testing:

```env
SSLCOMMERZ_TEST_MODE=true
```

For production:

```env
SSLCOMMERZ_TEST_MODE=false
```

## Basic Usage

### Initialize Payment

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'BDT',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'redirect_url' => 'https://yoursite.com/payment/callback',
    'payment_options' => 'visa,mastercard,mobilebank',
    'customer' => [
        'name' => 'John Doe',
        'phone' => '01712345678',
        'address' => '123 Main St, Dhaka, Bangladesh',
        'city' => 'Dhaka',
        'country' => 'Bangladesh',
        'postal_code' => '1000'
    ],
    'metadata' => [
        'category' => 'Electronics',
        'order_id' => 'ORD123456'
    ]
];

$payment = Payment::gateway('sslcommerz')->pay($paymentRequest);
```

### Verify Payment

```php
$verification = Payment::gateway('sslcommerz')->verify($transactionId);

if ($verification['success']) {
    $status = $verification['status'];
    $amount = $verification['amount'];

    if ($status === 'success') {
        // Payment was successful
    }
}
```

### Process Refund

```php
$refundData = [
    'bank_transaction_id' => 'BANK_TRANSACTION_ID',
    'amount' => 50.00,
    'reason' => 'Customer requested refund'
];

$refund = Payment::gateway('sslcommerz')->refund($refundData);
```

## Advanced Features

### Create Payment Link

```php
$linkData = [
    'amount' => 100.00,
    'currency' => 'BDT',
    'description' => 'Payment for services',
    'redirect_url' => 'https://yoursite.com/success',
    'customer' => [
        'name' => 'John Doe',
        'email' => 'customer@example.com',
        'phone' => '01712345678'
    ]
];

$paymentLink = Payment::gateway('sslcommerz')->createPaymentLink($linkData);
```

### Setup Subscription

```php
$subscriptionData = [
    'amount' => 500.00,
    'currency' => 'BDT',
    'interval' => 'month',
    'times' => 12,
    'description' => 'Monthly subscription',
    'customer' => [
        'name' => 'John Doe',
        'email' => 'customer@example.com',
        'phone' => '01712345678'
    ],
    'success_url' => 'https://yoursite.com/subscription/success',
    'fail_url' => 'https://yoursite.com/subscription/fail',
    'cancel_url' => 'https://yoursite.com/subscription/cancel'
];

$subscription = Payment::gateway('sslcommerz')->createSubscription($subscriptionData);
```

### Get Available Payment Methods

```php
$methods = Payment::gateway('sslcommerz')->getPaymentMethodsForCountry('BD');

// Returns:
// [
//     'visa' => 'Visa Card',
//     'mastercard' => 'Mastercard',
//     'amex' => 'American Express',
//     'mobile_banking' => 'Mobile Banking (bKash, Rocket, Nagad, etc.)',
//     'internet_banking' => 'Internet Banking',
//     'others' => 'Other Payment Methods'
// ]
```

## Webhook Setup

1. Set up your webhook endpoint in the SSLCommerz dashboard

2. Create a route to handle webhooks:

```php
// routes/web.php
Route::post('/sslcommerz/webhook', [SslcommerzWebhookController::class, 'handleWebhook']);
```

3. Create the webhook controller:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class SslcommerzWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $payload = $request->all();

        // Process the webhook
        $result = Payment::gateway('sslcommerz')->processWebhook($payload);

        if ($result['success']) {
            $eventType = $result['event_type'];
            $transactionId = $result['transaction_id'];

            switch ($eventType) {
                case 'payment.success':
                    // Handle successful payment
                    $this->handleSuccessfulPayment($result);
                    break;

                case 'payment.failed':
                    // Handle failed payment
                    $this->handleFailedPayment($result);
                    break;

                case 'payment.cancelled':
                    // Handle cancelled payment
                    $this->handleCancelledPayment($result);
                    break;
            }
        }

        return response()->json(['status' => 'received']);
    }

    protected function handleSuccessfulPayment($data)
    {
        // Update order status, send confirmation email, etc.
        // $data contains: transaction_id, amount, currency, payment_method
    }

    protected function handleFailedPayment($data)
    {
        // Log failed payment, notify user, etc.
    }

    protected function handleCancelledPayment($data)
    {
        // Handle cancelled payment
    }
}
```

### IPN (Instant Payment Notification)

SSLCommerz also supports IPN for real-time payment notifications:

```php
public function handleIpn(Request $request)
{
    $val_id = $request->input('val_id');

    if (!$val_id) {
        return response()->json(['error' => 'Missing val_id'], 400);
    }

    // Verify the IPN request
    $verifyData = [
        'val_id' => $val_id,
        'store_id' => config('services.sslcommerz.store_id'),
        'store_password' => config('services.sslcommerz.store_password'),
        'format' => 'json'
    ];

    $response = Http::asForm()->post(
        config('services.sslcommerz.test_mode')
            ? 'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php'
            : 'https://securepay.sslcommerz.com/validator/api/validationserverAPI.php',
        $verifyData
    );

    if ($response->successful() && $response['status'] === 'VALID') {
        // Payment verified successfully
        $transactionId = $response['tran_id'];
        $amount = $response['amount'];
        $currency = $response['currency'];

        // Update your database, send notifications, etc.
    }

    return response()->json(['status' => 'processed']);
}
```

## Payment Methods Configuration

### Specifying Payment Methods

You can specify which payment methods to display:

```php
$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'BDT',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'payment_options' => [
        'visa',
        'mastercard',
        'mobile_banking',
        'internet_banking'
    ],
    // ... other parameters
];
```

### Available Payment Method Codes

- `visa` - Visa Cards
- `mastercard` - Mastercard
- `amex` - American Express
- `brac_visa` - BRAC Visa
- `city_visa` - City Visa
- `dutch_bangla_visa` - Dutch Bangla Visa
- `ebl_visa` - EBL Visa
- `dbbl_master` - DBBL Mastercard
- `brac_master` - BRAC Mastercard
- `city_master` - City Mastercard
- `ebl_master` - EBL Mastercard
- `mtbl_visa` - MBL Visa
- `city_amex` - City American Express
- `brac_amex` - BRAC American Express
- `dbbl_amex` - DBBL American Express
- `mobile_banking` - Mobile Banking
- `internet_banking` - Internet Banking
- `others` - Other Payment Methods

## Error Handling

The SSLCommerz gateway provides detailed error messages:

```php
$payment = Payment::gateway('sslcommerz')->pay($paymentRequest);

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
- `WEBHOOK_FAILED` - Webhook processing failed
- `TRANSACTION_NOT_FOUND` - Transaction not found

## Testing

### Test Credentials

For testing, use these credentials:

```env
SSLCOMMERZ_STORE_ID=testbox
SSLCOMMERZ_STORE_PASSWORD=qwerty
SSLCOMMERZ_TEST_MODE=true
```

### Test Cards

Use these test cards for testing:

- **Visa**: 4111111111111111
- **Mastercard**: 5555555555554444
- **American Express**: 378282246310005

Test CVV: 123
Test Expiry: Any future date

### Test Mobile Banking

For mobile banking testing, select any mobile banking option and use test account numbers provided in the SSLCommerz test environment.

## Best Practices

### Security

1. **Never expose your store credentials** in frontend code
2. **Always validate webhook requests** using the verification endpoint
3. **Use HTTPS** for all webhook endpoints
4. **Implement proper error handling** and logging

### Performance

1. **Cache payment method lists** to avoid repeated API calls
2. **Use transaction IDs** that are unique and easy to track
3. **Implement retry logic** for failed transactions
4. **Monitor transaction statuses** regularly

### User Experience

1. **Display appropriate payment options** based on customer location
2. **Provide clear error messages** to users
3. **Implement proper loading states** during payment processing
4. **Send timely notifications** for payment confirmations

## Supported Currencies

SSLCommerz supports the following currencies:
- **BDT** (Bangladeshi Taka) - Primary currency
- **USD** (US Dollar)
- **EUR** (Euro)
- **GBP** (British Pound)
- **AUD** (Australian Dollar)
- **CAD** (Canadian Dollar)
- **SGD** (Singapore Dollar)
- **MYR** (Malaysian Ringgit)
- **THB** (Thai Baht)
- **IDR** (Indonesian Rupiah)
- **PHP** (Philippine Peso)
- **AED** (UAE Dirham)
- **SAR** (Saudi Riyal)
- **QAR** (Qatari Riyal)
- **OMR** (Omani Rial)
- **BHD** (Bahraini Dinar)
- **KWD** (Kuwaiti Dinar)
- **JOD** (Jordanian Dinar)
- **LBP** (Lebanese Pound)

## Country Support

SSLCommerz primarily serves Bangladesh but supports:
- **Bangladesh** (Full feature support)
- **International** (Card payments and selected methods)

## Rate Limits

SSLCommerz implements rate limits:
- 100 requests per minute for payment initialization
- 1000 requests per minute for transaction validation

Implement proper rate limiting in your application to avoid being blocked.

## SDK Methods Reference

### Payment Methods
- `pay()` - Initialize a payment
- `verify()` - Verify a transaction
- `refund()` - Process a refund
- `getTransactionStatus()` - Get transaction status

### Payment Links
- `createPaymentLink()` - Create a payment link

### Subscriptions
- `createSubscription()` - Create a subscription
- `cancelSubscription()` - Cancel a subscription (handled at application level)

### Customer Management
- `createCustomer()` - Create/record customer information

### Utilities
- `getPaymentMethodsForCountry()` - Get available payment methods for a country
- `getSupportedCurrencies()` - Get list of supported currencies
- `getGatewayConfig()` - Get gateway configuration

## Integration Tips

### Redirect URL Handling

```php
// In your success URL controller
public function paymentSuccess(Request $request)
{
    $transactionId = $request->input('tran_id');

    // Verify the transaction
    $verification = Payment::gateway('sslcommerz')->verify($transactionId);

    if ($verification['success'] && $verification['status'] === 'success') {
        // Update order status, show success page
        return view('payment.success', [
            'transaction_id' => $transactionId,
            'amount' => $verification['amount']
        ]);
    }

    // Handle verification failure
    return redirect('/payment/fail')->with('error', 'Payment verification failed');
}
```

### Custom Metadata

```php
$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'BDT',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'metadata' => [
        'order_id' => 'ORD123456',
        'user_id' => 789,
        'product_ids' => [1, 2, 3],
        'custom_field' => 'custom_value'
    ],
    // ... other parameters
];

// The metadata will be sent as value_a in the payment request
```

## Support

For SSLCommerz-specific support:
- Email: support@sslcommerz.com
- Documentation: https://developer.sslcommerz.com/
- API Reference: https://developer.sslcommerz.com/doc/api/v3/

For Laravel Payments package support:
- GitHub Issues: https://github.com/your-username/laravel-payments/issues
- Email: your-email@example.com

## Changelog

### v1.0.0
- Initial SSLCommerz integration
- Support for card payments, mobile banking, internet banking
- Webhook and IPN handling
- Payment link creation
- Subscription support (recurring payments)
- Refund functionality