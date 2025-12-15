# PayPal Gateway Integration Guide

This guide will help you integrate PayPal payment gateway into your Laravel application using the laravel-payments package.

## Table of Contents

1. [Installation](#installation)
2. [Configuration](#configuration)
3. [Basic Usage](#basic-usage)
4. [Payment Flow](#payment-flow)
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

3. Add your PayPal credentials to your `.env` file:

```env
# PayPal Configuration
PAYPAL_MODE=sandbox  # or 'live' for production
PAYPAL_SANDBOX_CLIENT_ID=your_sandbox_client_id
PAYPAL_SANDBOX_CLIENT_SECRET=your_sandbox_client_secret
PAYPAL_LIVE_CLIENT_ID=your_live_client_id
PAYPAL_LIVE_CLIENT_SECRET=your_live_client_secret
PAYPAL_WEBHOOK_SECRET=your_webhook_id  # Optional but recommended
```

## Configuration

The PayPal gateway is pre-configured in the `config/payments.php` file:

```php
'gateways' => [
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
        'webhook_secret' => env('PAYPAL_WEBHOOK_SECRET'),
    ],
],
```

## Basic Usage

### 1. Creating a Payment

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

// Set metadata
$paymentRequest->setMetadata([
    'order_id' => 12345,
    'customer_id' => 67890,
    'items' => [
        [
            'name' => 'Product Name',
            'quantity' => 1,
            'unit_amount' => [
                'currency_code' => 'USD',
                'value' => '100.00'
            ]
        ]
    ]
]);

// Process payment with PayPal
$response = Payment::gateway('paypal')->pay($paymentRequest);

// Check if payment requires redirect
if ($response->isRedirect()) {
    // Redirect user to PayPal
    return redirect($response->getRedirectUrl());
}
```

### 2. Capturing Payment After Approval

When the user returns from PayPal after approval, you need to capture the payment:

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

// Get the order ID from the request
$orderId = $request->query('token') ?? $request->query('order_id');

// Capture the payment
try {
    $response = Payment::gateway('paypal')->capturePayment($orderId);

    if ($response->isSuccess()) {
        // Payment was successful
        $transactionId = $response->getTransactionId();
        $amount = $response->getData()['amount'];

        // Update your database with payment details
        // ...

        return redirect()->route('payment.success')->with([
            'transaction_id' => $transactionId,
            'amount' => $amount
        ]);
    } else {
        // Payment failed
        return redirect()->route('payment.error')->with([
            'message' => 'Payment capture failed'
        ]);
    }
} catch (\Exception $e) {
    // Handle exceptions
    return redirect()->route('payment.error')->with([
        'message' => $e->getMessage()
    ]);
}
```

### 3. Processing Refunds

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

try {
    $success = Payment::gateway('paypal')->refund($transactionId, $refundAmount);

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

## Payment Flow

### Complete Payment Flow Example

1. **Create Route**:

```php
// routes/web.php
use App\Http\Controllers\PaymentController;

Route::get('/payment/create', [PaymentController::class, 'create']);
Route::post('/payment/process', [PaymentController::class, 'process']);
Route::get('/payment/success', [PaymentController::class, 'success']);
Route::get('/payment/cancel', [PaymentController::class, 'cancel']);
Route::get('/payment/webhook/paypal', [PaymentController::class, 'paypalWebhook']);
```

2. **Create Controller**:

```php
// app/Http/Controllers/PaymentController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

class PaymentController extends Controller
{
    public function create()
    {
        return view('payment.create');
    }

    public function process(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'required|string'
        ]);

        $paymentRequest = new PaymentRequest(
            amount: $request->amount,
            currency: 'USD',
            description: $request->description
        );

        $paymentRequest->setTransactionId('order_' . uniqid());
        $paymentRequest->setReturnUrl(route('payment.success'));
        $paymentRequest->setCancelUrl(route('payment.cancel'));

        $response = Payment::gateway('paypal')->pay($paymentRequest);

        if ($response->isRedirect()) {
            return redirect($response->getRedirectUrl());
        }

        return back()->with('error', 'Payment initialization failed');
    }

    public function success(Request $request)
    {
        $orderId = $request->query('token');

        if (!$orderId) {
            return redirect('/')->with('error', 'Invalid payment response');
        }

        try {
            $response = Payment::gateway('paypal')->capturePayment($orderId);

            if ($response->isSuccess()) {
                return view('payment.success', [
                    'transactionId' => $response->getTransactionId(),
                    'amount' => $response->getData()['amount']
                ]);
            }

            return redirect('/')->with('error', 'Payment capture failed');
        } catch (\Exception $e) {
            return redirect('/')->with('error', $e->getMessage());
        }
    }

    public function cancel()
    {
        return view('payment.cancel');
    }
}
```

3. **Create Views**:

```php
<!-- resources/views/payment/create.blade.php -->
<form action="{{ route('payment.process') }}" method="POST">
    @csrf
    <div>
        <label>Amount</label>
        <input type="number" name="amount" step="0.01" required>
    </div>
    <div>
        <label>Description</label>
        <input type="text" name="description" required>
    </div>
    <button type="submit">Pay with PayPal</button>
</form>
```

```php
<!-- resources/views/payment/success.blade.php -->
<h1>Payment Successful!</h1>
<p>Transaction ID: {{ $transactionId }}</p>
<p>Amount: ${{ $amount }}</p>
```

## Webhook Setup

### 1. Configure Webhook URL

Add the webhook route:

```php
// routes/web.php or routes/api.php
Route::post('/payment/webhook/paypal', [PaymentController::class, 'paypalWebhook'])
    ->middleware(['api', 'throttle:60,1']);
```

### 2. Create Webhook Handler

```php
public function paypalWebhook(Request $request)
{
    $payload = $request->json()->all();

    try {
        $response = Payment::gateway('paypal')->verify($payload);

        if ($response->isSuccess()) {
            // Payment was successful
            $transactionId = $response->getTransactionId();
            $orderId = $response->getData()['order_id'];
            $eventType = $response->getData()['event_type'];

            // Handle different event types
            switch ($eventType) {
                case 'CHECKOUT.ORDER.APPROVED':
                    // Order was approved by customer
                    // You might want to trigger the capture here
                    break;

                case 'PAYMENT.CAPTURE.COMPLETED':
                    // Payment was captured successfully
                    // Update order status to paid
                    break;

                case 'PAYMENT.CAPTURE.DENIED':
                    // Payment was denied
                    // Update order status to failed
                    break;
            }

            // Save webhook data to your database for reference
            // ...
        }

        return response()->json(['status' => 'success']);
    } catch (\Exception $e) {
        // Log error
        \Log::error('PayPal webhook error: ' . $e->getMessage());

        return response()->json(['status' => 'error'], 400);
    }
}
```

### 3. Set Up Webhook in PayPal Dashboard

1. Log in to your PayPal Developer Dashboard
2. Go to Applications -> Your Application -> Webhooks
3. Add a new webhook with the URL: `https://your-domain.com/payment/webhook/paypal`
4. Select the events you want to receive:
   - Checkout order approved
   - Payment capture completed
   - Payment capture denied
   - Payment sale completed
   - Payment sale denied

## Error Handling

The package provides comprehensive error handling:

```php
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use Mdiqbal\LaravelPayments\Exceptions\GatewayNotFoundException;

try {
    $response = Payment::gateway('paypal')->pay($paymentRequest);
} catch (GatewayNotFoundException $e) {
    // Gateway not found
    Log::error('PayPal gateway not configured');
} catch (PaymentException $e) {
    // Payment processing error
    Log::error('PayPal payment error: ' . $e->getMessage());
} catch (\Exception $e) {
    // Other exceptions
    Log::error('Unexpected error: ' . $e->getMessage());
}
```

## Advanced Features

### 1. Custom Payment Items

```php
$paymentRequest->setMetadata([
    'items' => [
        [
            'name' => 'Product 1',
            'description' => 'Product Description',
            'quantity' => 2,
            'unit_amount' => [
                'currency_code' => 'USD',
                'value' => '50.00'
            ]
        ],
        [
            'name' => 'Product 2',
            'quantity' => 1,
            'unit_amount' => [
                'currency_code' => 'USD',
                'value' => '25.00'
            ]
        ]
    ]
]);
```

### 2. Custom Branding

```php
$paymentRequest->setMetadata([
    'brand_name' => 'Your Company Name',
    'logo_url' => 'https://your-domain.com/logo.png'
]);
```

### 3. Shipping Information

If you need to collect shipping information:

```php
$paymentRequest->setMetadata([
    'shipping_preference' => 'GET_FROM_FILE'  // or 'NO_SHIPPING' or 'SET_PROVIDED_ADDRESS'
]);
```

### 4. Dynamic Currency Support

The gateway supports multiple currencies. Just set the currency in your payment request:

```php
$paymentRequest = new PaymentRequest(
    amount: 100.00,
    currency: 'EUR',  // or GBP, CAD, AUD, etc.
    description: 'Payment description'
);
```

## Testing

### Sandbox Testing

1. Use your PayPal sandbox credentials in your `.env` file
2. Create test accounts in PayPal Sandbox
3. Test the complete payment flow

### Test Example

```php
// In your test
public function test_paypal_payment()
{
    $paymentRequest = new PaymentRequest(
        amount: 10.00,
        currency: 'USD',
        description: 'Test Payment'
    );

    $paymentRequest->setTransactionId('test_order_123');

    $response = Payment::gateway('paypal')->pay($paymentRequest);

    $this->assertTrue($response->isRedirect());
    $this->assertNotNull($response->getRedirectUrl());
    $this->assertEquals('created', $response->getData()['status']);
}
```

## Support

For issues and questions:

1. Check the [GitHub Issues](https://github.com/your-username/laravel-payments/issues)
2. Review the [PayPal API Documentation](https://developer.paypal.com/docs/api/)
3. Refer to the package documentation

## Security Notes

1. Never commit your PayPal credentials to version control
2. Always use HTTPS for your webhook URLs
3. Verify webhook signatures to ensure requests are from PayPal
4. Implement proper error handling to prevent exposing sensitive information
5. Use PayPal's sandbox for testing before going live