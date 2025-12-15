# Flutterwave Integration Guide

This guide explains how to integrate Flutterwave payment gateway with the Laravel Payments package.

## Overview

Flutterwave is a leading African payment gateway that supports multiple payment methods including:
- Card payments (Visa, Mastercard, Verve)
- Bank transfers
- Mobile money (M-Pesa, MTN Mobile Money, etc.)
- USSD payments
- Barter by Flutterwave
- Buy Now, Pay Later options
- Cryptocurrency payments

## Installation

1. Install the Flutterwave package via Composer:

```bash
composer require abraham-flutterwave/laravel-payment
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --tag=flutterwave-config
```

## Configuration

Add your Flutterwave credentials to your `.env` file:

```env
FLUTTERWAVE_PUBLIC_KEY=your_public_key_here
FLUTTERWAVE_SECRET_KEY=your_secret_key_here
FLUTTERWAVE_SECRET_HASH=your_webhook_secret_hash
FLUTTERWAVE_ENCRYPTION_KEY=your_encryption_key_here
FLUTTERWAVE_LOG_CHANNEL=stack
```

You can obtain these keys from your [Flutterwave dashboard](https://dashboard.flutterwave.com/).

### Environment-Specific Settings

For development/testing:

```env
FLUTTERWAVE_ENV=staging
```

For production:

```env
FLUTTERWAVE_ENV=production
```

## Basic Usage

### Initialize Payment

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'NGN',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'redirect_url' => 'https://yoursite.com/payment/callback',
    'payment_options' => 'card,banktransfer,ussd',
    'customer' => [
        'name' => 'John Doe',
        'phone' => '+2341234567890',
        'address' => '123 Main St, Lagos, Nigeria'
    ],
    'customizations' => [
        'title' => 'My Company',
        'description' => 'Payment for products',
        'logo' => 'https://yoursite.com/logo.png'
    ]
];

$payment = Payment::gateway('flutterwave')->pay($paymentRequest);
```

### Verify Payment

```php
$verification = Payment::gateway('flutterwave')->verify($transactionId);

if ($verification['success']) {
    $status = $verification['data']['status'];
    $amount = $verification['data']['amount'];

    if ($status === 'successful') {
        // Payment was successful
    }
}
```

### Process Refund

```php
$refundData = [
    'transaction_id' => 'FLW_TRANSACTION_ID',
    'amount' => 50.00
];

$refund = Payment::gateway('flutterwave')->refund($refundData);
```

## Advanced Features

### Create Customer

```php
$customerData = [
    'email' => 'customer@example.com',
    'name' => 'John Doe',
    'phone' => '+2341234567890',
    'address' => '123 Main St, Lagos, Nigeria'
];

$customer = Payment::gateway('flutterwave')->createCustomer($customerData);
```

### Create Payment Link

```php
$linkData = [
    'amount' => 100.00,
    'currency' => 'NGN',
    'description' => 'Payment for services',
    'redirect_url' => 'https://yoursite.com/success',
    'duration' => 24, // Duration in hours
    'is_fixed_amount' => true
];

$paymentLink = Payment::gateway('flutterwave')->createPaymentLink($linkData);
```

### Setup Subscription

```php
$subscriptionData = [
    'customer' => [
        'email' => 'customer@example.com',
        'name' => 'John Doe'
    ],
    'plan' => 'RQP_PLAN_ID',
    'amount' => 5000,
    'currency' => 'NGN'
];

$subscription = Payment::gateway('flutterwave')->createSubscription($subscriptionData);
```

### Create Virtual Account

```php
$virtualAccountData = [
    'email' => 'customer@example.com',
    'bvn' => '12345678901',
    'is_permanent' => true,
    'customer_name' => 'John Doe'
];

$virtualAccount = Payment::gateway('flutterwave')->createVirtualAccount($virtualAccountData);
```

### Get Supported Banks

```php
$banks = Payment::gateway('flutterwave')->getSupportedBanks('NGN'); // NGN, GHS, KES, TZS, UGX, ZAR
```

### Validate Card BIN

```php
$binValidation = Payment::gateway('flutterwave')->validateCardBin('539983');
```

### Send Money (Transfer)

```php
$transferData = [
    'account_bank' => '044', // Sort code
    'account_number' => '0690000032',
    'amount' => 5000,
    'narration' => 'Payment for services',
    'currency' => 'NGN',
    'reference' => 'TRF_' . time()
];

$transfer = Payment::gateway('flutterwave')->sendMoney($transferData);
```

## Webhook Setup

1. Set up your webhook endpoint in the Flutterwave dashboard

2. Create a route to handle webhooks:

```php
// routes/web.php
Route::post('/flutterwave/webhook', [FlutterwaveWebhookController::class, 'handleWebhook']);
```

3. Create the webhook controller:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class FlutterwaveWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $webhookSecret = config('services.flutterwave.secret_hash');
        $signature = $request->header('verif-hash');

        if ($signature !== $webhookSecret) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->all();

        // Process the webhook
        $result = Payment::gateway('flutterwave')->processWebhook($payload);

        if ($result['success']) {
            $eventType = $payload['event'];

            switch ($eventType) {
                case 'charge.completed':
                    // Handle successful payment
                    $this->handleSuccessfulPayment($payload['data']);
                    break;

                case 'charge.failed':
                    // Handle failed payment
                    $this->handleFailedPayment($payload['data']);
                    break;

                case 'refund.completed':
                    // Handle refund
                    $this->handleRefund($payload['data']);
                    break;

                case 'transfer.completed':
                    // Handle transfer
                    $this->handleTransfer($payload['data']);
                    break;

                case 'subscription.create':
                    // Handle subscription creation
                    $this->handleSubscriptionCreated($payload['data']);
                    break;
            }
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleSuccessfulPayment($data)
    {
        // Update order status, send confirmation email, etc.
    }

    protected function handleFailedPayment($data)
    {
        // Log failed payment, notify user, etc.
    }

    protected function handleRefund($data)
    {
        // Update refund status, etc.
    }

    protected function handleTransfer($data)
    {
        // Update transfer status, etc.
    }

    protected function handleSubscriptionCreated($data)
    {
        // Handle subscription creation
    }
}
```

## Error Handling

The Flutterwave gateway provides detailed error messages:

```php
$payment = Payment::gateway('flutterwave')->pay($paymentRequest);

if (!$payment['success']) {
    $error = $payment['error'];
    $message = $error['message'];
    $code = $error['code'];

    // Handle error based on type
    if ($code === 'INVALID_REQUEST') {
        // Invalid request parameters
    } elseif ($code === 'INSUFFICIENT_FUNDS') {
        // Insufficient funds
    } elseif ($code === 'CARD_DECLINED') {
        // Card declined
    }
}
```

## Common Error Codes

- `INVALID_REQUEST`: Request parameters are invalid
- `P004`: Insufficient funds
- `CARD_DECLINED`: Card was declined
- `INVALID_OTP`: Invalid OTP provided
- `TRANSACTION_NOT_FOUND`: Transaction not found
- `DUPLICATE_TRANSACTION`: Duplicate transaction attempt

## Testing

### Test Cards

Use these test cards for testing:

- **Visa**: 4187427415564246
- **Mastercard**: 5531886552162754
- **Verve**: 5060990580000217474

Test CVV: 123
Test Expiry: Any future date
Test PIN: 3310

### Test OTP

Use 123456 as the OTP for test transactions.

### Test USSD

Use these test USSD codes:
- `*123*456#` - MTN
- `*555*678#` - Airtel
- `*888*901#` - 9mobile
- `*666*234#` - Glo

## Best Practices

### Security

1. **Never expose your secret keys** in frontend code
2. **Always validate webhook signatures** using the secret hash
3. **Use HTTPS** for all webhook endpoints
4. **Implement proper error handling** and logging

### Performance

1. **Cache customer information** to avoid repeated API calls
2. **Use payment links** for recurring payments
3. **Implement retry logic** for failed transactions
4. **Monitor transaction statuses** regularly

### User Experience

1. **Display appropriate payment options** based on customer location
2. **Provide clear error messages** to users
3. **Implement proper loading states** during payment processing
4. **Send timely notifications** for payment confirmations

## Supported Countries

Flutterwave supports payments in the following countries:
- Nigeria (NGN)
- Ghana (GHS)
- Kenya (KES)
- Tanzania (TZS)
- Uganda (UGX)
- South Africa (ZAR)
- Rwanda (RWF)
- Senegal (XOF)
- Sierra Leone (SLL)
- Gambia (GMD)
- Liberia (LRD)
- Zambia (ZMW)
- Malawi (MWK)
- Mozambique (MZN)

## Currency Support

Flutterwave supports multiple currencies including:
- NGN (Nigerian Naira)
- USD (US Dollar)
- EUR (Euro)
- GBP (British Pound)
- GHS (Ghanaian Cedi)
- KES (Kenyan Shilling)
- ZAR (South African Rand)
- And many more...

## Rate Limits

Flutterwave implements rate limits:
- 300 requests per minute for most endpoints
- 1000 requests per minute for tokenized payments

Implement proper rate limiting in your application to avoid being blocked.

## SDK Methods Reference

### Payment Methods
- `pay()` - Initialize a payment
- `verify()` - Verify a transaction
- `refund()` - Process a refund
- `capturePayment()` - Capture an authorized payment
- `voidPayment()` - Void an authorized payment

### Customer Management
- `createCustomer()` - Create a new customer
- `updateCustomer()` - Update customer details
- `getCustomer()` - Retrieve customer information
- `getCustomerTransactions()` - Get customer's transaction history

### Payment Links
- `createPaymentLink()` - Create a payment link
- `updatePaymentLink()` - Update a payment link
- `getPaymentLink()` - Retrieve payment link details

### Subscriptions
- `createSubscription()` - Create a subscription
- `updateSubscription()` - Update subscription
- `cancelSubscription()` - Cancel a subscription
- `getSubscriptions()` - List all subscriptions

### Transfers
- `sendMoney()` - Send money to bank account
- `getTransfer()` - Get transfer details
- `verifyAccount()` - Verify bank account number

### Utilities
- `getSupportedBanks()` - Get supported banks for a country
- `validateCardBin()` - Validate card BIN information
- `getExchangeRate()` - Get currency exchange rates
- `getPaymentMethods()` - Get available payment methods

## Support

For Flutterwave-specific support:
- Email: developers@flutterwavego.com
- Documentation: https://developer.flutterwave.com/docs
- API Reference: https://developer.flutterwave.com/reference

For Laravel Payments package support:
- GitHub Issues: https://github.com/your-username/laravel-payments/issues
- Email: your-email@example.com

## Changelog

### v1.0.0
- Initial Flutterwave integration
- Support for card payments, bank transfers, mobile money
- Webhook handling and verification
- Customer and subscription management
- Virtual account creation
- Transfer functionality