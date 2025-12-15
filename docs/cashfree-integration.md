# Cashfree Integration Guide

This guide explains how to integrate Cashfree payment gateway with the Laravel Payments package.

## Overview

Cashfree is a leading Indian payment gateway that supports multiple payment methods including:
- Credit/Debit Cards
- Net Banking
- UPI (Unified Payments Interface)
- Wallets (Paytm, PhonePe, Amazon Pay, etc.)
- EMI (Equated Monthly Installments)
- Buy Now Pay Later (BNPL)
- International Cards
- Pay Later

## Installation

Since Cashfree SDK is already included in the package dependencies, you just need to ensure you have the Laravel Payments package installed and configured.

## Configuration

Add your Cashfree credentials to your `.env` file:

```env
CASHFREE_APP_ID=your_app_id_here
CASHFREE_SECRET_KEY=your_secret_key_here
CASHFREE_TEST_MODE=true
CASHFREE_COUNTRY=IN
CASHFREE_WEBHOOK_SECRET=your_webhook_secret_here
CASHFREE_RETURN_URL=https://yoursite.com/payment/success
CASHFREE_WEBHOOK_URL=https://yoursite.com/cashfree/webhook
```

You can obtain these credentials from your [Cashfree merchant dashboard](https://merchant.cashfree.com/).

### Environment-Specific Settings

For development/testing:

```env
CASHFREE_TEST_MODE=true
CASHFREE_APP_ID=TEST123456789
CASHFREE_SECRET_KEY=TEST-secret-key
```

For production:

```env
CASHFREE_TEST_MODE=false
CASHFREE_APP_ID=PROD123456789
CASHFREE_SECRET_KEY=PROD-secret-key
```

You also need to add the configuration to your `config/services.php`:

```php
'cashfree' => [
    'app_id' => env('CASHFREE_APP_ID'),
    'secret_key' => env('CASHFREE_SECRET_KEY'),
    'test_mode' => env('CASHFREE_TEST_MODE', true),
    'country' => env('CASHFREE_COUNTRY', 'IN'),
    'webhook_secret' => env('CASHFREE_WEBHOOK_SECRET'),
    'return_url' => env('CASHFREE_RETURN_URL'),
    'webhook_url' => env('CASHFREE_WEBHOOK_URL'),
],
```

## Basic Usage

### Initialize Payment

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

$paymentRequest = [
    'amount' => 1000.00,
    'currency' => 'INR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'redirect_url' => 'https://yoursite.com/payment/callback',
    'customer' => [
        'name' => 'Rahul Sharma',
        'phone' => '9876543210',
        'address' => '123 Main St',
        'city' => 'Mumbai',
        'country' => 'IN',
        'postal_code' => '400001'
    ],
    'metadata' => [
        'order_id' => 'ORD123456',
        'user_id' => 789
    ]
];

$payment = Payment::gateway('cashfree')->pay($paymentRequest);
```

This will return a payment URL that you need to redirect the user to:

```php
if ($payment['success']) {
    // Store order_id for later verification
    session(['cashfree_order_id' => $payment['order_id']]);

    // Redirect to Cashfree payment page
    return redirect($payment['payment_url']);
}
```

### Process Payment Return

```php
// routes/web.php
Route::get('/payment/success', [PaymentController::class, 'success']);
Route::post('/cashfree/webhook', [CashfreeController::class, 'webhook']);
```

```php
// app/Http/Controllers/CashfreeController.php

use Mdiqbal\LaravelPayments\Facades\Payment;

class CashfreeController extends Controller
{
    public function webhook(Request $request)
    {
        // Parse webhook data
        $gateway = Payment::gateway('cashfree');
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
                    $order->cashfree_order_id = $result['order_id'];
                    $order->save();
                }
            }
        }

        return response()->json(['status' => 'received']);
    }

    public function success(Request $request)
    {
        // Handle successful return from payment page
        // Note: Always rely on webhook for final status confirmation
        return view('payment.success');
    }
}
```

### Verify Payment

```php
// First, get the order ID (stored during initialization)
$orderId = session('cashfree_order_id');

$verification = Payment::gateway('cashfree')->verify($orderId);

if ($verification['success']) {
    $status = $verification['status'];

    if ($status === 'completed') {
        // Payment was successful
        $orderId = $verification['order_id'];
        $amount = $verification['amount'];
        $currency = $verification['currency'];
        $paymentMethod = $verification['payment_method'];
    }
}
```

### Process Refund

```php
$refundData = [
    'order_id' => 'CASHFREE_ORDER_ID',
    'amount' => 500.00, // Optional - omit for full refund
    'reason' => 'Customer requested refund',
    'refund_id' => 'REF-' . time() // Optional unique refund ID
];

$refund = Payment::gateway('cashfree')->refund($refundData);
```

## Advanced Features

### Get Transaction Status

```php
$orderId = 'CF_ORDER_ID';
$status = Payment::gateway('cashfree')->getTransactionStatus($orderId);
```

### Search Transactions

```php
// Search with filters
$results = Payment::gateway('cashfree')->searchTransactions([
    'order_status' => 'PAID',
    'order_date_from' => '2024-01-01',
    'order_date_to' => '2024-12-31',
    'limit' => 20
]);
```

### Customer Details in Payment

```php
$paymentRequest = [
    'amount' => 1000.00,
    'currency' => 'INR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'customer' => [
        'name' => 'Priya Singh',
        'phone' => '9876543210',
        'address' => '123 Main Street',
        'city' => 'Bangalore',
        'state' => 'Karnataka',
        'country' => 'IN',
        'postal_code' => '560001'
    ],
    'redirect_url' => 'https://yoursite.com/payment/callback'
];

$payment = Payment::gateway('cashfree')->pay($paymentRequest);
```

### Payment Method Selection

```php
$paymentRequest = [
    // ... other fields
    'metadata' => [
        'payment_methods' => ['cc', 'dc', 'upi', 'nb', 'wallet'], // Allowed payment methods
        'payment_options' => [
            'cc' => ['min_amount' => 100], // Card minimum amount
            'emi' => ['max_emis' => 12],   // Maximum EMI months
            'wallet' => ['allowed_wallets' => ['paytm', 'phonepe', 'amazonpay']]
        ]
    ]
];
```

## Webhook Setup

Cashfree uses webhooks to notify your application about payment status changes.

1. Configure your webhook URL in the Cashfree merchant dashboard

2. Create a route to handle webhooks:

```php
// routes/web.php
Route::post('/cashfree/webhook', [CashfreeWebhookController::class, 'handleWebhook']);
```

3. Create the webhook controller:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class CashfreeWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $gateway = Payment::gateway('cashfree');
        $webhookData = $gateway->parseCallback($request);

        // Process the webhook
        $result = $gateway->verify($webhookData);

        if ($result['success']) {
            $eventType = $result['event_type'];
            $transactionId = $result['transaction_id'];
            $orderId = $result['order_id'];

            switch ($eventType) {
                case 'payment.completed':
                case 'payment.captured':
                    $this->handleSuccessfulPayment($result);
                    break;

                case 'payment.pending':
                    $this->handlePendingPayment($result);
                    break;

                case 'payment.failed':
                    $this->handleFailedPayment($result);
                    break;

                default:
                    $this->logInfo('Unknown webhook event: ' . $eventType, $result);
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
            $order->cashfree_order_id = $data['order_id'];
            $order->customer_details = array_merge($order->customer_details ?? [], $data['customer_details']);
            $order->merchant_info = array_merge($order->merchant_info ?? [], $data['merchant_info']);
            $order->save();

            // Send confirmation email
            Mail::to($order->customer_email)->send(new PaymentConfirmation($order));
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
        Log::warning('Cashfree payment failed', [
            'transaction_id' => $data['transaction_id'],
            'order_id' => $data['order_id']
        ]);

        // Update order status
        $order = Order::where('transaction_id', $data['transaction_id'])->first();
        if ($order) {
            $order->status = 'failed';
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

1. **Initialize Payment**: Call `pay()` to create an order and get checkout URL
2. **Redirect User**: Redirect customer to Cashfree payment page
3. **Customer Action**: Customer completes payment in Cashfree interface
4. **Webhook**: Cashfree sends webhook to your server
5. **Verification**: Use `verify()` to confirm payment status
6. **Complete**: Update order status and notify customer

## Webhook Security

Cashfree uses HMAC SHA256 signatures for webhook security:

1. **Signature Verification**: Gateway automatically verifies signatures if `webhook_secret` is configured
2. **Timestamp Validation**: Rejects webhooks older than 5 minutes to prevent replay attacks
3. **Headers Used**:
   - `X-Cashfree-Signature`: The HMAC signature
   - `X-Webhook-Timestamp`: The timestamp when webhook was generated

## Error Handling

The Cashfree gateway provides detailed error messages:

```php
$payment = Payment::gateway('cashfree')->pay($paymentRequest);

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
- `INVALID_CURRENCY` - Unsupported currency
- `INVALID_REQUEST` - Invalid request parameters
- `ORDER_NOT_FOUND` - Order not found
- `REFUND_NOT_ELIGIBLE` - Order is not eligible for refund

## Testing

### Test Mode

Use test credentials for development:

```env
CASHFREE_TEST_MODE=true
```

### Test Cards

Use these test card details for testing:

#### Successful Payments
- **Visa**: 4242 4242 4242 4242
- **Mastercard**: 5555 5555 5555 4444
- **Maestro**: 5610 5910 5910 5810
- **American Express**: 3700 000000 00002
- **Diners Club**: 3050 0000 0000 05

#### Failed Payments
- **Declined**: 5105 1051 0510 5100
- **Insufficient Funds**: 4111 1111 1111 1111
- **Invalid CVC**: 4242 4242 4242 4241

#### UPI Testing
- **Test UPI ID**: testuser@paytm
- **Success Phone**: 9999999999
- **Fail Phone**: 8888888888

### Test Scenarios

1. **Successful Payment**: Use valid test card details
2. **Failed Payment**: Use declined card details
3. **Pending Payment**: Net banking or UPI processing
4. **Refund**: Process refunds for test transactions
5. **Webhook Testing**: Test webhook endpoints using Cashfree's webhook testing tools

## Payment Methods

### Available Methods in India

#### Card Payments
- **Credit Cards**: Visa, Mastercard, American Express, Diners Club, Maestro
- **Debit Cards**: Visa, Mastercard, Maestro, RuPay
- **International Cards**: Supported for international transactions

#### Digital Payments
- **UPI**: All UPI apps (PhonePe, Paytm, Google Pay, etc.)
- **Wallets**: Paytm, PhonePe, Amazon Pay, MobiKwik, FreeCharge, Airtel Money
- **Net Banking**: All major Indian banks (50+ banks supported)

#### Special Payment Methods
- **EMI**: Credit card EMI (3, 6, 9, 12 months)
- **Buy Now Pay Later**: LazyPay, ZestMoney, Simpl, Ola Money Postpaid
- **Pay Later**: Cashfree Pay Later (instant credit)

#### Other Methods
- **International Payments**: International cards and wallets
- **Corporate Banking**: NEFT, RTGS for high-value transactions

### Method Selection Logic

Cashfree automatically determines appropriate payment methods based on:
- Transaction amount
- Customer location and IP
- Merchant configuration
- Device type (mobile vs desktop)

## Best Practices

### Security

1. **Never expose your API keys** in frontend code
2. **Always verify webhook signatures** using the built-in verification
3. **Use HTTPS** for all webhook endpoints
4. **Implement proper error handling** and logging
5. **Validate all inputs** before processing
6. **Never trust request data** - always verify with API

### Transaction Management

1. **Store order IDs** during initialization for later verification
2. **Always verify payments** through webhooks, not just URL returns
3. **Implement retry logic** for failed API calls
4. **Log all transaction attempts** for auditing
5. **Handle different payment methods** appropriately
6. **Set appropriate order expiry times**

### User Experience

1. **Show payment processing status** to users
2. **Redirect users appropriately** after payment
3. **Display proper error messages** in case of failures
4. **Send email confirmations** for successful payments
5. **Provide support contact** for payment issues
6. **Handle payment timeouts** gracefully

### Performance Optimization

1. **Cache payment method lists** for faster UI
2. **Implement connection pooling** for API calls
3. **Use asynchronous processing** for webhook handling
4. **Optimize database queries** for order updates
5. **Implement rate limiting** for webhook endpoints

## Supported Currencies

Cashfree processes payments in multiple currencies:

- **INR** (Indian Rupee) - Primary currency
- **USD** (US Dollar) - International payments
- **EUR** (Euro) - International payments
- **GBP** (British Pound) - International payments
- **AED** (UAE Dirham) - International payments
- **SAR** (Saudi Riyal) - International payments
- **AUD** (Australian Dollar) - International payments
- **CAD** (Canadian Dollar) - International payments
- **SGD** (Singapore Dollar) - International payments
- **THB** (Thai Baht) - International payments

## Country Support

Cashfree primarily serves India with international payment support:

- **India** (Full feature support)
- **International** (Limited support through international payment methods)
- **NRI Payments** (Supported for NRI customers)
- **Cross-border** (Limited support for international merchants)

## Rate Limits

Cashfree implements rate limits to prevent abuse:
- **Standard**: 100 requests per minute per IP
- **High Volume**: 500 requests per minute (on request)
- **Webhooks**: 1000 events per hour
- **Bulk Operations**: 10 requests per minute

## SDK Methods Reference

### Payment Methods
- `pay()` - Initialize a payment and create order
- `verify($payload)` - Verify webhook payload
- `verify($orderId)` - Verify a transaction status
- `refund()` - Process a refund (full or partial)
- `getTransactionStatus()` - Get transaction status

### Transaction Management
- `searchTransactions()` - Search transactions with filters

### Utilities
- `parseCallback()` - Parse webhook parameters from request
- `getSupportedCurrencies()` - Get supported currencies
- `getGatewayConfig()` - Get gateway configuration
- `getPaymentMethodsForCountry()` - Get payment methods for a country

## Advanced Integration Tips

### Order Management

```php
// Create order with custom expiry time
$paymentRequest = [
    'amount' => 1000.00,
    'currency' => 'INR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'metadata' => [
        'order_expiry' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        'auto_retry' => false,
        'send_sms' => true,
        'send_email' => true
    ],
    'redirect_url' => 'https://yoursite.com/payment/callback'
];

$payment = Payment::gateway('cashfree')->pay($paymentRequest);
```

### Payment Method Restrictions

```php
// Restrict payment methods for specific amounts
$paymentRequest = [
    'amount' => 500.00,
    'currency' => 'INR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'metadata' => [
        'payment_methods' => ['upi', 'wallet'], // Only UPI and wallets
        'restrict_payment_options' => true
    ],
    'redirect_url' => 'https://yoursite.com/payment/callback'
];
```

### Custom Customer ID

```php
// Use your own customer ID instead of transaction ID
$paymentRequest = [
    'amount' => 1000.00,
    'currency' => 'INR',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'customer' => [
        'id' => 'CUST_' . $userId, // Your customer ID
        'name' => 'Customer Name',
        'phone' => '9876543210'
    ],
    'redirect_url' => 'https://yoursite.com/payment/callback'
];
```

### Recurring Payments Setup

While Cashfree doesn't have direct subscription API in this SDK, you can implement recurring payments:

```php
class RecurringPaymentController extends Controller
{
    public function initiateRecurring($subscription)
    {
        // Create initial payment
        $paymentRequest = [
            'amount' => $subscription->amount,
            'currency' => 'INR',
            'email' => $subscription->customer_email,
            'transaction_id' => 'SUB_' . $subscription->id . '_' . date('Ym'),
            'description' => 'Subscription payment - ' . $subscription->plan_name,
            'metadata' => [
                'subscription_id' => $subscription->id,
                'is_recurring' => true,
                'next_billing_date' => $subscription->next_billing_date
            ],
            'redirect_url' => 'https://yoursite.com/subscription/success'
        ];

        $payment = Payment::gateway('cashfree')->pay($paymentRequest);

        if ($payment['success']) {
            // Store for future recurring billing
            $subscription->last_cashfree_order_id = $payment['order_id'];
            $subscription->save();
        }
    }
}
```

### Multi-Currency Support

```php
// Handle international payments
if ($customerCountry !== 'IN') {
    $paymentRequest = [
        'amount' => $amountInUSD,
        'currency' => 'USD',
        'email' => $customerEmail,
        'transaction_id' => 'INTL_' . time(),
        'customer' => [
            'name' => $customerName,
            'phone' => $customerPhone,
            'address' => $customerAddress,
            'country' => $customerCountry
        ],
        'metadata' => [
            'international_payment' => true,
            'customer_country' => $customerCountry
        ],
        'redirect_url' => 'https://yoursite.com/payment/callback'
    ];

    $payment = Payment::gateway('cashfree')->pay($paymentRequest);
}
```

### Session Management

```php
class PaymentController extends Controller
{
    public function initiate(Request $request)
    {
        $payment = Payment::gateway('cashfree')->pay($request->all());

        if ($payment['success']) {
            // Store payment information in session
            session([
                'cashfree_order_id' => $payment['order_id'],
                'transaction_id' => $payment['transaction_id'],
                'amount' => $payment['amount'],
                'initiated_at' => now()
            ]);

            return redirect($payment['payment_url']);
        }

        return back()->with('error', 'Failed to initialize payment');
    }

    public function success(Request $request)
    {
        // User returned from Cashfree after payment
        // Wait for webhook confirmation before updating order
        return view('payment.processing', [
            'order_id' => session('cashfree_order_id')
        ]);
    }

    public function checkPaymentStatus(Request $request)
    {
        $orderId = session('cashfree_order_id');

        if ($orderId) {
            $status = Payment::gateway('cashfree')->getTransactionStatus($orderId);

            if ($status['success'] && in_array($status['status'], ['completed', 'failed'])) {
                // Clear session
                session()->forget(['cashfree_order_id', 'transaction_id', 'amount', 'initiated_at']);

                return response()->json(['status' => $status['status']]);
            }
        }

        return response()->json(['status' => 'processing']);
    }
}
```

## Support

For Cashfree-specific support:
- Email: merchants@cashfree.com
- Documentation: https://docs.cashfree.com/
- Developer Portal: https://dev.cashfree.com/
- API Reference: https://docs.cashfree.com/docs/
- Support: https://cashfree.com/contact

For Laravel Payments package support:
- GitHub Issues: https://github.com/your-username/laravel-payments/issues
- Email: your-email@example.com

## Changelog

### v1.0.0
- Initial Cashfree integration
- Order-based payment processing
- Webhook signature verification
- Refund processing (full and partial)
- Customer details management
- Transaction search functionality
- Multi-currency support
- Advanced payment method configuration
- Comprehensive error handling and logging
- Support for Indian and international payment methods