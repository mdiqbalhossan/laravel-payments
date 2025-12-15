# Paytm Gateway Integration Guide

This guide will help you integrate Paytm payment gateway into your Laravel application using the laravel-payments package.

## Table of Contents

1. [Installation](#installation)
2. [Configuration](#configuration)
3. [Basic Usage](#basic-usage)
4. [Payment Methods](#payment-methods)
5. [Webhook Setup](#webhook-setup)
6. [Error Handling](#error-handling)
7. [Advanced Features](#advanced-features)
8. [Testing](#testing)

## Installation

1. Install the package via Composer:

```bash
composer require mdiqbal/laravel-payments
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --provider="Mdiqbal\LaravelPayments\PaymentsServiceProvider"
```

3. Add your Paytm credentials to your `.env` file:

```env
# Paytm Configuration
PAYTM_MODE=test  # or 'live' for production
PAYTM_SANDBOX_MERCHANT_ID=YourMerchantIdHere
PAYTM_SANDBOX_MERCHANT_KEY=YourMerchantKeyHere
PAYTM_LIVE_MERCHANT_ID=YourLiveMerchantIdHere
PAYTM_LIVE_MERCHANT_KEY=YourLiveMerchantKeyHere
```

## Configuration

The Paytm gateway is pre-configured in the `config/payments.php` file:

```php
'gateways' => [
    'paytm' => [
        'mode' => env('PAYTM_MODE', 'sandbox'),
        'sandbox' => [
            'merchant_id' => env('PAYTM_SANDBOX_MERCHANT_ID'),
            'merchant_key' => env('PAYTM_SANDBOX_MERCHANT_KEY'),
        ],
        'live' => [
            'merchant_id' => env('PAYTM_LIVE_MERCHANT_ID'),
            'merchant_key' => env('PAYTM_LIVE_MERCHANT_KEY'),
        ],
    ],
],
```

## Basic Usage

### 1. Creating a Transaction

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

// Create a payment request
$paymentRequest = new PaymentRequest(
    amount: 500.00,
    currency: 'INR',
    description: 'Payment for Order #12345'
);

// Set optional parameters
$paymentRequest->setTransactionId('order_' . uniqid());

// Set metadata for additional options
$paymentRequest->setMetadata([
    'email' => 'customer@example.com',
    'phone' => '9876543210',
    'customer_id' => 'CUST123',
    'notes' => [
        'order_id' => 12345,
        'customer_name' => 'John Doe'
    ]
]);

// Process payment with Paytm
$response = Payment::gateway('paytm')->pay($paymentRequest);

// Get transaction details
$orderId = $response->getData()['order_id'];
$txnToken = $response->getData()['txn_token'];
```

### 2. Frontend Integration with Paytm Checkout

Create a payment form:

```html
<!-- payment-form.blade.php -->
<form id="paytm-form" method="post" action="{{ $paymentUrl }}">
    @csrf
    <input type="hidden" name="orderid" value="{{ $orderId }}">
    <input type="hidden" name="mid" value="{{ $merchantId }}">
    <input type="hidden" name="txnToken" value="{{ $txnToken }}">
    <input type="hidden" name="amount" value="{{ $amount }}">
    <input type="hidden" name="currency" value="INR">

    <div class="payment-container">
        <h3>Pay with Paytm</h3>
        <p>Amount: ₹{{ number_format($amount, 2) }}</p>
        <button type="submit" class="btn btn-primary">Proceed to Pay</button>
    </div>
</form>

<script>
    // Optional: Auto-submit form when ready
    window.onload = function() {
        // You can customize the Paytm checkout experience here
        const form = document.getElementById('paytm-form');

        // Add loading state
        form.addEventListener('submit', function(e) {
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = 'Processing...';
        });
    };
</script>
```

### 3. Verifying Payment

After successful payment, verify it on your server:

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

// Get all Paytm response parameters
$payload = $request->all();

try {
    $response = Payment::gateway('paytm')->verify($payload);

    if ($response->isSuccess()) {
        $transactionId = $response->getTransactionId();
        $amount = $response->getData()['amount'];
        $paymentMode = $response->getData()['payment_mode'];
        $bankName = $response->getData()['bank_name'];

        // Update your database
        // ...

        return response()->json([
            'success' => true,
            'message' => 'Payment verified successfully',
            'transaction_id' => $transactionId
        ]);
    }
} catch (\Exception $e) {
    return response()->json([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
```

### 4. Processing Refunds

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

try {
    $success = Payment::gateway('paytm')->refund($transactionId, $refundAmount);

    if ($success) {
        // Refund processed successfully
        // Update your database
        // ...
    }
} catch (\Exception $e) {
    // Handle refund failure
    // ...
}
```

### 5. Checking Transaction Status

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

try {
    $response = Payment::gateway('paytm')->getTransactionStatus($orderId);

    if ($response->isSuccess()) {
        // Transaction was successful
        $status = $response->getData()['status'];
        $amount = $response->getData()['amount'];
    } else {
        // Transaction failed or pending
        $status = $response->getData()['status'];
    }
} catch (\Exception $e) {
    // Handle error
}
```

## Payment Methods

### Using Different Payment Channels

Paytm supports multiple payment channels. You can configure them in metadata:

```php
$paymentRequest->setMetadata([
    'channel_id' => 'WEB',  // or 'WAP' for mobile
    'ui_mode' => 'MERCHANT',  // or 'OFFLINE' for custom UI
]);
```

### EMI Options

Configure EMI for high-value transactions:

```php
$paymentRequest->setMetadata([
    'emi' => [
        'eligible' => true,
        'emi_banks' => ['HDFC', 'ICICI', 'SBI', 'AXIS']
    ]
]);
```

### UPI Payments

Paytm supports UPI payments:

```php
$paymentRequest->setMetadata([
    'upi' => [
        'enabled' => true,
        'apps' => ['paytm', 'gpay', 'phonepe', 'bhim']
    ]
]);
```

## Complete Payment Flow Example

### Controller Implementation

```php
// app/Http/Controllers/PaytmPaymentController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

class PaytmPaymentController extends Controller
{
    public function create()
    {
        return view('paytm.create');
    }

    public function process(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'email' => 'required|email',
            'phone' => 'required|digits:10',
            'description' => 'required|string'
        ]);

        $paymentRequest = new PaymentRequest(
            amount: $request->amount,
            currency: 'INR',
            description: $request->description
        );

        $paymentRequest->setTransactionId('order_' . uniqid());
        $paymentRequest->setMetadata([
            'email' => $request->email,
            'phone' => $request->phone,
            'customer_id' => auth()->id() ? 'CUST_' . auth()->id() : null,
            'billing_address' => [
                'name' => auth()->user()->name ?? 'Customer Name',
                'address' => '123 Street, City',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400001',
                'country' => 'India'
            ],
            'notes' => [
                'user_id' => auth()->id(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]
        ]);

        $paymentRequest->setReturnUrl(route('payment.callback'));

        $response = Payment::gateway('paytm')->pay($paymentRequest);

        return response()->json([
            'success' => true,
            'payment_url' => $response->getRedirectUrl(),
            'order_id' => $response->getData()['order_id']
        ]);
    }

    public function callback(Request $request)
    {
        $payload = $request->all();

        try {
            $response = Payment::gateway('paytm')->verify($payload);

            if ($response->isSuccess()) {
                // Update your database
                Order::where('transaction_id', $response->getTransactionId())
                    ->update([
                        'status' => 'paid',
                        'payment_id' => $response->getTransactionId(),
                        'payment_mode' => $response->getData()['payment_mode'],
                        'bank_name' => $response->getData()['bank_name'],
                        'paid_at' => now()
                    ]);

                return redirect()->route('payment.success')->with([
                    'message' => 'Payment successful',
                    'transaction_id' => $response->getTransactionId()
                ]);
            } else {
                return redirect()->route('payment.error')->with([
                    'message' => 'Payment failed',
                    'error' => $response->getData()['response_message'] ?? 'Unknown error'
                ]);
            }
        } catch (\Exception $e) {
            return redirect()->route('payment.error')->with([
                'message' => 'Payment verification failed',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function webhook(Request $request)
    {
        $payload = $request->json()->all();

        try {
            $response = Payment::gateway('paytm')->processWebhook($payload);

            if ($response->isSuccess()) {
                $eventType = $response->getData()['event_type'];
                $transactionId = $response->getTransactionId();

                // Handle different event types
                switch ($eventType) {
                    case 'transaction':
                        $status = $response->getData()['status'];
                        // Update order status
                        break;
                }
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            \Log::error('Paytm webhook error: ' . $e->getMessage());
            return response()->json(['status' => 'error'], 400);
        }
    }
}
```

### Routes Configuration

```php
// routes/web.php
use App\Http\Controllers\PaytmPaymentController;

Route::get('/payment/paytm', [PaytmPaymentController::class, 'create']);
Route::post('/payment/paytm/process', [PaytmPaymentController::class, 'process']);
Route::post('/payment/paytm/callback', [PaytmPaymentController::class, 'callback']);
Route::get('/payment/success', function() {
    return view('payment.success');
})->name('payment.success');
Route::get('/payment/error', function() {
    return view('payment.error');
})->name('payment.error');

// Webhook endpoint
Route::post('/payment/webhook/paytm', [PaytmPaymentController::class, 'webhook'])
    ->middleware(['api', 'throttle:60,1']);
```

## Webhook Setup

### 1. Configure Webhook Endpoint

The webhook endpoint is automatically created by the package. Add the route:

```php
// routes/api.php
Route::post('/payment/webhook/paytm', [WebhookController::class, 'paytm'])
    ->middleware(['api', 'throttle:60,1']);
```

### 2. Set Up Webhook in Paytm Dashboard

1. Log in to your Paytm Dashboard
2. Go to Developer Settings → Webhooks
3. Add a new webhook with the URL: `https://your-domain.com/payment/webhook/paytm`
4. Select events to listen for:
   - Transaction Success
   - Transaction Failure
   - Refund Success
   - Refund Failure

### 3. Webhook Handler with Checksum Verification

```php
// app/Http/Controllers/WebhookController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class WebhookController extends Controller
{
    public function paytm(Request $request)
    {
        $payload = $request->json()->all();

        try {
            // Verify webhook checksum
            $checksum = $request->header('x-paytm-checksum');
            if (!$checksum || !Payment::gateway('paytm')->validateChecksum($payload, $checksum)) {
                return response()->json(['status' => 'error', 'message' => 'Invalid checksum'], 401);
            }

            $response = Payment::gateway('paytm')->processWebhook($payload);

            if ($response->isSuccess()) {
                $eventType = $response->getData()['event_type'];
                $transactionId = $response->getTransactionId();

                // Find the related order
                $order = Order::where('transaction_id', $transactionId)
                    ->orWhere('payment_id', $transactionId)
                    ->first();

                if ($order) {
                    $status = $response->getData()['status'];

                    switch ($status) {
                        case 'completed':
                            $order->status = 'paid';
                            $order->paid_at = now();
                            $order->save();
                            break;

                        case 'failed':
                            $order->status = 'failed';
                            $order->save();
                            break;

                        case 'refunded':
                            $order->status = 'refunded';
                            $order->refunded_at = now();
                            $order->save();
                            break;
                    }
                }
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
```

## Error Handling

The package provides comprehensive error handling:

```php
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use TechTailor\Paytm\Exception\PaytmException;

try {
    $response = Payment::gateway('paytm')->pay($paymentRequest);
} catch (PaytmException $e) {
    // Paytm specific errors
    $message = $this->getPaytmErrorMessage($e->getCode());
} catch (PaymentException $e) {
    // General payment error
    Log::error('Payment error: ' . $e->getMessage());
} catch (\Exception $e) {
    // Other exceptions
    Log::error('Unexpected error: ' . $e->getMessage());
}

private function getPaytmErrorMessage($code): string
{
    $messages = [
        '141' => 'Check provided inputs: MID, ORDER_ID, CUST_ID, TXN_AMOUNT, CHANNEL_ID, INDUSTRY_TYPE_ID, WEBSITE',
        '227' => 'Invalid checksumhash',
        '301' => 'Invalid payment mode',
        '303' => 'Maximum transactions per month reached',
        '401' => 'Invalid Auth Token',
        '501' => 'Internal server error',
        '502' => 'Service unavailable',
        '503' => 'Invalid input parameters',
        '504' => 'Mobile number not verified',
        '508' => 'Duplicate Order ID',
        '509' => 'Refund failed due to invalid parameters',
        '510' => 'Refund amount cannot be greater than transaction amount',
        '511' => 'Refund cannot be processed at this time',
        '512' => 'Refund request already processed for this transaction',
        '513' => 'Refund failed due to invalid refund details',
        '514' => 'Refund failed due to duplicate request',
        '515' => 'Refund failed due to technical error',
        '810' => 'Transaction already processed for this order ID',
        '822' => 'Access denied to this merchant',
    ];

    return $messages[$code] ?? 'Payment processing error: ' . $code;
}
```

## Advanced Features

### 1. Custom UI Integration

For custom payment UI without Paytm's hosted page:

```php
$paymentRequest->setMetadata([
    'ui_mode' => 'MERCHANT',
    'theme' => [
        'merchantName' => 'Your Company Name',
        'merchantLogo' => 'https://your-domain.com/logo.png'
    ]
]);
```

### 2. Subscription Management

Create recurring payments:

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

// Create subscription
$subscriptionData = [
    'order_id' => 'SUB_' . uniqid(),
    'subscription_type' => 'RECURRING',
    'transaction_amount' => 100000,  // Amount in paise
    'transaction_currency' => 'INR',
    'unit_price' => 100000,
    'subscription_frequency' => 1,  // 1 = monthly
    'subscription_billing_cycle' => '12',  // 12 months
    'subscription_start_date' => now()->addDays(30)->format('Y-m-d'),
    'subscription_expiry_date' => now()->addYear()->format('Y-m-d'),
    'customer_id' => 'CUST123',
    'cust_email' => 'customer@example.com',
    'cust_phone' => '9876543210'
];

$response = Payment::gateway('paytm')->createSubscription($subscriptionData);
```

### 3. Partial Refunds

Process partial refunds:

```php
$refundData = [
    'transaction_id' => $transactionId,
    'refund_amount' => 5000,  // Amount in paise (50.00 INR)
    'refund_reason' => 'Partial refund for cancelled item'
];

$response = Payment::gateway('paytm')->initiateRefund($refundData);
```

### 4. Recurring Payments

Set up recurring payments:

```php
$recurringData = [
    'order_id' => 'REC_' . uniqid(),
    'transaction_amount' => 100000,
    'transaction_currency' => 'INR',
    'unit_price' => 100000,
    'recurring_frequency' => '1',  // Monthly
    'recurring_start_date' => now()->format('Y-m-d'),
    'recurring_end_date' => now()->addYear()->format('Y-m-d'),
    'customer_id' => 'CUST123'
];

$response = Payment::gateway('paytm')->createRecurringPayment($recurringData);
```

### 5. Express Checkout

Quick checkout for returning customers:

```php
$paymentRequest->setMetadata([
    'express_checkout' => true,
    'saved_cards' => true,
    'enable_upi' => true,
    'enable_emi' => true
]);
```

### 6. Mobile/WAP Integration

For mobile-specific integration:

```php
$paymentRequest->setMetadata([
    'channel_id' => 'WAP',
    'mobile_no' => '9876543210',
    'email' => 'customer@example.com'
]);
```

## Testing

### Using Test Credentials

Paytm provides test credentials for testing:

```env
PAYTM_MODE=test
PAYTM_SANDBOX_MERCHANT_ID=YourTestMerchantId
PAYTM_SANDBOX_MERCHANT_KEY=YourTestMerchantKey
```

### Test Card Details

For testing card payments:

| Card Type | Card Number | Expiry | CVV | Name |
|-----------|-------------|--------|-----|------|
| Visa | 4012000033330026 | 12/25 | 123 | Test User |
| Mastercard | 5424180279791762 | 12/25 | 123 | Test User |
| RuPay | 6520000000000006 | 12/25 | 123 | Test User |

### Test Bank Details

For net banking:

- Test Bank: "TestBank"
- Account: "9876543210"
- IFSC: "TEST000001"

### Test Example

```php
public function test_paytm_payment()
{
    // Set test mode
    config(['payments.gateways.paytm.mode' => 'sandbox']);
    config(['payments.gateways.paytm.sandbox.merchant_id' => 'TestMerchant123']);
    config(['payments.gateways.paytm.sandbox.merchant_key' => 'TestMerchantKey']);

    $paymentRequest = new PaymentRequest(
        amount: 500.00,
        currency: 'INR',
        description: 'Test Payment'
    );

    $paymentRequest->setTransactionId('test_order_123');
    $paymentRequest->setMetadata(['email' => 'test@example.com']);

    $response = Payment::gateway('paytm')->pay($paymentRequest);

    $this->assertTrue($response->isRedirect());
    $this->assertNotNull($response->getData()['order_id']);
    $this->assertNotNull($response->getRedirectUrl());
}
```

## Support

For issues and questions:

1. Check the [GitHub Issues](https://github.com/your-username/laravel-payments/issues)
2. Review the [Paytm Developer Documentation](https://developer.paytm.com/docs/)
3. Refer to the package documentation

## Security Notes

1. Never commit your Paytm merchant keys to version control
2. Always use HTTPS for your webhook URLs
3. Verify checksums for all Paytm responses
4. Implement proper error handling to prevent exposing sensitive information
5. Use test mode for development and testing
6. Monitor your Paytm Dashboard for suspicious transactions

## Performance Optimization

1. Cache transaction responses where appropriate
2. Use appropriate error handling and retry logic
3. Monitor your Paytm Dashboard for performance metrics
4. Implement rate limiting for payment endpoints

```php
// Example of transaction status caching
$cacheKey = "paytm_status_{$orderId}";
$transactionStatus = Cache::remember($cacheKey, 300, function () use ($orderId) {
    $response = Payment::gateway('paytm')->getTransactionStatus($orderId);
    return $response->getData()['status'];
});
```

## Best Practices

1. Always verify checksums for Paytm responses
2. Use webhooks for reliable payment status updates
3. Store transaction references for tracking
4. Set appropriate currency (Paytm primarily supports INR)
5. Use descriptive metadata for better tracking
6. Implement proper logging for debugging
7. Use unique order IDs to avoid duplicates
8. Validate all input parameters before processing

## Multi-Currency Support

While Paytm primarily supports INR, they offer limited multi-currency support:

```php
// Supported currencies
$supportedCurrencies = ['INR'];

// For international payments (if enabled in your Paytm account)
$paymentRequest = new PaymentRequest(
    amount: 100.00,
    currency: 'USD',  // Requires international account setup
    description: 'International Payment'
);
```

## Payment Gateway URLs

Remember to use the correct URLs based on your environment:

**Sandbox (Test) Environment:**
- Transaction API: `https://securegw-stage.paytm.in/theia/api/v1/initiateTransaction`
- Payment URL: `https://securegw-stage.paytm.in/theia/processTransaction`

**Production Environment:**
- Transaction API: `https://securegw.paytm.in/theia/api/v1/initiateTransaction`
- Payment URL: `https://securegw.paytm.in/theia/processTransaction`