# Mercado Pago Integration Guide

This guide explains how to integrate Mercado Pago payment gateway with the Laravel Payments package.

## Overview

Mercado Pago is a leading payment platform in Latin America that supports multiple payment methods including:
- Credit/Debit Cards
- Digital Wallets (Mercado Pago Wallet)
- Bank Transfers
- Cash Payment Options (Boleto, OXXO, etc.)
- Installments and Financing

## Installation

Since Mercado Pago SDK is already included in the package dependencies, you just need to ensure you have the Laravel Payments package installed and configured.

## Configuration

Add your Mercado Pago credentials to your `.env` file:

```env
MERCADOPAGO_ACCESS_TOKEN=your_access_token_here
MERCADOPAGO_PUBLIC_KEY=your_public_key_here
MERCADOPAGO_TEST_MODE=true
MERCADOPAGO_COUNTRY=MX
MERCADOPAGO_WEBHOOK_SECRET=your_webhook_secret_here
MERCADOPAGO_RETURN_URL=https://yoursite.com/payment/success
MERCADOPAGO_CALLBACK_URL=https://yoursite.com/mercadopago/callback
```

You can obtain these credentials from your [Mercado Pago developer dashboard](https://www.mercadopago.com/developers).

### Environment-Specific Settings

For development/testing:

```env
MERCADOPAGO_TEST_MODE=true
MERCADOPAGO_ACCESS_TOKEN=TEST-1234567890-abcdef
MERCADOPAGO_PUBLIC_KEY=TEST-public-key
```

For production:

```env
MERCADOPAGO_TEST_MODE=false
MERCADOPAGO_ACCESS_TOKEN=APP_USR-1234567890-abcdef
MERCADOPAGO_PUBLIC_KEY=APP_USR-public-key
```

You also need to add the configuration to your `config/services.php`:

```php
'mercadopago' => [
    'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
    'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
    'test_mode' => env('MERCADOPAGO_TEST_MODE', true),
    'country' => env('MERCADOPAGO_COUNTRY', 'MX'),
    'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
    'return_url' => env('MERCADOPAGO_RETURN_URL'),
    'callback_url' => env('MERCADOPAGO_CALLBACK_URL'),
],
```

## Basic Usage

### Initialize Payment

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'MXN',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'redirect_url' => 'https://yoursite.com/payment/callback',
    'customer' => [
        'name' => 'Juan Pérez',
        'phone' => '5512345678',
        'address' => '123 Main St',
        'city' => 'Mexico City',
        'country' => 'MX',
        'postal_code' => '06000',
        'identification' => [
            'type' => 'DNI',
            'number' => '12345678'
        ]
    ],
    'metadata' => [
        'order_id' => 'ORD123456',
        'user_id' => 789
    ]
];

$payment = Payment::gateway('mercadopago')->pay($paymentRequest);
```

This will return a payment URL that you need to redirect the user to:

```php
if ($payment['success']) {
    // Store preference_id for later verification
    session(['mercadopago_preference_id' => $payment['preference_id']]);

    // Redirect to Mercado Pago payment page
    return redirect($payment['payment_url']);
}
```

### Process Payment Return

```php
// routes/web.php
Route::get('/payment/success', [PaymentController::class, 'success']);
Route::post('/mercadopago/callback', [MercadoPagoController::class, 'callback']);
```

```php
// app/Http/Controllers/MercadoPagoController.php

use Mdiqbal\LaravelPayments\Facades\Payment;

class MercadoPagoController extends Controller
{
    public function callback(Request $request)
    {
        // Parse callback data
        $gateway = Payment::gateway('mercadopago');
        $callbackData = $gateway->parseCallback($request);

        // Process the webhook
        $result = $gateway->verify($callbackData);

        if ($result['success']) {
            $transactionId = $result['transaction_id'];
            $status = $result['status'];

            if ($status === 'completed') {
                // Update order status
                $order = Order::where('transaction_id', $transactionId)->first();
                if ($order) {
                    $order->status = 'paid';
                    $order->paid_at = now();
                    $order->payment_method = $result['payment_method_id'];
                    $order->mercado_pago_payment_id = $result['payment_id'];
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

### Verify Payment

```php
// First, get the preference ID (stored during initialization)
$preferenceId = session('mercadopago_preference_id');

$verification = Payment::gateway('mercadopago')->verify($preferenceId);

if ($verification['success']) {
    $status = $verification['status'];

    if ($status === 'completed') {
        // Payment was successful
        $paymentId = $verification['payment_id'];
        $amount = $verification['amount'];
        $currency = $verification['currency'];
    }
}
```

### Process Refund

```php
$refundData = [
    'payment_id' => 'MERCADO_PAGO_PAYMENT_ID',
    'amount' => 50.00, // Optional - omit for full refund
    'reason' => 'Customer requested refund'
];

$refund = Payment::gateway('mercadopago')->refund($refundData);
```

## Advanced Features

### Create Customer

```php
$customerData = [
    'email' => 'customer@example.com',
    'first_name' => 'Juan',
    'last_name' => 'Pérez',
    'phone' => [
        'area_code' => '55',
        'number' => '12345678'
    ],
    'identification' => [
        'type' => 'DNI',
        'number' => '12345678'
    ],
    'description' => 'Premium customer',
    'metadata' => [
        'internal_id' => 123,
        'segment' => 'premium'
    ]
];

$customer = Payment::gateway('mercadopago')->createCustomer($customerData);
```

### Search Transactions

```php
// Search by external reference
$results = Payment::gateway('mercadopago')->searchTransactions([
    'external_reference' => 'TXN123456',
    'limit' => 20
]);

// Search with multiple filters
$results = Payment::gateway('mercadopago')->searchTransactions([
    'payment_type_id' => 'credit_card',
    'payment_method_id' => 'visa',
    'status' => 'approved',
    'date_created_from' => '2024-01-01T00:00:00Z',
    'date_created_to' => '2024-12-31T23:59:59Z',
    'limit' => 50
]);
```

### Get Transaction Status

```php
$paymentId = '1234567890';
$status = Payment::gateway('mercadopago')->getTransactionStatus($paymentId);
```

### Create Subscriptions

```php
$subscriptionData = [
    'plan_id' => 'PREAPPROVAL_PLAN_ID',
    'payer_email' => 'customer@example.com',
    'back_url' => 'https://yoursite.com/subscription/return',
    'reason' => 'Monthly Premium Subscription',
    'external_reference' => 'SUB-' . time(),
    'auto_recurring' => [
        'frequency' => 1,
        'frequency_type' => 'months',
        'transaction_amount' => 99.99,
        'currency_id' => 'MXN'
    ]
];

$subscription = Payment::gateway('mercadopago')->createSubscription($subscriptionData);
```

### Cancel Subscriptions

```php
$subscriptionId = 'SUBSCRIPTION_ID';
$result = Payment::gateway('mercadopago')->cancelSubscription($subscriptionId);
```

## Webhook Setup

Mercado Pago uses webhooks to notify your application about payment status changes.

1. Configure your webhook URL in the Mercado Pago dashboard

2. Create a route to handle webhooks:

```php
// routes/web.php
Route::post('/mercadopago/webhook', [MercadoPagoWebhookController::class, 'handleWebhook']);
```

3. Create the webhook controller:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class MercadoPagoWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $gateway = Payment::gateway('mercadopago');
        $callbackData = $gateway->parseCallback($request);

        // Process the webhook
        $result = $gateway->verify($callbackData);

        if ($result['success']) {
            $eventType = $result['event_type'];
            $transactionId = $result['transaction_id'];
            $paymentId = $result['payment_id'];

            switch ($eventType) {
                case 'payment.approved':
                    $this->handleApprovedPayment($result);
                    break;

                case 'payment.pending':
                    $this->handlePendingPayment($result);
                    break;

                case 'payment.rejected':
                case 'payment.cancelled':
                    $this->handleFailedPayment($result);
                    break;

                case 'payment.refunded':
                    $this->handleRefundedPayment($result);
                    break;

                case 'payment.in_mediation':
                    $this->handleDisputedPayment($result);
                    break;

                case 'chargeback.created':
                    $this->handleChargeback($result);
                    break;
            }
        }

        // Always return 200 OK to acknowledge receipt
        return response()->json(['status' => 'received']);
    }

    protected function handleApprovedPayment($data)
    {
        // Update order status
        $order = Order::where('transaction_id', $data['transaction_id'])->first();
        if ($order) {
            $order->status = 'paid';
            $order->paid_at = now();
            $order->payment_method = $data['payment_method_id'];
            $order->mercado_pago_payment_id = $data['payment_id'];
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
        Log::warning('Mercado Pago payment failed', [
            'transaction_id' => $data['transaction_id'],
            'payment_id' => $data['payment_id'],
            'event_type' => $data['event_type']
        ]);

        // Update order status
        $order = Order::where('transaction_id', $data['transaction_id'])->first();
        if ($order) {
            $order->status = 'failed';
            $order->save();
        }
    }

    protected function handleRefundedPayment($data)
    {
        // Process refund
        $order = Order::where('transaction_id', $data['transaction_id'])->first();
        if ($order) {
            $order->status = 'refunded';
            $order->refunded_at = now();
            $order->save();
        }
    }

    protected function handleDisputedPayment($data)
    {
        // Payment is in mediation/dispute
        $order = Order::where('transaction_id', $data['transaction_id'])->first();
        if ($order) {
            $order->status = 'disputed';
            $order->disputed_at = now();
            $order->save();
        }
    }

    protected function handleChargeback($data)
    {
        // Handle chargeback
        Log::warning('Mercado Pago chargeback received', [
            'chargeback_id' => $data['chargeback_id']
        ]);
    }
}
```

## Payment Flow

1. **Initialize Payment**: Call `pay()` to create a preference and get checkout URL
2. **Redirect User**: Redirect customer to Mercado Pago checkout page
3. **Customer Action**: Customer completes payment in Mercado Pago interface
4. **Webhook**: Mercado Pago sends webhook to your server
5. **Verification**: Use `verify()` to confirm payment status
6. **Complete**: Update order status and notify customer

## Webhook Security

Mercado Pago uses HMAC SHA256 signatures for webhook security:

1. **Signature Format**: `ts={timestamp},v1={hash}`
2. **Verification**: Gateway automatically verifies signatures if `webhook_secret` is configured
3. **Timestamp Validation**: Rejects webhooks older than 5 minutes to prevent replay attacks

## Error Handling

The Mercado Pago gateway provides detailed error messages:

```php
$payment = Payment::gateway('mercadopago')->pay($paymentRequest);

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

## Testing

### Test Mode

Use test credentials for development:

```env
MERCADOPAGO_TEST_MODE=true
```

### Test Cards

Use these test card details for testing:

- **Visa**: 4235644728426356
- **Mastercard**: 5031755734530604
- **American Express**: 371180303257522
- **Any Expiry**: Future date
- **CVV**: Any 3 digits
- **Cardholder Name**: Any name

### Test Scenarios

1. **Successful Payment**: Use valid test card details
2. **Failed Payment**: Use card number 4509 9535 6623 3704
3. **Pending Payment**: Payment processing in progress
4. **Refund**: Process refunds for test transactions

## Payment Methods

### Available Methods by Country

#### Argentina
- **Credit/Debit Cards**: Visa, Mastercard, American Express, Cabal, Naranja
- **Digital Wallet**: Mercado Pago Wallet
- **Cash**: Pago Fácil, Rapipago, Provincia Net
- **Bank Transfer**: debito automatico

#### Brazil
- **Credit/Debit Cards**: Visa, Mastercard, Elo, Hipercard, American Express
- **Digital Wallet**: Mercado Pago Wallet
- **Cash**: Boleto Bancário
- **Bank Transfer**: Transferência Bancária

#### Chile
- **Credit/Debit Cards**: Visa, Mastercard, American Express, Magna, Diners Club
- **Digital Wallet**: Mercado Pago Wallet
- **Cash**: Servipag, Multicaja, Webpay

#### Colombia
- **Credit/Debit Cards**: Visa, Mastercard, American Express, Diners Club
- **Digital Wallet**: Mercado Pago Wallet
- **Cash**: Baloto, Efecty, SuRed
- **Bank Transfer**: PSE

#### Mexico
- **Credit/Debit Cards**: Visa, Mastercard, American Express, Carnet
- **Digital Wallet**: Mercado Pago Wallet
- **Cash**: OXXO, 7-Eleven, Farmacias del Ahorro
- **Bank Transfer**: SPEI

#### Peru
- **Credit/Debit Cards**: Visa, Mastercard, American Express, Diners Club
- **Digital Wallet**: Mercado Pago Wallet
- **Cash**: Pago Efectivo, Agencias

#### Uruguay
- **Credit/Debit Cards**: Visa, Mastercard, Oca
- **Digital Wallet**: Mercado Pago Wallet
- **Cash**: Redpagos, Abitab
- **Bank Transfer**: Abitab

## Best Practices

### Security

1. **Never expose your access token** in frontend code
2. **Always verify webhook signatures** using the built-in verification
3. **Use HTTPS** for all webhook endpoints
4. **Implement proper error handling** and logging
5. **Validate all inputs** before processing

### Transaction Management

1. **Store preference IDs** during initialization for later verification
2. **Always verify payments** through webhooks, not just URL returns
3. **Implement retry logic** for failed API calls
4. **Log all transaction attempts** for auditing
5. **Handle different payment methods** appropriately

### User Experience

1. **Show payment processing status** to users
2. **Redirect users appropriately** after payment
3. **Display proper error messages** in case of failures
4. **Send email confirmations** for successful payments
5. **Provide support contact** for payment issues

## Supported Currencies

Mercado Pago processes payments in local currencies for each supported country:

- **ARS** (Argentine Peso) - Argentina
- **BRL** (Brazilian Real) - Brazil
- **CLP** (Chilean Peso) - Chile
- **COP** (Colombian Peso) - Colombia
- **MXN** (Mexican Peso) - Mexico
- **PEN** (Peruvian Sol) - Peru
- **UYU** (Uruguayan Peso) - Uruguay
- **USD** (US Dollar) - Limited support
- **EUR** (Euro) - Limited support

## Country Support

Mercado Pago primarily serves Latin America:

- **Argentina** (Full feature support)
- **Brazil** (Full feature support)
- **Chile** (Full feature support)
- **Colombia** (Full feature support)
- **Mexico** (Full feature support)
- **Peru** (Full feature support)
- **Uruguay** (Full feature support)
- **Other Countries** (Limited support through international processing)

## Rate Limits

Mercado Pago implements rate limits to prevent abuse:
- **Standard**: 1000 requests per hour
- **High Volume**: 6000 requests per hour
- **Webhooks**: 100 events per minute

## SDK Methods Reference

### Payment Methods
- `pay()` - Initialize a payment and create preference
- `verify($payload)` - Verify webhook payload
- `verify($paymentId)` - Verify a transaction status
- `refund()` - Process a refund (full or partial)
- `getTransactionStatus()` - Get transaction status

### Customer Management
- `createCustomer()` - Create a customer record

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

### Multi-Country Support

```php
// If you have multiple Mercado Pago accounts for different countries
$gateway = Payment::gateway('mercadopago', [
    'access_token' => 'country_specific_token',
    'country' => 'BR',
    'public_key' => 'country_public_key'
]);
```

### Custom Item Descriptions

```php
$paymentRequest = [
    'amount' => 100.00,
    'currency' => 'MXN',
    'email' => 'customer@example.com',
    'transaction_id' => 'TXN' . time(),
    'items' => [
        [
            'title' => 'Product 1',
            'quantity' => 2,
            'unit_price' => 25.00,
            'currency_id' => 'MXN'
        ],
        [
            'title' => 'Product 2',
            'quantity' => 1,
            'unit_price' => 50.00,
            'currency_id' => 'MXN'
        ]
    ],
    'redirect_url' => 'https://yoursite.com/payment/callback'
];

$payment = Payment::gateway('mercadopago')->pay($paymentRequest);
```

### Installments Configuration

```php
// Configure installments in the payment request
$paymentRequest = [
    // ... other fields
    'payment_methods' => [
        'installments' => 12, // Max number of installments
        'excluded_payment_methods' => [
            ['id' => 'amex'] // Exclude American Express
        ],
        'excluded_payment_types' => [
            ['id' => 'debit_card'] // Exclude debit cards
        ]
    ]
];
```

### Recurring Payments

Since Mercado Pago supports native recurring payments:

```php
// Create a preapproval plan first
$planData = [
    'reason' => 'Monthly Premium Subscription',
    'auto_recurring' => [
        'frequency' => 1,
        'frequency_type' => 'months',
        'transaction_amount' => 99.99,
        'currency_id' => 'MXN'
    ],
    'back_url' => 'https://yoursite.com/subscription/return',
    'external_reference' => 'PLAN-' . time()
];

$subscription = Payment::gateway('mercadopago')->createSubscription($planData);

if ($subscription['success']) {
    // Redirect user to subscribe
    return redirect($subscription['init_point']);
}
```

### Session Management

```php
class PaymentController extends Controller
{
    public function initiate(Request $request)
    {
        $payment = Payment::gateway('mercadopago')->pay($request->all());

        if ($payment['success']) {
            // Store payment information in session
            session([
                'mercadopago_preference_id' => $payment['preference_id'],
                'transaction_id' => $payment['transaction_id'],
                'amount' => $payment['amount']
            ]);

            return redirect($payment['payment_url']);
        }

        return back()->with('error', 'Failed to initialize payment');
    }

    public function success(Request $request)
    {
        // User returned from Mercado Pago after payment
        // Wait for webhook confirmation before updating order
        return view('payment.processing');
    }
}
```

## Support

For Mercado Pago-specific support:
- Email: developers@mercadopago.com
- Documentation: https://www.mercadopago.com/developers
- Developer Portal: https://www.mercadopago.com/developers
- Community: https://comunidad.mercadopago.com/

For Laravel Payments package support:
- GitHub Issues: https://github.com/your-username/laravel-payments/issues
- Email: your-email@example.com

## Changelog

### v1.0.0
- Initial Mercado Pago integration
- Preference-based checkout implementation
- Webhook signature verification
- Refund processing (full and partial)
- Customer management
- Transaction search functionality
- Subscription support
- Multi-country configuration
- Advanced error handling and logging