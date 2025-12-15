# Paystack Gateway Integration Guide

This guide will help you integrate Paystack payment gateway into your Laravel application using the laravel-payments package.

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

3. Add your Paystack credentials to your `.env` file:

```env
# Paystack Configuration
PAYSTACK_MODE=test  # or 'live' for production
PAYSTACK_SANDBOX_SECRET=
PAYSTACK_LIVE_SECRET=
```

## Configuration

The Paystack gateway is pre-configured in the `config/payments.php` file:

```php
'gateways' => [
    'paystack' => [
        'mode' => env('PAYSTACK_MODE', 'sandbox'),
        'sandbox' => [
            'secret_key' => env('PAYSTACK_SANDBOX_SECRET'),
        ],
        'live' => [
            'secret_key' => env('PAYSTACK_LIVE_SECRET'),
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
    amount: 5000.00,
    currency: 'NGN',
    description: 'Payment for Order #12345'
);

// Set optional parameters
$paymentRequest->setTransactionId('order_' . uniqid());

// Set metadata for additional options
$paymentRequest->setMetadata([
    'email' => 'customer@example.com',
    'channels' => ['card', 'bank', 'ussd'],
    'callback_url' => route('payment.callback'),
    'metadata' => [
        'order_id' => 12345,
        'customer_name' => 'John Doe'
    ]
]);

// Process payment with Paystack
$response = Payment::gateway('paystack')->pay($paymentRequest);

// Get transaction details
$reference = $response->getData()['reference'];
$authorizationUrl = $response->getData()['authorization_url'];
```

### 2. Frontend Integration with Paystack Inline

Create a payment form:

```html
<!-- payment-form.blade.php -->
<button id="paystack-button" class="btn btn-primary">Pay with Paystack</button>

<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
    const handler = PaystackPop.setup({
        key: 'pk_test_xxxxxxxxxxxxxxxxxxxxxxxx', // Your Paystack public key
        email: 'customer@example.com',
        amount: 500000,  // Amount in kobo (5000.00 NGN)
        currency: 'NGN',
        ref: '{{ $reference }}',  // Reference from payment response
        callback: function(response) {
            // Send payment verification request to your server
            fetch('{{ route("payment.verify") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    reference: response.reference
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
        onClose: function() {
            alert('Payment window closed');
        }
    });

    document.getElementById('paystack-button').onclick = function(e) {
        e.preventDefault();
        handler.openIframe();
    };
</script>
```

### 3. Verifying Payment

After successful payment, verify it on your server:

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

// Get reference from request
$reference = $request->reference;

try {
    $response = Payment::gateway('paystack')->verify(['reference' => $reference]);

    if ($response->isSuccess()) {
        $transactionId = $response->getTransactionId();
        $amount = $response->getData()['amount'];
        $channel = $response->getData()['channel'];
        $customer = $response->getData()['customer'];

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
    $success = Payment::gateway('paystack')->refund($reference, $refundAmount);

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

### Using Paystack Payment Page

For a quick setup with Paystack's hosted payment page:

```php
$paymentRequest = new PaymentRequest(
    amount: 5000.00,
    currency: 'NGN',
    description: 'Product Purchase'
);

$paymentRequest->setMetadata([
    'email' => 'customer@example.com',
    'page_name' => 'Product Purchase',
    'channels' => ['card', 'bank', 'ussd', 'qr'],
    'split_code' => 'SPL_xxxxxxxxxxxxxx',  // For split payments
    'logo' => 'https://your-domain.com/logo.png'
]);

$paymentRequest->setReturnUrl(route('payment.success'));

$response = Payment::gateway('paystack')->createPaymentPage($paymentRequest);

if ($response->isRedirect()) {
    return redirect($response->getRedirectUrl());
}
```

### Saving Customer Information

```php
// Create a customer
$customerId = Payment::gateway('paystack')->createCustomer([
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => 'john@example.com',
    'phone' => '+2348012345678',
    'metadata' => [
        'user_id' => 123,
        'source' => 'website'
    ]
]);

// Use customer code in payment requests
$paymentRequest->setMetadata([
    'email' => 'john@example.com',
    'customer_code' => $customerId
]);
```

### Subscriptions

Create recurring payment subscriptions:

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

// Create a plan first
$planId = Payment::gateway('paystack')->createPlan([
    'name' => 'Premium Plan',
    'interval' => 'monthly',
    'amount' => 500000,  // Amount in kobo
    'currency' => 'NGN',
    'description' => 'Monthly subscription for premium features'
]);

// Create subscription
$subscriptionData = [
    'customer' => $customerCode,
    'plan' => $planId,
    'authorization' => $authCode,  // From previous transaction
    'start_date' => now()->addDays(30)->format('Y-m-d'),
];

$response = Payment::gateway('paystack')->createSubscription($subscriptionData);
```

### Recurring Payments

Charge saved authorization for recurring payments:

```php
$chargeData = [
    'authorization_code' => $authCode,  // From previous successful transaction
    'email' => 'customer@example.com',
    'amount' => 100000,  // Amount in kobo
    'reference' => 'recurring_' . uniqid()
];

$response = Payment::gateway('paystack')->chargeAuthorization($chargeData);
```

## Complete Payment Flow Example

### Controller Implementation

```php
// app/Http/Controllers/PaystackPaymentController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

class PaystackPaymentController extends Controller
{
    public function create()
    {
        return view('paystack.create');
    }

    public function process(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100',
            'email' => 'required|email',
            'description' => 'required|string'
        ]);

        $paymentRequest = new PaymentRequest(
            amount: $request->amount,
            currency: 'NGN',
            description: $request->description
        );

        $paymentRequest->setTransactionId('order_' . uniqid());
        $paymentRequest->setMetadata([
            'email' => $request->email,
            'channels' => $request->channels ?? ['card', 'bank'],
            'callback_url' => route('payment.callback'),
            'metadata' => [
                'user_id' => auth()->id(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]
        ]);

        $response = Payment::gateway('paystack')->pay($paymentRequest);

        return response()->json([
            'success' => true,
            'reference' => $response->getData()['reference'],
            'authorization_url' => $response->getData()['authorization_url']
        ]);
    }

    public function verify(Request $request)
    {
        $payload = [
            'reference' => $request->reference
        ];

        try {
            $response = Payment::gateway('paystack')->verify($payload);

            if ($response->isSuccess()) {
                // Update your database
                Order::where('transaction_id', $response->getTransactionId())
                    ->update([
                        'status' => 'paid',
                        'payment_reference' => $response->getData()['reference'],
                        'payment_channel' => $response->getData()['channel'],
                        'paid_at' => $response->getData()['paid_at'],
                        'customer_email' => $response->getData()['customer']['email'],
                        'authorization_code' => $response->getData()['authorization']['authorization_code']
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
            $response = Payment::gateway('paystack')->processWebhook($payload);

            if ($response->isSuccess()) {
                $eventType = $response->getData()['event_type'];
                $transactionId = $response->getTransactionId();

                // Handle different event types
                switch ($eventType) {
                    case 'charge.success':
                        // Payment successful
                        break;
                    case 'charge.failed':
                        // Payment failed
                        break;
                    case 'transfer.success':
                        // Transfer successful
                        break;
                    case 'subscription.disable':
                        // Subscription canceled
                        break;
                }
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            \Log::error('Paystack webhook error: ' . $e->getMessage());
            return response()->json(['status' => 'error'], 400);
        }
    }
}
```

### Routes Configuration

```php
// routes/web.php
use App\Http\Controllers\PaystackPaymentController;

Route::get('/payment/paystack', [PaystackPaymentController::class, 'create']);
Route::post('/payment/paystack/process', [PaystackPaymentController::class, 'process']);
Route::post('/payment/paystack/verify', [PaystackPaymentController::class, 'verify'])->name('payment.verify');

// Webhook endpoint
Route::post('/payment/webhook/paystack', [PaystackPaymentController::class, 'webhook'])
    ->middleware(['api', 'throttle:60,1']);
```

## Webhook Setup

### 1. Configure Webhook Endpoint

The webhook endpoint is automatically created by the package. Add the route:

```php
// routes/api.php
Route::post('/payment/webhook/paystack', [WebhookController::class, 'paystack'])
    ->middleware(['api', 'throttle:60,1']);
```

### 2. Set Up Webhook in Paystack Dashboard

1. Log in to your Paystack Dashboard
2. Go to Settings → Webhooks
3. Add a new webhook with the URL: `https://your-domain.com/payment/webhook/paystack`
4. Select events to listen for:
   - Charge Success
   - Charge Failure
   - Transfer Success
   - Transfer Failure
   - Subscription Create
   - Subscription Disable

### 3. Webhook Handler

```php
// app/Http/Controllers/WebhookController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class WebhookController extends Controller
{
    public function paystack(Request $request)
    {
        $payload = $request->json()->all();

        // Verify webhook signature (Paystack sends x-paystack-signature)
        $signature = $request->header('x-paystack-signature');
        $computedSignature = hash_hmac('sha512', $request->getContent(), config('payments.gateways.paystack.sandbox.secret_key'));

        if (!hash_equals($signature, $computedSignature)) {
            return response()->json(['status' => 'error'], 401);
        }

        try {
            $response = Payment::gateway('paystack')->processWebhook($payload);

            if ($response->isSuccess()) {
                $eventType = $response->getData()['event_type'];
                $transactionId = $response->getTransactionId();

                // Find the related order
                $order = Order::where('transaction_id', $transactionId)
                    ->orWhere('payment_reference', $transactionId)
                    ->first();

                if ($order) {
                    switch ($eventType) {
                        case 'charge.success':
                            $order->status = 'paid';
                            $order->paid_at = now();
                            $order->save();
                            break;

                        case 'charge.failed':
                            $order->status = 'failed';
                            $order->save();
                            break;

                        case 'subscription.disable':
                            if ($order->subscription) {
                                $order->subscription->status = 'canceled';
                                $order->subscription->save();
                            }
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
use Paystack\Exception\ApiException;

try {
    $response = Payment::gateway('paystack')->pay($paymentRequest);
} catch (ApiException $e) {
    // Paystack API errors
    $message = $this->getPaystackErrorMessage($e->getMessage());
} catch (PaymentException $e) {
    // General payment error
    Log::error('Payment error: ' . $e->getMessage());
} catch (\Exception $e) {
    // Other exceptions
    Log::error('Unexpected error: ' . $e->getMessage());
}

private function getPaystackErrorMessage($error): string
{
    $messages = [
        'Invalid amount' => 'Amount must be at least 100 NGN',
        'Invalid currency' => 'Only NGN currency is supported',
        'Invalid email' => 'Please provide a valid email address',
        'Transaction reference already exists' => 'Please use a different reference',
        'Insufficient funds' => 'Insufficient funds in account',
        'Transaction expired' => 'Transaction has expired',
    ];

    foreach ($messages as $pattern => $message) {
        if (str_contains($error, $pattern)) {
            return $message;
        }
    }

    return 'Payment processing error';
}
```

## Advanced Features

### 1. Split Payments

Configure payment splits with subaccounts:

```php
$paymentRequest->setMetadata([
    'subaccount' => 'ACCT_xxxxxxxxxxxxxx',
    'bearer' => 'subaccount',  // Who bears the fee
    'transaction_charge' => 2000,  // Fixed charge in kobo
    'split_code' => 'SPL_xxxxxxxxxxxxxx'  // Predefined split
]);
```

### 2. Multiple Payment Channels

Configure specific payment channels:

```php
$paymentRequest->setMetadata([
    'channels' => ['card', 'bank', 'ussd', 'qr', 'mobile_money']
]);
```

### 3. Bank Transfers

For bank transfer payments:

```javascript
const handler = PaystackPop.setup({
    key: 'pk_test_xxxxxxxxxxxxxxxxxxxxxxxx',
    email: 'customer@example.com',
    amount: 500000,
    currency: 'NGN',
    ref: '{{ $reference }}',
    channels: ['bank_transfer'],
    callback: function(response) {
        console.log(response);
    }
});
```

### 4. USSD Payments

Configure USSD payments:

```php
$paymentRequest->setMetadata([
    'channels' => ['ussd'],
    'ussd' => [
        'type' => '737',
        'phone' => '08012345678'
    ]
]);
```

### 5. QR Code Payments

Generate QR code for payments:

```php
$paymentRequest->setMetadata([
    'channels' => ['qr'],
    'qr' => [
        'amount' => $amountInKobo
    ]
]);
```

### 6. Bulk Charges

Process multiple charges at once:

```php
$charges = [
    [
        'amount' => 100000,
        'email' => 'customer1@example.com',
        'reference' => 'bulk_1_' . uniqid()
    ],
    [
        'amount' => 200000,
        'email' => 'customer2@example.com',
        'reference' => 'bulk_2_' . uniqid()
    ]
];

$response = Payment::gateway('paystack')->createBulkCharge($charges);
```

### 7. Payment Timeline

Track payment history:

```php
$timeline = Payment::gateway('paystack')->getTransactionTimeline($reference);
$events = $timeline->getData()['timeline'];
```

### 8. Card BIN Resolution

Resolve card BIN for better validation:

```php
$cardInfo = Payment::gateway('paystack')->resolveCardBin('412345');
// Returns: ['bin' => '412345', 'brand' => 'Visa', 'type' => 'DEBIT', ...]
```

### 9. List Banks

Get list of supported banks:

```php
$banks = Payment::gateway('paystack')->listBanks();
// Returns array of banks with name and code
```

## Testing

### Using Test Cards

Paystack provides test cards for testing:

| Card Type | Card Number | CVV | Expiry | Pin |
|-----------|-------------|-----|--------|-----|
| Visa Success | 4084084084084081 | 123 | 12/25 | 1234 |
| Mastercard Success | 5060666666666666 | 123 | 12/25 | 1234 |
| Verve Success | 6500000000000000 | 123 | 12/25 | 1234 |
| Insufficient Funds | 4084084084084081 | 123 | 12/25 | 1234 |
| Invalid PIN | 4084084084084081 | 123 | 12/25 | 0000 |

### Test Banks

For bank transfer tests:
- `057` - Zenith Bank
- `058` - GTBank
- `011` - First Bank
- `033` - UBA

### Test Example

```php
public function test_paystack_payment()
{
    // Set test mode
    config(['payments.gateways.paystack.mode' => 'sandbox']);
    config(['payments.gateways.paystack.sandbox.secret_key' => '']);

    $paymentRequest = new PaymentRequest(
        amount: 5000.00,
        currency: 'NGN',
        description: 'Test Payment'
    );

    $paymentRequest->setTransactionId('test_order_123');
    $paymentRequest->setMetadata(['email' => 'test@example.com']);

    $response = Payment::gateway('paystack')->pay($paymentRequest);

    $this->assertTrue($response->isRedirect());
    $this->assertNotNull($response->getData()['reference']);
    $this->assertNotNull($response->getRedirectUrl());
}
```

## Support

For issues and questions:

1. Check the [GitHub Issues](https://github.com/your-username/laravel-payments/issues)
2. Review the [Paystack API Documentation](https://paystack.com/docs/api/)
3. Refer to the package documentation

## Security Notes

1. Never commit your Paystack keys to version control
2. Always use HTTPS for your webhook URLs
3. Verify webhook signatures using the `x-paystack-signature` header
4. Implement proper error handling to prevent exposing sensitive information
5. Use test mode for development and testing
6. Monitor your Paystack Dashboard for suspicious transactions

## Performance Optimization

1. Cache customer details to reduce API calls
2. Use appropriate error handling and retry logic
3. Monitor your Paystack Dashboard for performance metrics
4. Implement rate limiting for payment endpoints

```php
// Example of caching customer
$customerKey = "paystack_customer_{$email}";
$customerCode = Cache::remember($customerKey, 3600, function () use ($email) {
    return Payment::gateway('paystack')->createCustomer([
        'email' => $email
    ]);
});
```

## Best Practices

1. Always verify payment status after callback
2. Use webhooks for reliable payment status updates
3. Store transaction references for tracking
4. Set appropriate currency (Paystack primarily supports NGN)
5. Use descriptive metadata for better tracking
6. Implement proper logging for debugging

## Multi-Currency Support

While Paystack primarily supports NGN, they offer limited multi-currency support:

```php
// Supported currencies
$supportedCurrencies = ['NGN', 'GHS', 'USD'];

// For international payments
$paymentRequest = new PaymentRequest(
    amount: 100.00,
    currency: 'GHS',  // Ghana Cedis
    description: 'International Payment'
);
```

## Payment Channel Configuration

Configure specific payment channels based on your needs:

```php
// For card payments only
$paymentRequest->setMetadata(['channels' => ['card']]);

// For mobile money (Ghana)
$paymentRequest->setMetadata(['channels' => ['mobile_money']]);

// For QR payments
$paymentRequest->setMetadata(['channels' => ['qr']]);

// For multiple channels
$paymentRequest->setMetadata([
    'channels' => ['card', 'bank', 'ussd', 'qr']
]);
```