# Stripe Gateway Integration Guide

This guide will help you integrate Stripe payment gateway into your Laravel application using the laravel-payments package.

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

3. Add your Stripe credentials to your `.env` file:

```env
# Stripe Configuration
STRIPE_MODE=test  # or 'live' for production
STRIPE_SANDBOX_SECRET=
STRIPE_SANDBOX_KEY=
STRIPE_LIVE_SECRET=
STRIPE_LIVE_KEY=
STRIPE_WEBHOOK_SECRET=
```

## Configuration

The Stripe gateway is pre-configured in the `config/payments.php` file:

```php
'gateways' => [
    'stripe' => [
        'mode' => env('STRIPE_MODE', 'sandbox'),
        'sandbox' => [
            'secret_key' => env('STRIPE_SANDBOX_SECRET'),
            'api_key' => env('STRIPE_SANDBOX_KEY'),
        ],
        'live' => [
            'secret_key' => env('STRIPE_LIVE_SECRET'),
            'api_key' => env('STRIPE_LIVE_KEY'),
        ],
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
],
```

## Basic Usage

### 1. Creating a PaymentIntent

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

// Set metadata for additional options
$paymentRequest->setMetadata([
    'customer_id' => 'cus_xxxxxxxxxxxxxx',
    'email' => 'customer@example.com',
    'shipping' => [
        'address' => [
            'line1' => '123 Main St',
            'city' => 'San Francisco',
            'state' => 'CA',
            'postal_code' => '94111',
            'country' => 'US',
        ],
        'name' => 'John Doe'
    ]
]);

// Process payment with Stripe
$response = Payment::gateway('stripe')->pay($paymentRequest);

// Get client secret for frontend
$clientSecret = $response->getData()['client_secret'];
$paymentIntentId = $response->getTransactionId();
```

### 2. Frontend Integration with Stripe Elements

Create a payment form with Stripe Elements:

```html
<!-- payment-form.blade.php -->
<form id="payment-form">
    <div id="payment-element">
        <!-- Stripe Element will be inserted here -->
    </div>
    <button id="submit-button">Pay Now</button>
    <div id="error-message">
        <!-- Error messages will be displayed here -->
    </div>
</form>

<script src="https://js.stripe.com/v3/"></script>
<script>
    // Initialize Stripe with your publishable key
    const stripe = Stripe('{{ config("payments.gateways.stripe." . config("payments.gateways.stripe.mode") . ".api_key") }}');

    // Get client secret from backend
    const clientSecret = '{{ $clientSecret }}';

    const elements = stripe.elements({
        clientSecret: clientSecret,
        appearance: {
            theme: 'stripe'
        }
    });

    const paymentElement = elements.create('payment');
    paymentElement.mount('#payment-element');

    // Handle form submission
    const form = document.getElementById('payment-form');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const {error} = await stripe.confirmPayment({
            elements,
            confirmParams: {
                return_url: '{{ route("payment.success") }}',
            },
        });

        if (error) {
            document.getElementById('error-message').textContent = error.message;
        }
    });
</script>
```

### 3. Retrieving Payment Status

After payment completion or failure:

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

// Get payment intent ID from request
$paymentIntentId = $request->query('payment_intent');

if ($paymentIntentId) {
    $response = Payment::gateway('stripe')->retrievePayment($paymentIntentId);

    if ($response->isSuccess()) {
        // Payment succeeded
        $transactionId = $response->getTransactionId();
        $amount = $response->getData()['amount'];

        // Update your database
        // ...

        return view('payment.success', compact('transactionId', 'amount'));
    } else {
        // Payment failed
        return view('payment.error', [
            'message' => 'Payment was not successful'
        ]);
    }
}
```

### 4. Processing Refunds

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

try {
    $success = Payment::gateway('stripe')->refund($paymentIntentId, $refundAmount);

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

### Using Stripe Checkout (Hosted Page)

For a quick setup with Stripe's hosted checkout page:

```php
$paymentRequest = new PaymentRequest(
    amount: 100.00,
    currency: 'USD',
    description: 'Product Purchase'
);

$paymentRequest->setMetadata([
    'payment_method_types' => ['card', 'klarna', 'afterpay_clearpay'],
    'product_description' => 'Premium Product',
    'customer_id' => 'cus_xxxxxxxxxxxxxx'
]);

$paymentRequest->setReturnUrl(route('payment.success'));
$paymentRequest->setCancelUrl(route('payment.cancel'));

$response = Payment::gateway('stripe')->createCheckoutSession($paymentRequest);

if ($response->isRedirect()) {
    return redirect($response->getRedirectUrl());
}
```

### Saving Payment Methods for Future Use

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

// Create a customer
$customerId = Payment::gateway('stripe')->createCustomer([
    'email' => 'customer@example.com',
    'name' => 'John Doe',
    'metadata' => [
        'user_id' => auth()->id()
    ]
]);

// Create payment method
$paymentMethodId = Payment::gateway('stripe')->createPaymentMethod([
    'type' => 'card',
    'card' => [
        'token' => $stripeToken // From Stripe Elements
    ]
]);

// Attach payment method to customer
Payment::gateway('stripe')->attachPaymentMethod($paymentMethodId, $customerId);

// Save payment method ID in your database for future charges
```

### Charging Saved Payment Methods

```php
$paymentRequest = new PaymentRequest(
    amount: 100.00,
    currency: 'USD',
    description: 'Subscription Payment'
);

$paymentRequest->setMetadata([
    'customer_id' => $customerId,
    'payment_method_id' => $savedPaymentMethodId,
    'off_session' => true // For background charges
]);

$response = Payment::gateway('stripe')->pay($paymentRequest);
```

## Payment Flow Example

### Complete Controller Implementation

```php
// app/Http/Controllers/StripePaymentController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

class StripePaymentController extends Controller
{
    public function create()
    {
        // Get publishable key for frontend
        $gateway = Payment::gateway('stripe');
        $publishableKey = $gateway->getPublishableKey();

        return view('stripe.create', compact('publishableKey'));
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
        $paymentRequest->setMetadata([
            'email' => auth()->user()->email,
            'customer_id' => auth()->user()->stripe_customer_id ?? null
        ]);

        $response = Payment::gateway('stripe')->pay($paymentRequest);

        return response()->json([
            'client_secret' => $response->getData()['client_secret'],
            'payment_intent_id' => $response->getTransactionId()
        ]);
    }

    public function success(Request $request)
    {
        $paymentIntentId = $request->query('payment_intent');

        if (!$paymentIntentId) {
            return redirect('/')->with('error', 'Invalid payment response');
        }

        $response = Payment::gateway('stripe')->retrievePayment($paymentIntentId);

        if ($response->isSuccess()) {
            return view('stripe.success', [
                'transactionId' => $response->getTransactionId(),
                'amount' => $response->getData()['amount']
            ]);
        }

        return view('stripe.error', [
            'message' => 'Payment was not successful'
        ]);
    }

    public function webhook(Request $request)
    {
        $payload = $request->json()->all();

        try {
            $response = Payment::gateway('stripe')->verify($payload);

            if ($response->isSuccess()) {
                $transactionId = $response->getTransactionId();
                $eventType = $response->getData()['event_type'];
                $amount = $response->getData()['amount'];

                // Handle different event types
                switch ($eventType) {
                    case 'payment_intent.succeeded':
                        // Update order status to paid
                        // Send confirmation email
                        // Update subscription status
                        break;

                    case 'payment_intent.payment_failed':
                        // Update order status to failed
                        // Notify customer
                        break;

                    case 'charge.dispute.created':
                        // Handle disputes
                        // Notify admin
                        break;
                }

                // Save webhook data to your database
                // ...
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            \Log::error('Stripe webhook error: ' . $e->getMessage());
            return response()->json(['status' => 'error'], 400);
        }
    }
}
```

### Routes Configuration

```php
// routes/web.php
use App\Http\Controllers\StripePaymentController;

Route::get('/payment/stripe', [StripePaymentController::class, 'create']);
Route::post('/payment/stripe/process', [StripePaymentController::class, 'process']);
Route::get('/payment/stripe/success', [StripePaymentController::class, 'success']);

// Webhook endpoint
Route::post('/payment/webhook/stripe', [StripePaymentController::class, 'webhook'])
    ->middleware(['api', 'throttle:60,1']);
```

## Webhook Setup

### 1. Configure Webhook Endpoint

The webhook endpoint is automatically created by the package. Just add the route in your `routes/api.php`:

```php
Route::post('/payment/webhook/stripe', [WebhookController::class, 'stripe'])
    ->middleware(['api', 'throttle:60,1']);
```

### 2. Set Up Webhook in Stripe Dashboard

1. Log in to your Stripe Dashboard
2. Go to Developers → Webhooks
3. Add endpoint URL: `https://your-domain.com/payment/webhook/stripe`
4. Select events to listen for:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `payment_intent.canceled`
   - `charge.succeeded`
   - `charge.failed`
   - `charge.dispute.created`

### 3. Webhook Handler Implementation

```php
// app/Http/Controllers/WebhookController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class WebhookController extends Controller
{
    public function stripe(Request $request)
    {
        $payload = $request->json()->all();

        try {
            $response = Payment::gateway('stripe')->verify($payload);

            if ($response->isSuccess()) {
                $eventType = $response->getData()['event_type'];
                $transactionId = $response->getTransactionId();

                // Find the related order/transaction in your database
                $order = Order::where('transaction_id', $transactionId)->first();

                if ($order) {
                    switch ($eventType) {
                        case 'payment_intent.succeeded':
                            $order->status = 'paid';
                            $order->paid_at = now();
                            $order->save();

                            // Trigger any post-payment actions
                            // ...
                            break;

                        case 'payment_intent.payment_failed':
                            $order->status = 'failed';
                            $order->save();

                            // Notify customer of failure
                            // ...
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
use Mdiqbal\LaravelPayments\Exceptions\GatewayNotFoundException;
use Stripe\Exception\CardException;

try {
    $response = Payment::gateway('stripe')->pay($paymentRequest);
} catch (CardException $e) {
    // Card declined or invalid
    $declineCode = $e->getDeclineCode();
    $message = $this->getCardErrorMessage($declineCode);
} catch (PaymentException $e) {
    // Payment processing error
    Log::error('Stripe payment error: ' . $e->getMessage());
} catch (GatewayNotFoundException $e) {
    // Gateway not configured
    Log::error('Stripe gateway not found');
} catch (\Exception $e) {
    // Other exceptions
    Log::error('Unexpected error: ' . $e->getMessage());
}

private function getCardErrorMessage($declineCode): string
{
    $messages = [
        'insufficient_funds' => 'Insufficient funds in your account',
        'card_not_supported' => 'This card is not supported',
        'expired_card' => 'Your card has expired',
        'incorrect_cvc' => 'Incorrect CVC code',
        'invalid_number' => 'Invalid card number',
        'invalid_expiry_month' => 'Invalid expiration month',
        'invalid_expiry_year' => 'Invalid expiration year',
    ];

    return $messages[$declineCode] ?? 'Payment declined. Please try another card.';
}
```

## Advanced Features

### 1. Connected Accounts

For platforms using Stripe Connect:

```php
$paymentRequest->setMetadata([
    'transfer_data' => [
        'destination' => 'acct_xxxxxxxxxxxxxx',  // Connected account ID
        'amount' => round($amount * 0.9 * 100)  // 90% to connected account
    ]
]);
```

### 2. Application Fees

Charge application fees on payments:

```php
$paymentRequest->setMetadata([
    'application_fee_amount' => 5.00  // $5 application fee
]);
```

### 3. Subscriptions

For recurring payments:

```php
// Create subscription programmatically
$stripe = Payment::gateway('stripe')->getStripeClient();

$subscription = $stripe->subscriptions->create([
    'customer' => 'cus_xxxxxxxxxxxxxx',
    'items' => [
        ['price' => 'price_xxxxxxxxxxxxxx']
    ],
    'payment_behavior' => 'default_incomplete',
    'expand' => ['latest_invoice.payment_intent'],
]);

// Return client secret for confirmation
return $subscription->latest_invoice->payment_intent->client_secret;
```

### 4. Dispute Management

Handle disputes programmatically:

```php
$stripe = Payment::gateway('stripe')->getStripeClient();

// Get dispute details
$dispute = $stripe->disputes->retrieve('dp_xxxxxxxxxxxxxx');

// Accept dispute
$stripe->disputes->accept('dp_xxxxxxxxxxxxxx');

// Challenge dispute with evidence
$stripe->disputes->createEvidence('dp_xxxxxxxxxxxxxx', [
    'customer_email' => 'customer@example.com',
    'receipt' => 'file_xxxxxxxxxxxxxx'
]);
```

### 5. Multi-Currency Support

Process payments in different currencies:

```php
$paymentRequest = new PaymentRequest(
    amount: 100.00,
    currency: 'EUR',  // or GBP, CAD, AUD, etc.
    description: 'European customer payment'
);

// Stripe will handle currency conversion automatically
$response = Payment::gateway('stripe')->pay($paymentRequest);
```

### 6. Radar Fraud Detection

Configure fraud detection rules:

```php
$paymentRequest->setMetadata([
    'fraud_detection' => [
        'radar_session_id' => $sessionId,
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent()
    ]
]);
```

## Testing

### Using Stripe Test Cards

Stripe provides various test cards for testing:

| Card Number | Purpose | Description |
|-------------|---------|-------------|
| 4242424242424242 | Success | Successful payment |
| 4000000000000002 | Card Declined | Generic decline |
| 4000000000009995 | Insufficient Funds | Account has insufficient funds |
| 4000000000009987 | Lost Card | Card reported as lost |
| 4000000000009979 | Stolen Card | Card reported as stolen |
| 4000000000000069 | Expired Card | Card has expired |
| 4000000000000127 | Incorrect CVC | CVC verification failed |
| 4000000000000119 | Processing Error | Payment processing error |

### Test Example

```php
public function test_stripe_payment()
{
    // Set test mode
    config(['payments.gateways.stripe.mode' => 'sandbox']);
    config(['payments.gateways.stripe.sandbox.secret_key' => 'xxxxx']);

    $paymentRequest = new PaymentRequest(
        amount: 10.00,
        currency: 'USD',
        description: 'Test Payment'
    );

    $paymentRequest->setTransactionId('test_order_123');

    $response = Payment::gateway('stripe')->pay($paymentRequest);

    $this->assertTrue($response->isSuccess());
    $this->assertNotNull($response->getData()['client_secret']);
    $this->assertNotNull($response->getTransactionId());
}
```

### Testing Webhooks

Use the Stripe CLI to test webhooks locally:

```bash
# Install Stripe CLI
stripe listen --forward-to localhost:8000/payment/webhook/stripe
```

## Support

For issues and questions:

1. Check the [GitHub Issues](https://github.com/your-username/laravel-payments/issues)
2. Review the [Stripe API Documentation](https://stripe.com/docs/api)
3. Refer to the package documentation

## Security Notes

1. Never commit your Stripe API keys to version control
2. Always use HTTPS for your webhook URLs
3. Verify webhook signatures to ensure requests are from Stripe
4. Implement proper error handling to prevent exposing sensitive information
5. Use Stripe's test mode for development and testing
6. Enable Radar for fraud detection
7. Regularly review your Stripe account for suspicious activity

## Performance Optimization

1. Enable Stripe's built-in caching for PaymentIntents
2. Use idempotency keys to prevent duplicate charges
3. Implement proper error handling and retry logic
4. Monitor your Stripe Dashboard for performance metrics
5. Use Stripe's Dashboard alerts for monitoring payment issues

```php
// Use idempotency keys
$paymentRequest->setMetadata([
    'idempotency_key' => uniqid('payment_')
]);
```