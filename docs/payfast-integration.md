# PayFast Integration Guide

This guide explains how to integrate PayFast payment gateway with the Laravel Payments package.

## Overview

PayFast is a South African payment gateway that supports multiple payment methods including:
- Credit/Debit Cards
- Instant EFT (Electronic Funds Transfer)
- Masterpass
- PayPal
- Mobicred (Buy Now Pay Later)
- Scan to Pay (QR codes)
- SCode (USSD payments)
- Zapper QR payments
- Blu Voucher

## Installation

PayFast integration uses direct API calls, so no additional package is required. Just ensure you have the Laravel Payments package installed and configured.

## Configuration

Add your PayFast credentials to your `.env` file:

```env
PAYFAST_MERCHANT_ID=your_merchant_id_here
PAYFAST_MERCHANT_KEY=your_merchant_key_here
PAYFAST_PASSPHRASE=your_passphrase_here
PAYFAST_TEST_MODE=true
PAYFAST_WEBHOOK_URL=https://yoursite.com/payfast/webhook
PAYFAST_RETURN_URL=https://yoursite.com/payment/success
PAYFAST_CANCEL_URL=https://yoursite.com/payment/cancel
```

You can obtain these credentials from your [PayFast merchant dashboard](https://www.payfast.co.za/merchant/).

### Environment-Specific Settings

For development/testing:

```env
PAYFAST_TEST_MODE=true
PAYFAST_MERCHANT_ID=10000100
PAYFAST_MERCHANT_KEY=testmerchantkey
```

For production:

```env
PAYFAST_TEST_MODE=false
PAYFAST_MERCHANT_ID=your_production_merchant_id
PAYFAST_MERCHANT_KEY=your_production_merchant_key
```

You also need to add the configuration to your `config/services.php`:

```php
'payfast' => [
    'merchant_id' => env('PAYFAST_MERCHANT_ID'),
    'merchant_key' => env('PAYFAST_MERCHANT_KEY'),
    'passphrase' => env('PAYFAST_PASSPHRASE'),
    'test_mode' => env('PAYFAST_TEST_MODE', true),
    'webhook_url' => env('PAYFAST_WEBHOOK_URL'),
    'return_url' => env('PAYFAST_RETURN_URL'),
    'cancel_url' => env('PAYFAST_CANCEL_URL'),
],
```

## Basic Usage

### Initialize Payment

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

$paymentRequest = [
    'amount' => 299.99,
    'currency' => 'ZAR', // PayFast only supports ZAR
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'redirect_url' => 'https://yoursite.com/payment/callback',
    'customer' => [
        'name' => 'John Smith',
        'phone' => '27123456789'
    ],
    'metadata' => [
        'order_id' => 'ORD123456',
        'user_id' => 789,
        'custom_str1' => 'additional data',
        'custom_int1' => 12345
    ]
];

$payment = Payment::gateway('payfast')->pay($paymentRequest);
```

This will return a payment URL that you need to redirect the user to, along with form data for posting:

```php
if ($payment['success']) {
    // Option 1: Create an auto-submitting form
    return view('payment.payfast', [
        'payment_url' => $payment['payment_url'],
        'payment_data' => $payment['payment_data']
    ]);

    // Option 2: Store payment ID and handle differently
    session(['payfast_payment_id' => $payment['m_payment_id']]);

    // Handle payment data as needed
}
```

### Payment Form View Example

Create a view at `resources/views/payment/payfast.blade.php`:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Redirecting to Payment...</title>
</head>
<body onload="document.forms['payment_form'].submit()">
    <form id="payment_form" method="POST" action="{{ $payment_url }}">
        @csrf
        @foreach($payment_data as $key => $value)
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
Route::get('/payment/cancel', [PaymentController::class, 'cancel']);
Route::post('/payfast/webhook', [PayfastController::class, 'webhook']);
```

```php
// app/Http/Controllers/PayfastController.php

use Mdiqbal\LaravelPayments\Facades\Payment;

class PayfastController extends Controller
{
    public function webhook(Request $request)
    {
        // Parse webhook data
        $gateway = Payment::gateway('payfast');
        $webhookData = $gateway->parseCallback($request);

        // Process the webhook
        $result = $gateway->verify($webhookData);

        if ($result['success']) {
            $transactionId = $result['transaction_id'];
            $status = $result['status'];

            if ($status === 'completed') {
                // Update order status
                $order = Order::where('transaction_id', $transactionId)->first();
                if ($order) {
                    $order->status = 'paid';
                    $order->paid_at = now();
                    $order->payment_method = $result['payment_method'];
                    $order->payfast_payment_id = $result['m_payment_id'];
                    $order->save();
                }
            }
        }

        // Always return a 200 OK response to acknowledge receipt
        return response('OK', 200);
    }

    public function success(Request $request)
    {
        // User returned from PayFast after successful payment
        // Note: Always rely on webhook for final status confirmation
        return view('payment.success');
    }

    public function cancel(Request $request)
    {
        // User cancelled the payment
        return view('payment.cancelled');
    }
}
```

### Verify Payment

```php
// First, get the payment ID (stored during initialization)
$mPaymentId = session('payfast_payment_id');

$verification = Payment::gateway('payfast')->verify($mPaymentId);

if ($verification['success']) {
    $status = $verification['status'];

    if ($status === 'completed') {
        // Payment was successful
        $mPaymentId = $verification['m_payment_id'];
        $amount = $verification['amount'];
        $currency = $verification['currency']; // Will always be ZAR
        $paymentMethod = $verification['payment_method'];
    }
}
```

### Process Refund

```php
$refundData = [
    'm_payment_id' => 'PAYFAST_PAYMENT_ID',
    'amount' => 150.00, // Optional - omit for full refund
    'reason' => 'Customer requested refund',
    'token' => 'PAYFAST_TOKEN' // Required for refunds
];

$refund = Payment::gateway('payfast')->refund($refundData);
```

## Advanced Features

### Get Transaction Status

```php
$mPaymentId = 'CF_PAYMENT_ID';
$status = Payment::gateway('payfast')->getTransactionStatus($mPaymentId);
```

### Custom Fields

PayFast supports custom fields for additional data:

```php
$paymentRequest = [
    'amount' => 299.99,
    'currency' => 'ZAR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'metadata' => [
        'custom_str1' => 'User level',        // Custom string field 1
        'custom_str2' => 'Premium',          // Custom string field 2
        'custom_str3' => 'Monthly plan',     // Custom string field 3
        'custom_str4' => 'Promo code',       // Custom string field 4
        'custom_str5' => 'Reference',        // Custom string field 5
        'custom_int1' => 12345,              // Custom integer field 1
        'custom_int2' => 67890,              // Custom integer field 2
        'custom_int3' => 54321,              // Custom integer field 3
        'custom_int4' => 98765,              // Custom integer field 4
        'custom_int5' => 13579,              // Custom integer field 5
    ],
    'redirect_url' => 'https://yoursite.com/payment/callback'
];

$payment = Payment::gateway('payfast')->pay($paymentRequest);
```

### Subscriptions and Recurring Payments

```php
$subscriptionData = [
    'amount' => 99.99,
    'email' => 'customer@example.com',
    'description' => 'Monthly Premium Subscription',
    'frequency' => '3', // 3 = Monthly, 2 = Weekly, 4 = Quarterly, 5 = Bi-annually, 6 = Annually
    'cycles' => '12',   // Number of billing cycles (0 = unlimited)
    'return_url' => 'https://yoursite.com/subscription/success'
];

$subscription = Payment::gateway('payfast')->createSubscription($subscriptionData);
```

### Cancel Subscription

```php
$mPaymentId = 'SUBSCRIPTION_PAYMENT_ID';
$result = Payment::gateway('payfast')->cancelSubscription($mPaymentId);
```

## Webhook Setup

PayFast uses ITN (Instant Transaction Notification) webhooks to notify your application about payment status changes.

1. Configure your webhook URL in the PayFast merchant dashboard under "Settings" → "Instant Transaction Notification"

2. Create a route to handle webhooks:

```php
// routes/web.php
Route::post('/payfast/webhook', [PayfastWebhookController::class, 'handleWebhook']);
```

3. Create the webhook controller:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class PayfastWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $gateway = Payment::gateway('payfast');
        $webhookData = $gateway->parseCallback($request);

        // Process the webhook
        $result = $gateway->verify($webhookData);

        if ($result['success']) {
            $eventType = $result['event_type'];
            $transactionId = $result['transaction_id'];
            $mPaymentId = $result['m_payment_id'];

            switch ($eventType) {
                case 'payment.completed':
                    $this->handleSuccessfulPayment($result);
                    break;

                case 'payment.pending':
                    $this->handlePendingPayment($result);
                    break;

                case 'payment.failed':
                    $this->handleFailedPayment($result);
                    break;

                case 'payment.cancelled':
                    $this->handleCancelledPayment($result);
                    break;

                default:
                    $this->logInfo('Unknown webhook event: ' . $eventType, $result);
            }
        }

        // Always return 200 OK to acknowledge receipt
        return response('OK', 200);
    }

    protected function handleSuccessfulPayment($data)
    {
        // Update order status
        $order = Order::where('transaction_id', $data['transaction_id'])->first();
        if ($order) {
            $order->status = 'paid';
            $order->paid_at = now();
            $order->payment_method = $data['payment_method'];
            $order->payfast_payment_id = $data['m_payment_id'];
            $order->merchant_info = array_merge($order->merchant_info ?? [], $data['merchant_info']);
            $order->save();

            // Send confirmation email
            Mail::to($data['merchant_info']['email_address'])->send(new PaymentConfirmation($order));
        }
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

    protected function handleFailedPayment($data)
    {
        // Log failed payment
        Log::warning('PayFast payment failed', [
            'transaction_id' => $data['transaction_id'],
            'm_payment_id' => $data['m_payment_id']
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
        $order = Order::where('transaction_id', $data['transaction_id'])->first();
        if ($order) {
            $order->status = 'cancelled';
            $order->save();
        }
    }

    protected function logInfo($message, $data)
    {
        Log::info($message, $data);
    }
}
```

## Payment Flow

1. **Initialize Payment**: Call `pay()` to generate payment data and signature
2. **POST to PayFast**: Submit form data to PayFast payment page
3. **Customer Action**: Customer completes payment in PayFast interface
4. **ITN Webhook**: PayFast sends webhook to your server
5. **Verification**: Use `verify()` to confirm payment status
6. **Complete**: Update order status and notify customer

## Webhook Security

PayFast uses MD5 signatures for webhook security:

1. **Signature Generation**: Payment requests include MD5 signature
2. **Webhook Verification**: ITN webhooks include signature verification
3. **Security Features**:
   - MD5 hash of all parameters sorted alphabetically
   - Optional passphrase for additional security
   - Parameter encoding and URL encoding

## Error Handling

The PayFast gateway provides detailed error messages:

```php
$payment = Payment::gateway('payfast')->pay($paymentRequest);

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
- `INVALID_CURRENCY` - Only ZAR is supported
- `INVALID_REQUEST` - Invalid request parameters
- `SIGNATURE_MISMATCH` - Invalid signature
- `TOKEN_REQUIRED` - Token required for refund

## Testing

### Test Mode

Use test credentials for development:

```env
PAYFAST_TEST_MODE=true
```

### Test Merchant Details

- **Merchant ID**: 10000100
- **Merchant Key**: 46f0cd694581a
- **Passphrase**: (your passphrase or leave empty for testing)

### Test URLs

- **Payment URL**: https://sandbox.payfast.co.za/eng/process
- **ITN Webhook**: Your local development URL (use ngrok for testing)

### Test Scenarios

1. **Successful Payment**: Use test credentials and complete payment flow
2. **Failed Payment**: Test various failure scenarios
3. **Refund**: Process refunds for test transactions
4. **Webhook Testing**: Use PayFast's ITN testing tools

## Payment Methods

### Available Methods in South Africa

#### Card Payments
- **Credit Cards**: Visa, Mastercard
- **Debit Cards**: Visa Debit, Mastercard Debit
- **3D Secure**: Supported for enhanced security

#### Bank Transfers
- **Instant EFT**: Direct bank transfer from major South African banks
  - ABSA
  - FNB
  - Standard Bank
  - Nedbank
  - Capitec Bank

#### Digital Wallets
- **Masterpass**: Mastercard's digital wallet
- **PayPal**: International payment option
- **Mobicred**: Buy now, pay later service
- **Scan to Pay**: QR code payments
- **Zapper**: QR code payment system

#### Mobile and Alternative Methods
- **SCode**: USSD-based payment system
- **Blu Voucher**: Prepaid voucher system
- **1Voucher**: Digital voucher payments

### Method Selection

PayFast automatically presents appropriate payment methods based on:
- Transaction amount
- Customer device (mobile/desktop)
- Browser capabilities
- Merchant configuration

## Best Practices

### Security

1. **Never expose your credentials** in frontend code
2. **Always verify ITN signatures** using the built-in verification
3. **Use HTTPS** for all webhook endpoints
4. **Implement proper error handling** and logging
5. **Validate all inputs** before processing
6. **Use passphrase** for additional signature security

### Transaction Management

1. **Store m_payment_id** during initialization for later verification
2. **Always verify payments** through ITN webhooks, not just URL returns
3. **Implement retry logic** for failed API calls
4. **Log all transaction attempts** for auditing
5. **Handle different payment methods** appropriately
6. **Set appropriate return and cancel URLs**

### User Experience

1. **Show payment processing status** to users
2. **Redirect users appropriately** after payment
3. **Display proper error messages** in case of failures
4. **Send email confirmations** for successful payments
5. **Provide support contact** for payment issues
6. **Handle payment timeouts** gracefully

## Supported Currencies

PayFast processes payments in South African Rand only:

- **ZAR** (South African Rand) - Primary and only supported currency

**Note**: If you need to accept international currencies, you'll need to handle currency conversion before sending to PayFast.

## Country Support

PayFast primarily serves South Africa:

- **South Africa** (Full feature support)
- **International**: Limited support through PayPal integration
- **Cross-border**: Supports international transactions with South African merchants

## Rate Limits

PayFast implements reasonable rate limits:
- **Standard**: 60 requests per minute
- **ITN Webhooks**: 1 webhook per transaction
- **API Queries**: Based on merchant account level

## SDK Methods Reference

### Payment Methods
- `pay()` - Initialize a payment with signature generation
- `verify($payload)` - Verify ITN webhook payload
- `verify($mPaymentId)` - Verify a transaction status via API
- `refund()` - Process a refund (full or partial)
- `getTransactionStatus()` - Get transaction status

### Subscriptions
- `createSubscription()` - Create a recurring payment subscription
- `cancelSubscription()` - Cancel an active subscription

### Utilities
- `parseCallback()` - Parse ITN webhook parameters from request
- `getSupportedCurrencies()` - Get supported currencies
- `getGatewayConfig()` - Get gateway configuration
- `getPaymentMethodsForCountry()` - Get payment methods for a country

## Advanced Integration Tips

### Custom Payment Page

```php
// Create a custom payment form
class PaymentController extends Controller
{
    public function showPaymentForm(Request $request)
    {
        $paymentData = [
            'merchant_id' => config('services.payfast.merchant_id'),
            'merchant_key' => config('services.payfast.merchant_key'),
            'return_url' => route('payment.success'),
            'cancel_url' => route('payment.cancel'),
            'notify_url' => route('payfast.webhook'),
            'm_payment_id' => 'TXN' . time(),
            'amount' => $request->input('amount', 100.00),
            'item_name' => $request->input('description', 'Product Purchase'),
            'email_address' => $request->input('email', Auth::user()->email),
        ];

        // Generate signature
        $gateway = Payment::gateway('payfast');
        $signature = $gateway->generateSignature($paymentData);
        $paymentData['signature'] = $signature;

        return view('payment.form', compact('paymentData'));
    }
}
```

### Multi-Item Cart

```php
// Handle multiple items in a single payment
$cartItems = Cart::all();
$totalAmount = $cartItems->sum('price');
$itemDescription = count($cartItems) . ' items in cart';

$paymentRequest = [
    'amount' => $totalAmount,
    'currency' => 'ZAR',
    'email' => Auth::user()->email,
    'transaction_id' => 'CART_' . time(),
    'description' => $itemDescription,
    'metadata' => [
        'custom_str1' => 'Cart Purchase',
        'custom_int1' => count($cartItems),
        'cart_id' => Cart::current()->id
    ],
    'redirect_url' => route('cart.success')
];

$payment = Payment::gateway('payfast')->pay($paymentRequest);
```

### Recurring Payments Implementation

```php
class SubscriptionController extends Controller
{
    public function create(Request $request)
    {
        $subscriptionData = [
            'amount' => 199.99,
            'email' => $request->input('email'),
            'description' => 'Monthly Premium Plan',
            'frequency' => '3', // Monthly
            'cycles' => '12',    // 12 months
            'return_url' => route('subscription.success'),
            'metadata' => [
                'plan_id' => 'premium_monthly',
                'user_id' => Auth::id()
            ]
        ];

        $subscription = Payment::gateway('payfast')->createSubscription($subscriptionData);

        if ($subscription['success']) {
            // Store subscription details
            Subscription::create([
                'user_id' => Auth::id(),
                'plan_id' => 'premium_monthly',
                'amount' => 199.99,
                'frequency' => 'monthly',
                'status' => 'pending',
                'payfast_m_payment_id' => $subscription['m_payment_id']
            ]);

            return view('payment.subscription', [
                'payment_data' => $subscription['payment_data'],
                'payment_url' => $subscription['payment_url']
            ]);
        }

        return back()->with('error', 'Failed to create subscription');
    }
}
```

### Custom Webhook Processing

```php
class PayfastWebhookHandler
{
    public function handleAdvancedWebhook(Request $request)
    {
        $gateway = Payment::gateway('payfast');
        $data = $gateway->parseCallback($request);

        // Verify signature
        if (!$this->verifySignature($data)) {
            Log::error('Invalid PayFast webhook signature', $data);
            return response('Invalid signature', 400);
        }

        // Get merchant details
        $merchant = Merchant::where('payfast_merchant_id', $data['merchant_id'])->first();
        if (!$merchant) {
            Log::error('Unknown PayFast merchant ID: ' . $data['merchant_id']);
            return response('Unknown merchant', 400);
        }

        // Process based on payment status
        switch ($data['payment_status']) {
            case 'COMPLETE':
                $this->processCompletePayment($data, $merchant);
                break;

            case 'PENDING':
                $this->processPendingPayment($data, $merchant);
                break;

            case 'FAILED':
                $this->processFailedPayment($data, $merchant);
                break;
        }

        return response('OK', 200);
    }

    private function processCompletePayment($data, $merchant)
    {
        // Find the order
        $order = Order::where('transaction_id', $data['m_payment_id'])->first();

        if ($order && $order->status !== 'paid') {
            // Update order
            $order->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_method' => $data['payment_method'],
                'transaction_fee' => $data['amount_fee'],
                'net_amount' => $data['amount_net'],
                'merchant_data' => [
                    'pf_payment_id' => $data['pf_payment_id'],
                    'name_first' => $data['name_first'],
                    'name_last' => $data['name_last'],
                    'email_address' => $data['email_address']
                ]
            ]);

            // Trigger events
            event(new PaymentCompleted($order, $merchant));

            // Send notifications
            $this->sendPaymentNotifications($order, $merchant);
        }
    }
}
```

## Support

For PayFast-specific support:
- Email: support@payfast.co.za
- Documentation: https://developers.payfast.co.za/
- Merchant Portal: https://www.payfast.co.za/
- Developer Guide: https://developers.payfast.co.za/documentation
- API Reference: https://developers.payfast.co.za/api

For Laravel Payments package support:
- GitHub Issues: https://github.com/your-username/laravel-payments/issues
- Email: your-email@example.com

## Changelog

### v1.0.0
- Initial PayFast integration
- Direct API integration (no package dependency)
- MD5 signature generation and verification
- ITN webhook processing
- Refund processing support
- Custom field support (5 string + 5 integer fields)
- Subscription and recurring billing support
- South African payment method support
- Comprehensive error handling and logging