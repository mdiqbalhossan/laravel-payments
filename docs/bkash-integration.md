# bKash Integration Guide

This guide explains how to integrate bKash payment gateway with the Laravel Payments package.

## Overview

bKash is Bangladesh's largest mobile financial service provider, offering:
- Mobile Wallet Payments
- QR Code Payments
- In-App Payments
- OTP-based Transactions
- Online Payment Gateway Integration

## Installation

1. Install the bKash package via Composer:

```bash
composer require sabitahmad/laravel-bkash
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --tag=bkash-config
```

3. Publish the migration file (optional, for storing payment data):

```bash
php artisan vendor:publish --tag=bkash-migrations
```

## Configuration

Add your bKash credentials to your `.env` file:

```env
BKASH_APP_KEY=your_app_key_here
BKASH_APP_SECRET=your_app_secret_here
BKASH_USERNAME=your_username_here
BKASH_PASSWORD=your_password_here
BKASH_TEST_MODE=true
BKASH_CALLBACK_URL=https://yoursite.com/bkash/callback
BKASH_SUCCESS_URL=https://yoursite.com/payment/success
BKASH_FAIL_URL=https://yoursite.com/payment/fail
```

You can obtain these credentials from your [bKash merchant dashboard](https://merchant.bkash.com/).

### Environment-Specific Settings

For development/testing:

```env
BKASH_TEST_MODE=true
BKASH_APP_KEY=sandbox_app_key
BKASH_APP_SECRET=sandbox_app_secret
```

For production:

```env
BKASH_TEST_MODE=false
BKASH_APP_KEY=live_app_key
BKASH_APP_SECRET=live_app_secret
```

You also need to add the configuration to your `config/services.php`:

```php
'bkash' => [
    'app_key' => env('BKASH_APP_KEY'),
    'app_secret' => env('BKASH_APP_SECRET'),
    'username' => env('BKASH_USERNAME'),
    'password' => env('BKASH_PASSWORD'),
    'test_mode' => env('BKASH_TEST_MODE', true),
    'callback_url' => env('BKASH_CALLBACK_URL'),
    'success_url' => env('BKASH_SUCCESS_URL'),
    'fail_url' => env('BKASH_FAIL_URL'),
],
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
    'customer' => [
        'name' => 'John Doe',
        'phone' => '01XXXXXXXXX'
    ],
    'metadata' => [
        'order_id' => 'ORD123456',
        'user_id' => 789
    ]
];

$payment = Payment::gateway('bkash')->pay($paymentRequest);
```

This will return a payment URL that you need to redirect the user to:

```php
if ($payment['success']) {
    // Store payment_id for later verification
    session(['bkash_payment_id' => $payment['payment_id']]);

    // Redirect to bKash payment page
    return redirect($payment['payment_url']);
}
```

### Verify Payment

```php
// First, get the payment ID (stored during initialization)
$paymentId = session('bkash_payment_id');

$verification = Payment::gateway('bkash')->verify($paymentId);

if ($verification['success']) {
    $status = $verification['status'];

    if ($status === 'completed') {
        // Payment was successful
        $trxId = $verification['transaction_id_bkash'];
        $amount = $verification['amount'];
    }
}
```

### Process Refund

```php
$refundData = [
    'payment_id' => 'PAYMENT_ID_FROM_BKASH',
    'amount' => 50.00,
    'trx_id' => 'ORIGINAL_TRANSACTION_ID',
    'reason' => 'Customer requested refund'
];

$refund = Payment::gateway('bkash')->refund($refundData);
```

## Advanced Features

### Query Transaction Status

```php
$transactionDetails = Payment::gateway('bkash')->queryTransaction($paymentId);
```

### Search Transaction by bKash Transaction ID

```php
$transaction = Payment::gateway('bkash')->searchTransaction($trxId);
```

### Create Payment Link

```php
$linkData = [
    'amount' => 100.00,
    'currency' => 'BDT',
    'description' => 'Payment for invoice #123',
    'customer' => [
        'name' => 'John Doe',
        'email' => 'customer@example.com'
    ]
];

$paymentLink = Payment::gateway('bkash')->createPaymentLink($linkData);
```

### Currency Conversion

bKash's primary currency is BDT (Bangladeshi Taka). The gateway automatically converts other currencies to BDT:

```php
// This will be converted to BDT internally
$paymentRequest = [
    'amount' => 1.00,
    'currency' => 'USD',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    // ... other parameters
];

$payment = Payment::gateway('bkash')->pay($paymentRequest);
// $payment['original_amount'] = 1.00
// $payment['original_currency'] = 'USD'
// $payment['amount'] = 109.50 (1 * 109.50)
// $payment['currency'] = 'BDT'
```

## Webhook Setup

bKash uses callbacks (webhooks) to notify your application about payment status changes.

1. Configure your callback URL in the bKash dashboard or in the payment request

2. Create a route to handle callbacks:

```php
// routes/web.php
Route::post('/bkash/callback', [BkashCallbackController::class, 'handleCallback']);
Route::get('/bkash/success', [BkashController::class, 'success']);
Route::get('/bkash/fail', [BkashController::class, 'fail']);
```

3. Create the callback controller:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class BkashCallbackController extends Controller
{
    public function handleCallback(Request $request)
    {
        $gateway = Payment::gateway('bkash');
        $callbackData = $gateway->parseCallback($request);

        // Process the webhook
        $result = $gateway->processWebhook($callbackData);

        if ($result['success']) {
            $eventType = $result['event_type'];
            $transactionId = $result['transaction_id'];
            $paymentId = $result['payment_id'];

            switch ($eventType) {
                case 'payment.completed':
                    $this->handleSuccessfulPayment($result);
                    break;

                case 'payment.failed':
                    $this->handleFailedPayment($result);
                    break;

                case 'payment.cancelled':
                    $this->handleCancelledPayment($result);
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
            $order->payment_method = 'bkash';
            $order->bkash_trx_id = $data['transaction_id_bkash'];
            $order->customer_msisdn = $data['customer_msisdn'];
            $order->save();

            // Send confirmation email
            Mail::to($order->customer_email)->send(new PaymentConfirmation($order));
        }
    }

    protected function handleFailedPayment($data)
    {
        // Log failed payment
        Log::warning('bKash payment failed', [
            'transaction_id' => $data['transaction_id'],
            'payment_id' => $data['payment_id']
        ]);

        // Update order status
        $order = Order::where('transaction_id', $data['transaction_id'])->first();
        if ($order) {
            $order->status = 'failed';
            $order->save();
        }
    }

    protected function handleCancelledPayment($data)
    {
        // Handle cancelled payment
    }

    protected function handlePendingPayment($data)
    {
        // Payment is being processed
        $order = Order::where('transaction_id', $data['transaction_id'])->first();
        if ($order) {
            $order->status = 'processing';
            $order->save();
        }
    }
}
```

4. Create success and fail handlers:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BkashController extends Controller
{
    public function success(Request $request)
    {
        // User returned from bKash after successful payment
        // Display success page
        return view('payment.bkash-success');
    }

    public function fail(Request $request)
    {
        // User returned from bKash after failed payment
        // Display failure page
        return view('payment.bkash-fail');
    }
}
```

## Payment Flow

1. **Initialize Payment**: Call `pay()` to get payment ID and bKash URL
2. **Redirect User**: Redirect customer to bKash payment page
3. **Customer Action**: Customer completes payment in bKash app
4. **Callback**: bKash sends callback to your server
5. **Verification**: Use `executePayment()` or `queryPayment()` to verify status
6. **Complete**: Update order status and notify customer

## Error Handling

The bKash gateway provides detailed error messages:

```php
$payment = Payment::gateway('bkash')->pay($paymentRequest);

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
- `QUERY_FAILED` - Transaction query failed
- `SEARCH_FAILED` - Transaction search failed

## Testing

### Test Mode

Use test credentials for development:

```env
BKASH_TEST_MODE=true
```

### Test Credentials

For testing in sandbox mode, bKash provides test credentials that will be provided in their developer documentation.

### Test Scenarios

1. **Successful Payment**: Complete the payment flow in test environment
2. **Failed Payment**: Cancel the payment or simulate failure
3. **Refund**: Process refunds for test transactions

## Payment Methods

### Available Methods in Bangladesh

- **bKash Mobile Wallet**: Direct payment through bKash mobile wallet
- **bKash App Payment**: Payment initiated through bKash app
- **bKash OTP Verification**: OTP-based payment confirmation
- **bKash QR Code**: QR code scanning for payments

### Method Selection

bKash automatically determines the appropriate payment method based on:
- Customer's bKash account type
- Transaction amount
- Merchant configuration

## Best Practices

### Security

1. **Never expose your credentials** in frontend code
2. **Always validate webhook callbacks** to ensure they're from bKash
3. **Use HTTPS** for all webhook endpoints
4. **Implement proper error handling** and logging

### Transaction Management

1. **Store payment IDs** during initialization for later verification
2. **Always verify payments** through executePayment or queryPayment
3. **Implement retry logic** for failed API calls
4. **Log all transaction attempts** for auditing

### User Experience

1. **Store session data** during payment initialization
2. **Handle all return URLs** appropriately (success, fail)
3. **Display clear payment status** to users
4. **Send email confirmations** for successful payments
5. **Provide support contact** for payment issues

## Supported Currencies

bKash primarily processes payments in BDT (Bangladeshi Taka). However, it can accept multiple currencies with automatic conversion:

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

bKash primarily serves Bangladesh but can accept:
- **Bangladesh** (Full feature support)
- **International** (Limited support through bKash's international payment gateway)

## Rate Limits

bKash implements reasonable rate limits. Implement proper rate limiting in your application to avoid being blocked.

## SDK Methods Reference

### Payment Methods
- `pay()` - Initialize a payment
- `verify()` - Verify a transaction status
- `refund()` - Process a refund
- `getTransactionStatus()` - Get transaction status
- `queryTransaction()` - Query transaction details
- `searchTransaction()` - Search by bKash transaction ID

### Payment Links
- `createPaymentLink()` - Create a payment link

### Customer Management
- `createCustomer()` - Note customer information (sent with each payment)

### Utilities
- `parseCallback()` - Parse callback parameters from request
- `getSupportedCurrencies()` - Get supported currencies
- `getGatewayConfig()` - Get gateway configuration
- `getPaymentMethodsForCountry()` - Get payment methods for a country

## Advanced Integration Tips

### Multiple bKash Accounts

```php
// If you have multiple bKash accounts
$gateway = Payment::gateway('bkash', [
    'app_key' => 'different_app_key',
    'app_secret' => 'different_app_secret',
    'username' => 'different_username',
    'password' => 'different_password'
]);
```

### Custom Exchange Rates

Override the default exchange rates for currency conversion:

```php
// In a service provider or middleware
$gateway = Payment::gateway('bkash');
// Note: The gateway uses predefined rates, implement your own exchange rate service for production
```

### Recurring Payments

Since bKash doesn't natively support recurring payments:

```php
// Implement your own recurring payment logic
public function processRecurringPayment($subscription)
{
    foreach ($subscription->charges as $charge) {
        $paymentRequest = [
            'amount' => $charge->amount,
            'currency' => 'BDT',
            'email' => $subscription->customer_email,
            'transaction_id' => 'SUB_' . $subscription->id . '_' . $charge->id,
            'description' => $subscription->description,
            // ... other parameters
        ];

        $payment = Payment::gateway('bkash')->pay($paymentRequest);

        if ($payment['success']) {
            // Store payment_id for this charge
            $charge->bkash_payment_id = $payment['payment_id'];
            $charge->save();
        }

        // Handle payment and update subscription status
    }
}
```

### Session Management

```php
class PaymentController extends Controller
{
    public function initiate(Request $request)
    {
        $payment = Payment::gateway('bkash')->pay($request->all());

        if ($payment['success']) {
            // Store payment information in session
            session([
                'bkash_payment_id' => $payment['payment_id'],
                'transaction_id' => $payment['transaction_id'],
                'amount' => $payment['amount']
            ]);

            return redirect($payment['payment_url']);
        }

        return back()->with('error', 'Failed to initialize payment');
    }

    public function verify(Request $request)
    {
        $paymentId = session('bkash_payment_id');

        if (!$paymentId) {
            return back()->with('error', 'Payment information not found');
        }

        $verification = Payment::gateway('bkash')->verify($paymentId);

        if ($verification['success'] && $verification['status'] === 'completed') {
            // Clear session
            session()->forget(['bkash_payment_id', 'transaction_id', 'amount']);

            // Update order status
            // Send confirmation
            // Redirect to success page
        }

        return back()->with('error', 'Payment verification failed');
    }
}
```

## Support

For bKash-specific support:
- Email: merchant@bkash.com
- Documentation: https://developer.bkash.com/
- Merchant Portal: https://merchant.bkash.com/
- API Reference: Available in developer portal

For Laravel Payments package support:
- GitHub Issues: https://github.com/your-username/laravel-payments/issues
- Email: your-email@example.com

## Changelog

### v1.0.0
- Initial bKash integration
- Mobile wallet payment support
- Callback/webhook handling
- Refund processing
- Payment link creation
- Multi-currency support with BDT conversion
- Transaction query and search functionality