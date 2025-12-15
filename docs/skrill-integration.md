# Skrill Integration Guide

This guide explains how to integrate Skrill payment gateway with the Laravel Payments package using their PSD2 API.

## Overview

Skrill (formerly Moneybookers) is a global digital wallet and payment gateway that supports:
- Digital Wallet Payments (Skrill Wallet)
- Credit/Debit Cards (Visa, Mastercard, Maestro, AMEX)
- Direct Bank Transfers
- Local Payment Methods (country-specific)
- Cryptocurrency Payments
- Recurring Payments and Subscriptions

## Installation

Skrill integration uses direct API calls through their PSD2 API, so no additional package is required. Just ensure you have the Laravel Payments package installed and configured.

## Configuration

Add your Skrill credentials to your `.env` file:

```env
SKRILL_MERCHANT_ID=your_merchant_id_here
SKRILL_API_KEY=your_api_key_here
SKRILL_TEST_MODE=true
SKRILL_WEBHOOK_URL=https://yoursite.com/skrill/webhook
SKRILL_RETURN_URL=https://yoursite.com/payment/success
```

You can obtain these credentials from your [Skrill merchant dashboard](https://www.skrill.com/merchant/).

### Environment-Specific Settings

For development/testing:

```env
SKRILL_TEST_MODE=true
SKRILL_MERCHANT_ID=test_merchant_id
SKRILL_API_KEY=test_api_key
```

For production:

```env
SKRILL_TEST_MODE=false
SKRILL_MERCHANT_ID=your_production_merchant_id
SKRILL_API_KEY=your_production_api_key
```

You also need to add the configuration to your `config/services.php`:

```php
'skrill' => [
    'merchant_id' => env('SKRILL_MERCHANT_ID'),
    'api_key' => env('SKRILL_API_KEY'),
    'test_mode' => env('SKRILL_TEST_MODE', true),
    'webhook_url' => env('SKRILL_WEBHOOK_URL'),
    'return_url' => env('SKRILL_RETURN_URL'),
],
```

## Basic Usage

### Initialize Payment

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'EUR', // Skrill's primary currency
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'redirect_url' => 'https://yoursite.com/payment/callback',
    'customer' => [
        'name' => 'John Doe',
        'phone' => '+447123456789',
        'address' => '123 Main St',
        'city' => 'London',
        'state' => 'London',
        'country' => 'GB',
        'postal_code' => 'SW1A 0AA'
    ],
    'metadata' => [
        'order_id' => 'ORD123456',
        'user_id' => 789,
        'customer_id' => 12345
    ]
];

$payment = Payment::gateway('skrill')->pay($paymentRequest);
```

This will return a payment URL that you need to redirect the user to:

```php
if ($payment['success']) {
    // Store session_id for later verification
    session(['skrill_session_id' => $payment['session_id']]);

    // Redirect to Skrill payment page
    return redirect($payment['payment_url']);
}
```

### Process Payment Return

```php
// routes/web.php
Route::get('/payment/success', [PaymentController::class, 'success']);
Route::post('/skrill/webhook', [SkrillController::class, 'webhook']);
```

```php
// app/Http/Controllers/SkrillController.php

use Mdiqbal\LaravelPayments\Facades\Payment;

class SkrillController extends Controller
{
    public function webhook(Request $request)
    {
        // Parse webhook data
        $gateway = Payment::gateway('skrill');
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
                    $order->skrill_session_id = $result['session_id'];
                    $order->save();
                }
            }
        }

        // Always return a 200 OK response to acknowledge receipt
        return response('OK', 200);
    }

    public function success(Request $request)
    {
        // User returned from Skrill after successful payment
        // Note: Always rely on webhook for final status confirmation
        return view('payment.success');
    }
}
```

### Verify Payment

```php
// First, get the session ID (stored during initialization)
$sessionId = session('skrill_session_id');

$verification = Payment::gateway('skrill')->verify($sessionId);

if ($verification['success']) {
    $status = $verification['status'];

    if ($status === 'completed') {
        // Payment was successful
        $sessionId = $verification['session_id'];
        $amount = $verification['amount'];
        $currency = $verification['currency'];
        $paymentMethod = $verification['payment_method'];
    }
}
```

### Process Refund

```php
$refundData = [
    'session_id' => 'SKRILL_SESSION_ID',
    'amount' => 50.00, // Optional - omit for full refund
    'reason' => 'Customer requested refund'
];

$refund = Payment::gateway('skrill')->refund($refundData);
```

## Advanced Features

### Get Transaction Status

```php
$sessionId = 'SKRILL_SESSION_ID';
$status = Payment::gateway('skrill')->getTransactionStatus($sessionId);
```

### Search Transactions

```php
// Search with filters
$results = Payment::gateway('skrill')->searchTransactions([
    'status' => 'processed',
    'from_date' => '2024-01-01',
    'to_date' => '2024-12-31',
    'limit' => 20,
    'offset' => 0
]);
```

### Payment Method Selection

```php
$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'EUR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'metadata' => [
        'payment_methods' => ['cc', 'wallet', 'bank'], // Allowed payment methods
        'language' => 'en' // Payment page language
    ],
    'redirect_url' => 'https://yoursite.com/payment/callback'
];

$payment = Payment::gateway('skrill')->pay($paymentRequest);
```

### Create Subscription

```php
$subscriptionData = [
    'amount' => 29.99,
    'currency' => 'EUR',
    'email' => 'customer@example.com',
    'customer_name' => 'John Doe',
    'description' => 'Monthly Premium Subscription',
    'frequency' => 1,      // 1 = monthly, 2 = quarterly, 3 = yearly
    'period' => 'M',         // M = monthly, Q = quarterly, Y = yearly
    'cycles' => 12,          // Number of cycles (0 = unlimited)
    'return_url' => 'https://yoursite.com/subscription/success'
];

$subscription = Payment::gateway('skrill')->createSubscription($subscriptionData);
```

### Cancel Subscription

```php
$subscriptionId = 'SKRILL_SUBSCRIPTION_ID';
$result = Payment::gateway('skrill')->cancelSubscription($subscriptionId);
```

## Webhook Setup

Skrill uses webhooks to notify your application about payment status changes.

1. Configure your webhook URL in the Skrill merchant dashboard under "Settings" → "Notifications"

2. Create a route to handle webhooks:

```php
// routes/web.php
Route::post('/skrill/webhook', [SkrillWebhookController::class, 'handleWebhook']);
```

3. Create the webhook controller:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class SkrillWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $gateway = Payment::gateway('skrill');
        $webhookData = $gateway->parseCallback($request);

        // Process the webhook
        $result = $gateway->verify($webhookData);

        if ($result['success']) {
            $eventType = $result['event_type'];
            $transactionId = $result['transaction_id'];
            $sessionId = $result['session_id'];

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

                case 'payment.refunded':
                    $this->handleRefundedPayment($result);
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
            $order->skrill_session_id = $data['session_id'];
            $order->merchant_info = array_merge($order->merchant_info ?? [], $data['merchant_info']);
            $order->save();

            // Send confirmation email
            Mail::to($data['customer_info']['email'])->send(new PaymentConfirmation($order));
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
        Log::warning('Skrill payment failed', [
            'transaction_id' => $data['transaction_id'],
            'session_id' => $data['session_id']
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

    protected function handleRefundedPayment($data)
    {
        // Process refund notification
        $order = Order::where('transaction_id', $data['transaction_id'])->first();
        if ($order) {
            $order->status = 'refunded';
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

1. **Initialize Payment**: Call `pay()` to create a payment session
2. **Redirect User**: Redirect customer to Skrill payment page
3. **Customer Action**: Customer completes payment in Skrill interface
4. **Webhook**: Skrill sends webhook to your server
5. **Verification**: Use `verify()` to confirm payment status
6. **Complete**: Update order status and notify customer

## Webhook Security

Skrill webhook security features:

1. **HTTPS Required**: All webhook URLs must use HTTPS
2. **IP Whitelisting**: Configure allowed IP addresses in Skrill dashboard
3. **Signature**: Optional signature verification if configured
4. **Retry Logic**: Skrill retries failed webhook deliveries

## Error Handling

The Skrill gateway provides detailed error messages:

```php
$payment = Payment::gateway('skrill')->pay($paymentRequest);

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
- `INVALID_CURRENCY` - Currency not supported
- `INVALID_REQUEST` - Invalid request parameters
- `SESSION_EXPIRED` - Payment session expired
- `INSUFFICIENT_FUNDS` - Insufficient funds in customer account

## Testing

### Test Mode

Use test credentials for development:

```env
SKRILL_TEST_MODE=true
```

### Test Credentials

For testing in sandbox mode:
- **Merchant ID**: Provided in test account
- **API Key**: Provided in test account
- **Test Emails**: Use any email with @test.com for sandbox testing

### Test Scenarios

1. **Successful Payment**: Complete the payment flow in test environment
2. **Failed Payment**: Use test scenarios that trigger failures
3. **Refund**: Process refunds for test transactions
4. **Webhook Testing**: Use Skrill's webhook testing tools

## Payment Methods

### Available Methods by Region

#### Europe
- **Credit/Debit Cards**: Visa, Mastercard, Maestro, American Express
- **Digital Wallet**: Skrill Wallet, Neteller
- **Bank Transfer**: SEPA Direct Debit, ACH Bank Transfer
- **Local Methods**: Giropay (Germany), iDEAL (Netherlands), Sofort (Germany)

#### UK
- **Credit/Debit Cards**: Visa, Mastercard, Maestro, AMEX
- **Digital Wallet**: Skrill Wallet, PayPal (via Skrill)
- **Bank Transfer**: Faster Payments
- **Local Methods**: Ukash, Paysafecard

#### Global
- **Digital Wallet**: Skrill Wallet (available worldwide)
- **Credit/Debit Cards**: International cards where supported
- **Bank Transfers**: SWIFT transfers for international payments
- **Cryptocurrency**: Bitcoin, Bitcoin Cash, Ethereum, Litecoin (where permitted)

### Payment Instrument Types

- `cc` - Credit/Debit Card
- `wallet` - Skrill Wallet
- `bank` - Bank Transfer
- `local_method` - Country-specific local payment method
- `crypto` - Cryptocurrency

## Best Practices

### Security

1. **Never expose your API key** in frontend code
2. **Use HTTPS** for all webhook endpoints
3. **Implement IP whitelisting** for webhook security
4. **Validate all inputs** before processing
5. **Store sensitive data securely** if needed
6. **Log security events** appropriately

### Transaction Management

1. **Store session IDs** during initialization for later verification
2. **Always verify payments** through webhooks or API queries
3. **Implement retry logic** for failed API calls
4. **Handle different payment methods** appropriately
5. **Use appropriate error handling** for different scenarios

### User Experience

1. **Show payment processing status** to users
2. **Redirect users appropriately** after payment
3. **Display proper error messages** in case of failures
4. **Send email confirmations** for successful payments
5. **Provide support contact** for payment issues
6. **Handle payment timeouts** gracefully

## Supported Currencies

Skrill supports a wide range of currencies:

- **EUR** (Euro) - Primary currency
- **USD** (US Dollar)
- **GBP** (British Pound)
- **PLN** (Polish Zloty)
- **CZK** (Czech Koruna)
- **DKK** (Danish Krone)
- **NOK** (Norwegian Krone)
- **SEK** (Swedish Krona)
- **CHF** (Swiss Franc)
- **CAD** (Canadian Dollar)
- **AUD** (Australian Dollar)
- **JPY** (Japanese Yen)
- **HKD** (Hong Kong Dollar)
- **SGD** (Singapore Dollar)
- **ZAR** (South African Rand)
- **INR** (Indian Rupee)
- And many more...

## Country Support

Skrill operates globally with varying feature availability:

- **Europe**: Full feature support
- **UK**: Full feature support
- **North America**: Limited support
- **Asia**: Limited support
- **Australia**: Full feature support
- **Rest of World**: Digital wallet and limited card support

## Rate Limits

Skrill implements reasonable rate limits:
- **API Requests**: 60 requests per minute per IP
- **Payment Sessions**: 1000 per hour per merchant
- **Webhooks**: One per transaction, with retry logic

## SDK Methods Reference

### Payment Methods
- `pay()` - Initialize a payment and create session
- `verify($payload)` - Verify webhook payload
- `verify($sessionId)` - Verify a transaction status via API
- `refund()` - Process a refund (full or partial)
- `getTransactionStatus()` - Get transaction status

### Transaction Management
- `searchTransactions()` - Search transactions with filters

### Subscriptions
- `createSubscription()` - Create a recurring subscription
- `cancelSubscription()` - Cancel an active subscription

### Utilities
- `parseCallback()` - Parse webhook parameters from request
- `getSupportedCurrencies()` - Get supported currencies
- `getGatewayConfig()` - Get gateway configuration
- `getPaymentMethodsForCountry()` - Get payment methods for a country

## Advanced Integration Tips

### Custom Field Configuration

```php
$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'EUR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'metadata' => [
        'product_id' => 'PROD_123',      // Custom field 1
        'affiliate_id' => 'AFF_456',      // Custom field 2
        'campaign_id' => 'CAM_789',       // Custom field 3
        'user_level' => 'premium',         // Custom field 4
        'source' => 'mobile_app',          // Custom field 5
    ],
    'redirect_url' => 'https://yoursite.com/payment/callback'
];

$payment = Payment::gateway('skrill')->pay($paymentRequest);
```

### Recurring Payments Setup

```php
class SubscriptionController extends Controller
{
    public function createSubscription(Request $request)
    {
        $subscriptionData = [
            'amount' => 29.99,
            'currency' => 'EUR',
            'email' => $request->input('email'),
            'customer_name' => $request->input('name'),
            'description' => 'Monthly Premium Plan',
            'frequency' => 1,      // Monthly
            'period' => 'M',         // Monthly
            'cycles' => 12,          // 12 months
            'return_url' => route('subscription.success'),
            'metadata' => [
                'plan_type' => 'premium',
                'user_id' => Auth::id()
            ]
        ];

        $subscription = Payment::gateway('skrill')->createSubscription($subscriptionData);

        if ($subscription['success']) {
            // Store subscription details
            Subscription::create([
                'user_id' => Auth::id(),
                'plan_id' => 'premium_monthly',
                'amount' => 29.99,
                'currency' => 'EUR',
                'status' => 'pending',
                'skrill_session_id' => $subscription['session_id']
            ]);

            return redirect($subscription['payment_url']);
        }

        return back()->with('error', 'Failed to create subscription');
    }

    public function processSubscription($sessionId)
    {
        $subscription = Subscription::where('skrill_session_id', $sessionId)->first();

        if ($subscription) {
            $status = Payment::gateway('skrill')->verify($sessionId);

            if ($status['success'] && $status['status'] === 'completed') {
                $subscription->update([
                    'status' => 'active',
                    'activated_at' => now()
                ]);

                // Grant user access
                Auth::user()->update(['premium_until' => now()->addMonth()]);
            }
        }
    }
}
```

### Multi-Currency Support

```php
class PaymentController extends Controller
{
    public function createPayment(Request $request)
    {
        $userCurrency = $this->getUserCurrency($request->ip());
        $amount = $this->convertAmount($request->input('amount'), 'USD', $userCurrency);

        $paymentRequest = [
            'amount' => $amount,
            'currency' => $userCurrency,
            'email' => Auth::user()->email,
            'transaction_id' => 'TXN_' . time() . '_' . $userCurrency,
            'customer' => [
                'name' => Auth::user()->name,
                'country' => $this->getCountryCode($request->ip()),
            ],
            'redirect_url' => route('payment.callback')
        ];

        return Payment::gateway('skrill')->pay($paymentRequest);
    }

    private function convertAmount($amount, $from, $to)
    {
        // Implement currency conversion logic
        return $amount; // Simplified for example
    }
}
```

### Error Handling and Logging

```php
class SkrillPaymentService
{
    protected function handleApiError($response, $request)
    {
        $errorData = $response->json();

        Log::error('Skrill API Error', [
            'status_code' => $response->status(),
            'error_code' => $errorData['error']['code'] ?? 'unknown',
            'error_message' => $errorData['error']['message'] ?? 'Unknown error',
            'request_data' => $request,
            'timestamp' => now()
        ]);

        // Send alert to admin for critical errors
        if ($response->status() >= 500) {
            $this->sendAlertToAdmin('Skrill API Error', $errorData['error']['message'] ?? 'API Error');
        }

        // Return user-friendly error message
        return $this->getUserFriendlyErrorMessage($errorData['error']['code'] ?? 'unknown');
    }

    private function getUserFriendlyErrorMessage($errorCode)
    {
        $errorMessages = [
            'INVALID_AMOUNT' => 'Invalid payment amount',
            'INVALID_CURRENCY' => 'Currency not supported',
            'INVALID_EMAIL' => 'Invalid email address',
            'SESSION_EXPIRED' => 'Payment session has expired',
            'INSUFFICIENT_FUNDS' => 'Insufficient funds in account',
            'CARD_DECLINED' => 'Payment card was declined',
            'NETWORK_ERROR' => 'Payment processing error, please try again'
        ];

        return $errorMessages[$errorCode] ?? 'Payment processing failed';
    }
}
```

## Support

For Skrill-specific support:
- Email: merchantsupport@skrill.com
- Documentation: https://developer-psd2.skrill.com/api-reference
- Developer Portal: https://www.skrill.com/business/developers/
- API Reference: https://developer-psd2.skrill.com/api-reference
- Merchant Dashboard: https://www.skrill.com/merchant/

For Laravel Payments package support:
- GitHub Issues: https://github.com/your-username/laravel-payments/issues
- Email: your-email@example.com

## Changelog

### v1.0.0
- Initial Skrill integration using PSD2 API
- Payment session creation and management
- Webhook notification processing
- Refund processing support
- Subscription and recurring billing support
- Multi-currency support with 20+ currencies
- Custom field support (up to 5 fields)
- Transaction search and filtering
- Comprehensive error handling and logging
- Support for global payment methods and local options