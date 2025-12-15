# SenangPay Integration Guide

This guide explains how to integrate SenangPay payment gateway with the Laravel Payments package.

## Overview

SenangPay is a Malaysian payment gateway that supports multiple payment methods including:
- Credit/Debit Cards (Visa, Mastercard)
- Online Banking (FPX)
- E-Wallets (Touch 'n Go, Boost, GrabPay)
- PayPal
- Direct bank transfers

## Installation

Since SenangPay doesn't require any specific package installation (we're using direct API integration), you just need to ensure you have the Laravel Payments package installed and configured.

## Configuration

Add your SenangPay credentials to your `.env` file:

```env
SENANGPAY_MERCHANT_ID=your_merchant_id_here
SENANGPAY_SECRET_KEY=your_secret_key_here
SENANGPAY_TEST_MODE=true
SENANGPAY_RETURN_URL=https://yoursite.com/payment/success
SENANGPAY_CALLBACK_URL=https://yoursite.com/senangpay/callback
```

You can obtain these credentials from your [SenangPay dashboard](https://app.senangpay.my/).

### Environment-Specific Settings

For development/testing:

```env
SENANGPAY_TEST_MODE=true
```

For production:

```env
SENANGPAY_TEST_MODE=false
```

You also need to add the configuration to your `config/services.php`:

```php
'senangpay' => [
    'merchant_id' => env('SENANGPAY_MERCHANT_ID'),
    'secret_key' => env('SENANGPAY_SECRET_KEY'),
    'test_mode' => env('SENANGPAY_TEST_MODE', true),
    'return_url' => env('SENANGPAY_RETURN_URL'),
    'callback_url' => env('SENANGPAY_CALLBACK_URL'),
],
```

## Basic Usage

### Initialize Payment

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'MYR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'redirect_url' => 'https://yoursite.com/payment/callback',
    'customer' => [
        'name' => 'John Doe',
        'phone' => '01234567890',
        'address' => '123 Main St',
        'city' => 'Kuala Lumpur',
        'country' => 'MY',
        'postal_code' => '50000'
    ],
    'metadata' => [
        'order_id' => 'ORD123456',
        'user_id' => 789
    ]
];

$payment = Payment::gateway('senangpay')->pay($paymentRequest);
```

This will return a payment URL that you need to redirect the user to, along with form data for posting:

```php
if ($payment['success']) {
    // Option 1: Redirect directly to payment URL
    return redirect($payment['payment_url']);

    // Option 2: Show a form with auto-submit
    return view('payment.senangpay', [
        'payment_url' => $payment['payment_url'],
        'form_data' => $payment['form_data']
    ]);
}
```

### Payment Form View Example

Create a view at `resources/views/payment/senangpay.blade.php`:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Redirecting to Payment...</title>
</head>
<body onload="document.forms['payment_form'].submit()">
    <form id="payment_form" method="POST" action="{{ $payment_url }}">
        @csrf
        @foreach($form_data as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <p>Redirecting to secure payment page...</p>
        <noscript>
            <input type="submit" value="Continue to Payment">
        </noscript>
    </form>
</body>
</html>
```

### Process Payment Return

```php
// routes/web.php
Route::get('/payment/success', [PaymentController::class, 'success']);
Route::post('/senangpay/callback', [SenangPayController::class, 'callback']);
```

```php
// app/Http/Controllers/SenangPayController.php

use Mdiqbal\LaravelPayments\Facades\Payment;

class SenangPayController extends Controller
{
    public function callback(Request $request)
    {
        // Parse callback data
        $gateway = Payment::gateway('senangpay');
        $callbackData = $gateway->parseCallback($request);

        // Process the webhook
        $result = $gateway->processWebhook($callbackData);

        if ($result['success']) {
            $transactionId = $result['transaction_id'];
            $status = $result['status'];

            if ($status === 'successful') {
                // Update order status
                $order = Order::where('transaction_id', $transactionId)->first();
                if ($order) {
                    $order->status = 'paid';
                    $order->paid_at = now();
                    $order->save();
                }
            }
        }

        return response()->json(['status' => 'received']);
    }

    public function success(Request $request)
    {
        // Handle successful return from payment page
        // Note: Always rely on callback for final status confirmation
        return view('payment.success');
    }
}
```

### Process Refund

```php
$refundData = [
    'transaction_id' => 'SENANGPAY_TRANSACTION_ID',
    'amount' => 50.00,
    'reason' => 'Customer requested refund'
];

$refund = Payment::gateway('senangpay')->refund($refundData);
```

## Advanced Features

### Create Payment Link

```php
$linkData = [
    'amount' => 100.00,
    'currency' => 'MYR',
    'description' => 'Payment for invoice #123',
    'customer' => [
        'name' => 'John Doe',
        'email' => 'customer@example.com'
    ],
    'redirect_url' => 'https://yoursite.com/success'
];

$paymentLink = Payment::gateway('senangpay')->createPaymentLink($linkData);
```

### Currency Conversion

SenangPay's primary currency is MYR (Malaysian Ringgit). The gateway automatically converts other currencies to MYR using predefined exchange rates:

```php
// This will be converted to MYR internally
$paymentRequest = [
    'amount' => 25.00,
    'currency' => 'USD',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    // ... other parameters
];

$payment = Payment::gateway('senangpay')->pay($paymentRequest);
// $payment['original_amount'] = 25.00
// $payment['original_currency'] = 'USD'
// $payment['amount'] = 117.50 (25 * 4.70)
// $payment['currency'] = 'MYR'
```

### Custom Parameters

You can pass custom parameters using metadata:

```php
$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'MYR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'metadata' => [
        'user_id' => 123,
        'product_ids' => [1, 2, 3],
        'voucher_code' => 'SAVE10'
    ]
];
```

## Webhook Setup

SenangPay uses callbacks (webhooks) to notify your application about payment status changes.

1. Configure your callback URL in the SenangPay dashboard or in the payment request

2. Create a route to handle callbacks:

```php
// routes/web.php
Route::post('/senangpay/callback', [SenangPayWebhookController::class, 'handleCallback']);
```

3. Create the webhook controller:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class SenangPayWebhookController extends Controller
{
    public function handleCallback(Request $request)
    {
        $gateway = Payment::gateway('senangpay');
        $callbackData = $gateway->parseCallback($request);

        // Process the webhook
        $result = $gateway->processWebhook($callbackData);

        if ($result['success']) {
            $eventType = $result['event_type'];
            $transactionId = $result['transaction_id'];

            switch ($eventType) {
                case 'payment.successful':
                    $this->handleSuccessfulPayment($result);
                    break;

                case 'payment.failed':
                    $this->handleFailedPayment($result);
                    break;

                case 'payment.pending':
                    $this->handlePendingPayment($result);
                    break;
            }
        }

        // Always return 200 OK to acknowledge receipt
        return response()->json(['status' => 'received']);
    }

    protected function handleSuccessfulPayment($data)
    {
        // Update order status
        $order = Order::where('transaction_id', $data['transaction_id'])->first();
        if ($order) {
            $order->status = 'paid';
            $order->paid_at = now();
            $order->payment_method = $data['payment_method'];
            $order->save();

            // Send confirmation email
            Mail::to($data['customer_email'])->send(new PaymentConfirmation($order));
        }
    }

    protected function handleFailedPayment($data)
    {
        // Log failed payment
        Log::warning('Payment failed', [
            'transaction_id' => $data['transaction_id'],
            'message' => $data['message']
        ]);

        // Notify customer
        // Update order status
    }

    protected function handlePendingPayment($data)
    {
        // Payment is being processed
        // Update order status to processing
    }
}
```

## Hash Verification

SenangPay uses HMAC SHA256 for security verification. The gateway automatically generates and verifies hashes for:

1. **Payment Request Hash**:
   ```
   hash = hmac_sha256(secret_key + detail + amount + order_id)
   ```

2. **Callback/Return Hash**:
   ```
   hash = hmac_sha256(secret_key + status + merchant_id + order_id + amount + currency + message)
   ```

3. **Refund Hash**:
   ```
   hash = hmac_sha256(secret_key + transaction_id + refund_amount)
   ```

## Error Handling

The SenangPay gateway provides detailed error messages:

```php
$payment = Payment::gateway('senangpay')->pay($paymentRequest);

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
- `WEBHOOK_FAILED` - Webhook processing failed

## Testing

### Test Mode

Use test credentials for development:

```env
SENANGPAY_TEST_MODE=true
```

### Test Cards

Use these test card details for testing:

- **Visa**: 4444 1111 1111 1115
- **Mastercard**: 5555 1111 1111 1116
- **Any Expiry**: Future date
- **CVV**: Any 3 digits

### Test Scenarios

1. **Successful Payment**: Use valid test card details
2. **Failed Payment**: Use card number 4000 0000 0000 0002
3. **Pending Payment**: Payment processing in progress

### FPX Testing

For FPX (online banking) testing in sandbox:
- Select any bank from the list
- Use test credentials provided by SenangPay

## Payment Methods

### Available Methods in Malaysia

- **Credit/Debit Cards**: Visa, Mastercard
- **Online Banking**: FPX (supports all major Malaysian banks)
- **E-Wallets**:
  - Touch 'n Go
  - Boost
  - GrabPay
  - MAE (Maybank)
  - ShopeePay
- **PayPal**: For international payments

### Method Selection

SenangPay automatically displays appropriate payment methods based on:
- Customer location
- Transaction amount
- Merchant configuration

## Best Practices

### Security

1. **Never expose your secret key** in frontend code
2. **Always verify webhook hashes** using the callback verification
3. **Use HTTPS** for all webhook endpoints
4. **Implement proper error handling** and logging

### Transaction Management

1. **Always rely on callbacks** for final payment confirmation
2. **Store transaction IDs** for verification
3. **Implement retry logic** for failed payments
4. **Log all payment attempts** for auditing

### User Experience

1. **Show payment processing status** to users
2. **Redirect users appropriately** after payment
3. **Display proper error messages** in case of failures
4. **Send email confirmations** for successful payments

## Supported Currencies

SenangPay primarily processes payments in MYR (Malaysian Ringgit). However, it can accept multiple currencies with automatic conversion:

- **MYR** (Malaysian Ringgit) - Primary currency
- **USD** (US Dollar)
- **EUR** (Euro)
- **GBP** (British Pound)
- **AUD** (Australian Dollar)
- **SGD** (Singapore Dollar)
- **HKD** (Hong Kong Dollar)
- **CAD** (Canadian Dollar)
- **JPY** (Japanese Yen)
- **CNY** (Chinese Yuan)
- **INR** (Indian Rupee)
- **THB** (Thai Baht)
- **IDR** (Indonesian Rupiah)
- **PHP** (Philippine Peso)
- **VND** (Vietnamese Dong)
- **BDT** (Bangladeshi Taka)
- **PKR** (Pakistani Rupee)
- **LKR** (Sri Lankan Rupee)
- **NPR** (Nepalese Rupee)
- **MVR** (Maldivian Rufiyaa)

## Country Support

SenangPay primarily serves Malaysia but accepts international payments through:
- Credit/Debit cards
- PayPal

## Rate Limits

SenangPay implements reasonable rate limits. Implement proper rate limiting in your application to avoid being blocked.

## SDK Methods Reference

### Payment Methods
- `pay()` - Initialize a payment
- `verify()` - Verify a transaction status
- `refund()` - Process a refund
- `getTransactionStatus()` - Get transaction status

### Payment Links
- `createPaymentLink()` - Create a payment link

### Customer Management
- `createCustomer()` - Note customer information (sent with each payment)

### Utilities
- `parseCallback()` - Parse callback parameters from request
- `getSupportedCurrencies()` - Get supported currencies
- `getGatewayConfig()` - Get gateway configuration

## Advanced Integration Tips

### Multi-Vendor Support

```php
// If you have multiple SenangPay accounts
$gateway = Payment::gateway('senangpay', [
    'merchant_id' => 'different_merchant_id',
    'secret_key' => 'different_secret_key'
]);
```

### Custom Exchange Rates

Override the default exchange rates for currency conversion:

```php
// In a service provider or middleware
Payment::gateway('senangpay')->setExchangeRates([
    'USD' => 4.50,
    'EUR' => 4.80
]);
```

### Recurring Payments

Since SenangPay doesn't natively support recurring payments:

```php
// Implement your own recurring payment logic
public function processRecurringPayment($subscription)
{
    foreach ($subscription->charges as $charge) {
        $paymentRequest = [
            'amount' => $charge->amount,
            'currency' => 'MYR',
            'email' => $subscription->customer_email,
            'transaction_id' => 'SUB_' . $subscription->id . '_' . $charge->id,
            'description' => $subscription->description,
            // ... other parameters
        ];

        $payment = Payment::gateway('senangpay')->pay($paymentRequest);
        // Handle payment and update subscription status
    }
}
```

## Support

For SenangPay-specific support:
- Email: support@senangpay.my
- Documentation: https://guide.senangpay.com/
- API Reference: https://guide.senangpay.com/developer-tools#api-integration

For Laravel Payments package support:
- GitHub Issues: https://github.com/your-username/laravel-payments/issues
- Email: your-email@example.com

## Changelog

### v1.0.0
- Initial SenangPay integration
- Direct API integration (no package dependency)
- Support for Malaysian payment methods
- Hash verification for security
- Callback/webhook handling
- Multi-currency support with MYR conversion
- Refund processing
- Payment link creation