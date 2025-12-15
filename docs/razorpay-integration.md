# Razorpay Gateway Integration Guide

This guide will help you integrate Razorpay payment gateway into your Laravel application using the laravel-payments package.

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

3. Add your Razorpay credentials to your `.env` file:

```env
# Razorpay Configuration
RAZORPAY_MODE=test  # or 'live' for production
RAZORPAY_SANDBOX_KEY_ID=rzp_test_xxxxxxxxxxxxxxxx
RAZORPAY_SANDBOX_KEY_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxx
RAZORPAY_LIVE_KEY_ID=rzp_live_xxxxxxxxxxxxxxxx
RAZORPAY_LIVE_KEY_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxx
```

## Configuration

The Razorpay gateway is pre-configured in the `config/payments.php` file:

```php
'gateways' => [
    'razorpay' => [
        'mode' => env('RAZORPAY_MODE', 'sandbox'),
        'sandbox' => [
            'key_id' => env('RAZORPAY_SANDBOX_KEY_ID'),
            'key_secret' => env('RAZORPAY_SANDBOX_KEY_SECRET'),
        ],
        'live' => [
            'key_id' => env('RAZORPAY_LIVE_KEY_ID'),
            'key_secret' => env('RAZORPAY_LIVE_KEY_SECRET'),
        ],
    ],
],
```

## Basic Usage

### 1. Creating an Order

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
    'contact' => '+919876543210',
    'notes' => [
        'order_id' => 12345,
        'customer_name' => 'John Doe'
    ]
]);

// Process payment with Razorpay
$response = Payment::gateway('razorpay')->pay($paymentRequest);

// Get order details
$orderId = $response->getData()['razorpay_order_id'];
$amount = $response->getData()['amount'];
$keyId = $response->getData()['key_id'];
```

### 2. Frontend Integration with Razorpay Checkout

Create a payment form:

```html
<!-- payment-form.blade.php -->
<button id="rzp-button" class="btn btn-primary">Pay with Razorpay</button>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    const options = {
        'key': '{{ $keyId }}',  // From payment response
        'amount': '{{ $amount * 100 }}',  // Amount in paise
        'currency': 'INR',
        'name': 'Your Company Name',
        'description': 'Payment for Order #{{ $orderId }}',
        'image': 'https://your-domain.com/logo.png',
        'order_id': '{{ $orderId }}',
        'handler': function (response) {
            // Send payment verification request to your server
            fetch('{{ route("payment.verify") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    razorpay_order_id: response.razorpay_order_id,
                    razorpay_payment_id: response.razorpay_payment_id,
                    razorpay_signature: response.razorpay_signature
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '{{ route("payment.success") }}';
                } else {
                    alert('Payment verification failed: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Payment verification failed');
            });
        },
        'prefill': {
            'name': 'John Doe',
            'email': 'customer@example.com',
            'contact': '919876543210'
        },
        'notes': {
            'address': 'Your Address'
        },
        'theme': {
            'color': '#3399cc'
        }
    };

    const rzp = new Razorpay(options);

    document.getElementById('rzp-button').onclick = function (e) {
        e.preventDefault();
        rzp.open();
    };
</script>
```

### 3. Verifying Payment

After successful payment, verify it on your server:

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

// Get payment details from request
$payload = [
    'razorpay_order_id' => $request->razorpay_order_id,
    'razorpay_payment_id' => $request->razorpay_payment_id,
    'razorpay_signature' => $request->razorpay_signature
];

try {
    $response = Payment::gateway('razorpay')->verify($payload);

    if ($response->isSuccess()) {
        $transactionId = $response->getTransactionId();
        $amount = $response->getData()['amount'];
        $method = $response->getData()['method'];
        $email = $response->getData()['email'];

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
    $success = Payment::gateway('razorpay')->refund($paymentId, $refundAmount);

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

## Payment Methods

### Using Payment Links

For sharing payment links via email, SMS, or WhatsApp:

```php
$paymentRequest = new PaymentRequest(
    amount: 500.00,
    currency: 'INR',
    description: 'Invoice Payment'
);

$paymentRequest->setMetadata([
    'email' => 'customer@example.com',
    'contact' => '+919876543210',
    'customer_name' => 'John Doe',
    'notify_email' => true,
    'notify_sms' => true,
    'callback_url' => route('payment.callback'),
    'callback_method' => 'post',
    'notes' => [
        'invoice_number' => 'INV-2024-001'
    ]
]);

$response = Payment::gateway('razorpay')->createPaymentLink($paymentRequest);

if ($response->isRedirect()) {
    $paymentLink = $response->getRedirectUrl();
    // Share this link with customer
}
```

### Subscriptions

Create recurring payment subscriptions:

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

// Create a plan first
$planId = Payment::gateway('razorpay')->createPlan([
    'period' => 'monthly',
    'interval' => 1,
    'item' => [
        'name' => 'Premium Plan',
        'description' => 'Monthly subscription for premium features',
        'amount' => 99900,  // Amount in paise
        'currency' => 'INR'
    ]
]);

// Create subscription
$subscriptionData = [
    'plan_id' => $planId,
    'total_count' => 12,  // 12 months
    'customer_notify' => 1,
    'notes' => [
        'user_id' => 123
    ]
];

$response = Payment::gateway('razorpay')->createSubscription($subscriptionData);
```

### Saving Customer Information

```php
// Create a customer
$customerId = Payment::gateway('razorpay')->createCustomer([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'contact' => '919876543210',
    'notes' => [
        'user_id' => 123,
        'type' => 'premium'
    ]
]);

// Use customer ID in payment requests
$paymentRequest->setMetadata(['customer_id' => $customerId]);
```

## Complete Payment Flow Example

### Controller Implementation

```php
// app/Http/Controllers/RazorpayPaymentController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

class RazorpayPaymentController extends Controller
{
    public function create()
    {
        return view('razorpay.create');
    }

    public function process(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'required|string'
        ]);

        $paymentRequest = new PaymentRequest(
            amount: $request->amount,
            currency: 'INR',
            description: $request->description
        );

        $paymentRequest->setTransactionId('order_' . uniqid());
        $paymentRequest->setMetadata([
            'email' => auth()->user()->email ?? null,
            'contact' => auth()->user()->phone ?? null,
            'notes' => [
                'user_id' => auth()->id(),
                'ip_address' => $request->ip()
            ]
        ]);

        $response = Payment::gateway('razorpay')->pay($paymentRequest);

        return response()->json([
            'success' => true,
            'order_id' => $response->getData()['razorpay_order_id'],
            'amount' => $response->getData()['amount'],
            'key_id' => $response->getData()['key_id']
        ]);
    }

    public function verify(Request $request)
    {
        $payload = [
            'razorpay_order_id' => $request->razorpay_order_id,
            'razorpay_payment_id' => $request->razorpay_payment_id,
            'razorpay_signature' => $request->razorpay_signature
        ];

        try {
            $response = Payment::gateway('razorpay')->verify($payload);

            if ($response->isSuccess()) {
                // Update your database
                Order::where('transaction_id', $response->getData()['order_id'])
                    ->update([
                        'status' => 'paid',
                        'payment_id' => $response->getTransactionId(),
                        'payment_method' => $response->getData()['method'],
                        'paid_at' => now()
                    ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Payment verified successfully'
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function webhook(Request $request)
    {
        $payload = $request->json()->all();

        try {
            $response = Payment::gateway('razorpay')->processWebhook($payload);

            if ($response->isSuccess()) {
                $eventType = $response->getData()['event_type'];
                $transactionId = $response->getTransactionId();

                // Handle different event types
                switch ($eventType) {
                    case 'payment.captured':
                        // Payment successful
                        break;
                    case 'payment.failed':
                        // Payment failed
                        break;
                    case 'refund.processed':
                        // Refund processed
                        break;
                }
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            \Log::error('Razorpay webhook error: ' . $e->getMessage());
            return response()->json(['status' => 'error'], 400);
        }
    }
}
```

### Routes Configuration

```php
// routes/web.php
use App\Http\Controllers\RazorpayPaymentController;

Route::get('/payment/razorpay', [RazorpayPaymentController::class, 'create']);
Route::post('/payment/razorpay/process', [RazorpayPaymentController::class, 'process']);
Route::post('/payment/razorpay/verify', [RazorpayPaymentController::class, 'verify'])->name('payment.verify');

// Webhook endpoint
Route::post('/payment/webhook/razorpay', [RazorpayPaymentController::class, 'webhook'])
    ->middleware(['api', 'throttle:60,1']);
```

## Webhook Setup

### 1. Configure Webhook Endpoint

The webhook endpoint is automatically created by the package. Add the route:

```php
// routes/api.php
Route::post('/payment/webhook/razorpay', [WebhookController::class, 'razorpay'])
    ->middleware(['api', 'throttle:60,1']);
```

### 2. Set Up Webhook in Razorpay Dashboard

1. Log in to your Razorpay Dashboard
2. Go to Settings → Webhooks
3. Add a new webhook with the URL: `https://your-domain.com/payment/webhook/razorpay`
4. Select events to listen for:
   - Payment Authorized
   - Payment Captured
   - Payment Failed
   - Payment Refunded
   - Order Paid

### 3. Webhook Handler

```php
// app/Http/Controllers/WebhookController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class WebhookController extends Controller
{
    public function razorpay(Request $request)
    {
        $payload = $request->json()->all();

        try {
            $response = Payment::gateway('razorpay')->processWebhook($payload);

            if ($response->isSuccess()) {
                $eventType = $response->getData()['event_type'];
                $transactionId = $response->getTransactionId();

                // Find the related order
                $order = Order::where('transaction_id', $transactionId)
                    ->orWhere('payment_id', $transactionId)
                    ->first();

                if ($order) {
                    switch ($eventType) {
                        case 'payment.captured':
                            $order->status = 'paid';
                            $order->paid_at = now();
                            $order->save();
                            break;

                        case 'payment.failed':
                            $order->status = 'failed';
                            $order->save();
                            break;

                        case 'refund.processed':
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
use Razorpay\Api\Errors\BadRequestError;
use Razorpay\Api\Errors\GatewayError;
use Razorpay\Api\Errors\ServerError;

try {
    $response = Payment::gateway('razorpay')->pay($paymentRequest);
} catch (BadRequestError $e) {
    // Invalid request parameters
    $message = $this->getBadRequestMessage($e->getCode());
} catch (GatewayError $e) {
    // Payment gateway error
    Log::error('Razorpay gateway error: ' . $e->getMessage());
} catch (ServerError $e) {
    // Razorpay server error
    Log::error('Razorpay server error: ' . $e->getMessage());
} catch (PaymentException $e) {
    // General payment error
    Log::error('Payment error: ' . $e->getMessage());
} catch (\Exception $e) {
    // Other exceptions
    Log::error('Unexpected error: ' . $e->getMessage());
}

private function getBadRequestMessage($code): string
{
    $messages = [
        'BAD_REQUEST_ERROR' => 'Invalid request parameters',
        'INVALID_AMOUNT' => 'Invalid amount. Amount should be between ₹1 to ₹100000',
        'INVALID_CURRENCY' => 'Invalid currency. Only INR is supported',
        'INVALID_ORDER_ID' => 'Invalid order ID',
        'INVALID_PAYMENT_ID' => 'Invalid payment ID',
        'REFUND_ALREADY_PROCESSED' => 'Refund has already been processed',
        'PAYMENT_NOT_AUTHORIZED' => 'Payment has not been authorized',
    ];

    return $messages[$code] ?? 'Payment processing error';
}
```

## Advanced Features

### 1. Custom Checkout Integration

For custom checkout experiences:

```javascript
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    const razorpay = new Razorpay({
        key: 'rzp_test_xxxxxxxxxxxxxxxx',
        image: 'https://your-domain.com/logo.png',
        prefill: {
            'name': 'John Doe',
            'email': 'john@example.com',
            'contact': '919876543210'
        },
        notes: {
            'address': 'Your Address',
            'merchant_order_id': 'order_123'
        },
        theme: {
            'color': '#3399cc'
        },
        modal: {
            'ondismiss': function() {
                console.log('Checkout form closed');
            },
            'escape': true,
            'handleback': true,
            'confirm_close': true,
            'persist': ['name', 'email', 'contact']
        }
    });
</script>
```

### 2. Multiple Payment Methods

Configure multiple payment methods:

```php
$paymentRequest->setMetadata([
    'notes' => [
        'allowed_methods' => json_encode([
            'card',
            'netbanking',
            'wallet',
            'upi'
        ]),
        'preferred_method' => 'upi'
    ]
]);
```

### 3. Net Banking Integration

```php
// For net banking payments, include bank details
$paymentRequest->setMetadata([
    'notes' => [
        'bank_code' => 'HDFC',  // Bank code
        'bank_name' => 'HDFC Bank'
    ]
]);
```

### 4. Wallet Integration

Support for various wallets:

```php
$paymentRequest->setMetadata([
    'notes' => [
        'wallet' => 'mobikwik'  // or 'paytm', 'freecharge', 'airtelmoney'
    ]
]);
```

### 5. UPI Integration

For UPI payments:

```php
$paymentRequest->setMetadata([
    'notes' => [
        'upi_vpa' => 'customer@upi'  // Customer's UPI ID
    ]
]);
```

### 6. EMI Options

Configure EMI payment options:

```javascript
const options = {
    // ... other options
    'handler': function (response) {
        // Handle payment
    },
    'config': {
        'display': {
            'blocks': {
                'banks': {
                    'name': 'Pay Using Banks',
                    'instruments': [
                        {
                            'name': 'EMI',
                            'periods': [3, 6, 9, 12]
                        }
                    ]
                }
            }
        }
    }
};
```

## Testing

### Using Test Cards

Razorpay provides test cards for testing:

| Card Type | Card Number | Name | Expiry | CVV |
|-----------|-------------|------|--------|-----|
| Visa | 4111 1111 1111 1111 | Test | 12/25 | 123 |
| Mastercard | 5267 3181 8797 5449 | Test | 12/25 | 123 |
| Maestro | 5012 0000 0000 0000 | Test | 12/25 | 123 |

### Test UPI Handle

Use any valid UPI handle for testing:
- `success@razorpay`
- `failure@razorpay`

### Test Example

```php
public function test_razorpay_payment()
{
    // Set test mode
    config(['payments.gateways.razorpay.mode' => 'sandbox']);
    config(['payments.gateways.razorpay.sandbox.key_id' => 'rzp_test_...']);
    config(['payments.gateways.razorpay.sandbox.key_secret' => '...']);

    $paymentRequest = new PaymentRequest(
        amount: 500.00,
        currency: 'INR',
        description: 'Test Payment'
    );

    $paymentRequest->setTransactionId('test_order_123');

    $response = Payment::gateway('razorpay')->pay($paymentRequest);

    $this->assertTrue($response->isSuccess());
    $this->assertNotNull($response->getData()['razorpay_order_id']);
    $this->assertEquals(500, $response->getData()['amount']);
}
```

## Support

For issues and questions:

1. Check the [GitHub Issues](https://github.com/your-username/laravel-payments/issues)
2. Review the [Razorpay API Documentation](https://razorpay.com/docs/api/)
3. Refer to the package documentation

## Security Notes

1. Never commit your Razorpay keys to version control
2. Always use HTTPS for your webhook URLs
3. Verify payment signatures to prevent fraud
4. Implement proper error handling to prevent exposing sensitive information
5. Use test mode for development and testing
6. Monitor your Razorpay Dashboard for suspicious transactions

## Performance Optimization

1. Cache customer details to reduce API calls
2. Use batch operations for bulk payments
3. Implement proper error handling and retry logic
4. Monitor your Razorpay Dashboard for performance metrics

```php
// Example of caching customer
$customerKey = "razorpay_customer_{$email}";
$customerId = Cache::remember($customerKey, 3600, function () use ($email) {
    return Payment::gateway('razorpay')->createCustomer([
        'email' => $email
    ]);
});
```

## Best Practices

1. Always verify payment signatures
2. Use webhooks for reliable payment status updates
3. Implement idempotency to prevent duplicate payments
4. Store transaction IDs for reference
5. Set appropriate currency (Razorpay primarily supports INR)
6. Use descriptive notes for better tracking
7. Implement proper logging for debugging

## Multi-Currency Support

While Razorpay primarily supports INR, they offer limited multi-currency support:

```php
// Supported currencies
$supportedCurrencies = ['INR', 'USD'];

// For international payments
$paymentRequest = new PaymentRequest(
    amount: 100.00,
    currency: 'USD',  // Note: Additional setup required
    description: 'International Payment'
);
```