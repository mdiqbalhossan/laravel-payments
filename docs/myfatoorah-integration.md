# MyFatoorah Integration Guide

## Overview

MyFatoorah is a leading payment gateway in the Middle East and North Africa (MENA) region, providing comprehensive payment solutions across 8 countries. This integration supports multiple payment methods including credit/debit cards, bank transfers, e-wallets, and region-specific payment options like KNET (Kuwait), SADAD (Saudi Arabia), and MADA.

## Features

- **Multiple Payment Methods**: Credit/Debit Cards, KNET, SADAD, MADA, Apple Pay, Google Pay, and more
- **Multi-Country Support**: Saudi Arabia, UAE, Kuwait, Bahrain, Oman, Jordan, Egypt, Qatar
- **Multi-Currency Support**: SAR, KWD, BHD, AED, QAR, OMR, JOD, EGP, USD, EUR
- **Payment Links**: Generate shareable payment links with multiple notification options
- **Direct Payment**: Seamless payment experience with redirect URLs
- **Real-time Notifications**: Comprehensive webhook system for payment events
- **Multi-Language Support**: English and Arabic interfaces
- **Invoice Generation**: Professional invoicing with payment tracking

## Supported Countries

| Country | Currency | Code | Primary Payment Methods |
|---------|----------|------|-----------------------|
| Saudi Arabia | Saudi Riyal | SAR | SADAD, MADA, Credit Cards |
| Kuwait | Kuwaiti Dinar | KWD | KNET, KNET Credit, Credit Cards |
| UAE | UAE Dirham | AED | Credit Cards, Debit Cards, Benefit |
| Bahrain | Bahraini Dinar | BHD | Benefit, Credit Cards |
| Oman | Omani Rial | OMR | NAPS, OmanNet |
| Jordan | Jordanian Dinar | JOD | Credit Cards |
| Egypt | Egyptian Pound | EGP | Credit Cards, Mobile Wallets |
| Qatar | Qatari Riyal | QAR | QPay, QCard, Credit Cards |

## Supported Payment Methods

| Method | Code | Description | Countries |
|--------|------|-------------|-----------|
| Credit/Debit Card | credit_card | Visa, Mastercard, Amex, UnionPay | All |
| KNET | knet | Kuwait Electronic Payment System | Kuwait |
| SADAD | sadad | Saudi Arabia's bank payment system | Saudi Arabia |
| MADA | mada | Saudi Arabia's debit card system | Saudi Arabia |
| NAPS | naps | Oman's National Payment System | Oman |
| Benefit | benefit | Bahrain's electronic payment | Bahrain |
| QPay | qpay | Qatar's digital wallet system | Qatar |
| STC Pay | stcpay | Saudi Telecom payment service | Saudi Arabia |
| Apple Pay | applepay | Apple digital wallet | All |
| Google Pay | googlepay | Google digital wallet | All |

## Configuration

### 1. Install Package

```bash
composer require myfatoorah/laravel-package
```

### 2. Environment Variables

Add these variables to your `.env` file:

```env
# MyFatoorah Configuration
MYFATOORAH_API_KEY=YOUR_API_KEY
MYFATOORAH_TEST_MODE=true
MYFATOORAH_WEBHOOK_URL=https://yourapp.com/payment/myfatoorah/webhook
MYFATOORAH_SUCCESS_URL=https://yourapp.com/payment/myfatoorah/success
MYFATOORAH_ERROR_URL=https://yourapp.com/payment/myfatoorah/error
```

### 3. Publish Configuration

```bash
php artisan vendor:publish --provider="Mdiqbal\LaravelPayments\PaymentsServiceProvider"
```

### 4. Update Config File

```php
// config/payments.php
'gateways' => [
    'myfatoorah' => [
        'driver' => 'myfatoorah',
        'api_key' => env('MYFATOORAH_API_KEY'),
        'test_mode' => env('MYFATOORAH_TEST_MODE', true),
        'webhook_url' => env('MYFATOORAH_WEBHOOK_URL'),
        'success_url' => env('MYFATOORAH_SUCCESS_URL'),
        'error_url' => env('MYFATOORAH_ERROR_URL'),
    ],
],
```

## MyFatoorah Account Setup

### 1. Create MyFatoorah Account

1. [Register on MyFatoorah](https://www.myfatoorah.com/register)
2. Complete the merchant registration form
3. Submit business documents (Commercial License, Tax ID, ID)
4. Wait for approval (typically 1-2 business days)

### 2. Get API Credentials

Once approved:
1. Log into MyFatoorah Dashboard
2. Navigate to Settings > API Settings
3. Note down your API Key
4. Configure sandbox/production mode

### 3. Configure Webhooks

In your MyFatoorah dashboard:
1. Go to Settings > Webhooks
2. Add your webhook URL: `https://yourapp.com/payment/myfatoorah/webhook`
3. Enable notifications for:
   - Payment Success
   - Payment Failed
   - Payment Expired
   - Refund Status

## Usage Examples

### 1. Basic Payment Processing

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTOs\PaymentRequest;

// Initialize MyFatoorah gateway
$payment = Payment::gateway('myfatoorah');

// Create a payment request
$response = $payment->pay(new PaymentRequest(
    amount: 100.00,
    currency: 'SAR',
    orderId: 'ORDER-' . uniqid(),
    description: 'Product purchase',
    customer: [
        'name' => 'Ahmed Mohammed',
        'email' => 'ahmed@example.com',
        'phone' => '+966501234567',
        'country' => 'SA',
    ],
    returnUrl: route('payment.success'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: route('payment.webhook'),
    metadata: [
        'language' => 'ar', // or 'en'
        'expiry_time' => 1440, // 24 hours in minutes
        'user_defined_field' => 'CUSTOM_VALUE',
        'items' => [
            [
                'ItemName' => 'Premium Service',
                'Quantity' => 1,
                'UnitPrice' => 100.00
            ]
        ]
    ]
));

if ($response->success) {
    // Redirect to MyFatoorah payment page
    return redirect($response->redirectUrl);
} else {
    // Handle error
    return back()->with('error', $response->message);
}
```

### 2. Payment Link Creation with Multiple Notifications

```php
$linkResponse = $payment->createPaymentLink([
    'amount' => 500.00,
    'currency' => 'KWD',
    'order_id' => 'LINK-' . time(),
    'description' => 'Service payment',
    'customer_name' => 'Salem Al-Otaibi',
    'customer_email' => 'salem@example.com',
    'customer_phone' => '+965511234567',
    'customer_reference' => 'REF-' . uniqid(),
    'notification_option' => 'All', // Link, SMS, Email
    'language' => 'ar',
    'expiry_time' => 4320, // 72 hours
    'callback_url' => route('payment.webhook'),
    'error_url' => route('payment.error'),
    'items' => [
        [
            'ItemName' => 'Consultation Service',
            'Quantity' => 1,
            'UnitPrice' => 500.00
        ]
    ],
]);

if ($linkResponse->success) {
    $paymentUrl = $linkResponse->redirectUrl;
    // Send payment link via SMS, email, or WhatsApp
    return response()->json([
        'payment_url' => $paymentUrl,
        'invoice_id' => $linkResponse->data['invoice_id']
    ]);
}
```

### 3. Direct Payment with Specific Payment Method

```php
// First, get available payment methods
$paymentMethodsResponse = $payment->getPaymentMethods();

if ($paymentMethodsResponse->success) {
    $paymentMethods = $paymentMethodsResponse->data['payment_methods'];

    // Find KNET method ID
    $knetMethod = collect($paymentMethods)->firstWhere('PaymentMethod', 'knet');

    if ($knetMethod) {
        $directResponse = $payment->initiatePayment([
            'payment_method_id' => $knetMethod['PaymentMethodId'],
            'payment_type' => 1,
            'amount' => 250.00,
            'currency' => 'KWD',
            'customer_name' => 'Mohammad Al-Ahmad',
            'customer_email' => 'mohammad@example.com',
            'customer_phone' => '+965223456789',
            'customer_reference' => 'DIR-' . uniqid(),
            'redirect_url' => route('payment.redirect'),
            'callback_url' => route('payment.webhook'),
            'language' => 'ar',
        ]);

        if ($directResponse->success) {
            return redirect($directResponse->redirectUrl);
        }
    }
}
```

### 4. Payment Status Check

```php
// Check payment status using invoice ID
$invoiceId = '123456789';
$response = $payment->verify(['invoice_id' => $invoiceId]);

if ($response->success) {
    echo "Payment Status: " . $response->status;
    echo "Invoice ID: " . $response->data['invoice_id'];
    echo "Payment ID: " . $response->data['payment_id'];
    echo "Amount Paid: " . $response->data['paid_amount'];
    echo "Payment Method: " . $response->data['payment_method'];

    if ($response->status === 'completed') {
        // Update order status
        // Send confirmation email
        // Process order fulfillment
    }
}
```

### 5. Process Refunds

```php
// Process a refund
$refundResponse = $payment->refund([
    'payment_id' => 'PAYMENT-123456789',
    'amount' => 50.00,
    'reason' => 'Customer requested partial refund',
    'charge_on_customer' => false // Refund charges are borne by merchant
]);

if ($refundResponse->success) {
    echo "Refund processed successfully";
    echo "Refund ID: " . $refundResponse->data['refund_id'];
    echo "Refund Amount: " . $refundResponse->data['refund_amount'];
}
```

### 6. Country-Specific Payment Method

```php
// Saudi Arabia - MADA payment
$saudiPayment = $payment->pay(new PaymentRequest(
    amount: 1200.00,
    currency: 'SAR',
    orderId: 'SA-' . uniqid(),
    customer: [
        'name' => 'Saeed Al-Harbi',
        'email' => 'saeed@example.com',
        'phone' => '+966551234567',
    ],
    metadata: [
        'payment_method_id' => '2', // MADA method ID
        'language' => 'ar',
    ]
));

// Kuwait - KNET payment
$kuwaitPayment = $payment->pay(new PaymentRequest(
    amount: 150.00,
    currency: 'KWD',
    orderId: 'KW-' . uniqid(),
    customer: [
        'name' => 'Fahad Al-Mutairi',
        'email' => 'fahad@example.com',
        'phone' => '+965511234567',
    ],
    metadata: [
        'payment_method_id' => '1', // KNET method ID
        'language' => 'ar',
    ]
));
```

## Webhook Handling

### 1. Create Webhook Route

```php
// routes/web.php
Route::post('/payment/myfatoorah/webhook', [MyfatoorahController::class, 'handleWebhook'])
    ->name('payment.myfatoorah.webhook')
    ->middleware('myfatoorah.webhook');
```

### 2. Webhook Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class MyfatoorahController extends Controller
{
    /**
     * Handle MyFatoorah webhook
     */
    public function handleWebhook(Request $request)
    {
        $gateway = Payment::gateway('myfatoorah');

        // Process webhook
        $response = $gateway->processWebhook($request->all());

        if ($response->success) {
            // Extract webhook data
            $webhookData = $response->data;
            $eventType = $webhook['webhook_type'];
            $status = $response->status;

            // Process based on status
            switch ($status) {
                case 'completed':
                    $this->handleSuccessfulPayment($webhookData);
                    break;

                case 'pending':
                    $this->handlePendingPayment($webhookData);
                    break;

                case 'failed':
                case 'cancelled':
                case 'expired':
                    $this->handleFailedPayment($webhookData);
                    break;

                case 'refunded':
                    $this->handleRefund($webhookData);
                    break;
            }

            return response('Webhook processed successfully');
        }

        return response('Webhook processing failed', 400);
    }

    /**
     * Handle successful payment
     */
    private function handleSuccessfulPayment(array $data)
    {
        // Update your database
        DB::table('payments')
            ->where('invoice_id', $data['invoice_id'])
            ->update([
                'status' => 'completed',
                'payment_id' => $data['payment_id'],
                'payment_method' => $data['payment_method'] ?? null,
                'paid_amount' => $data['paid_amount'] ?? 0,
                'currency' => $data['invoice_value'] ?? 0,
                'transaction_date' => $data['transaction_date'] ?? null,
                'customer_reference' => $data['customer_reference'] ?? null,
                'paid_at' => now()
            ]);

        // Update order status
        DB::table('orders')
            ->where('id', $data['order_id'] ?? $data['customer_reference'])
            ->update(['status' => 'paid']);

        // Send confirmation email
        // Generate receipt
        // Trigger fulfillment process
    }

    /**
     * Handle pending payment
     */
    private function handlePendingPayment(array $data)
    {
        DB::table('payments')
            ->where('invoice_id', $data['invoice_id'])
            ->update([
                'status' => 'pending',
                'pending_at' => now()
            ]);

        // Send reminder notification
        // Log pending payment
    }

    /**
     * Handle failed payment
     */
    private function handleFailedPayment(array $data)
    {
        DB::table('payments')
            ->where('invoice_id', $data['invoice_id'])
            ->update([
                'status' => 'failed',
                'failure_reason' => $data['status'] ?? 'Payment failed',
                'failed_at' => now()
            ]);

        // Notify customer
        // Log the failure for review
    }

    /**
     * Handle refund
     */
    private function handleRefund(array $data)
    {
        DB::table('refunds')
            ->where('payment_id', $data['payment_id'])
            ->update([
                'status' => 'completed',
                'processed_at' => now()
            ]);

        // Notify customer
        // Update inventory if needed
    }
}
```

### 3. Webhook Middleware

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MyfatoorahWebhook
{
    // MyFatoorah IP ranges (check documentation for latest IPs)
    private $allowedIps = [
        '51.15.102.69', // MyFatoorah IP
        '51.15.102.71', // MyFatoorah IP
        // Add more IPs as provided by MyFatoorah
    ];

    public function handle(Request $request, Closure $next)
    {
        // Log webhook for debugging
        Log::info('MyFatoorah webhook received', [
            'ip' => $request->ip(),
            'payload' => $request->all()
        ]);

        // Optional: IP filtering
        if (!in_array($request->ip(), $this->allowedIps)) {
            Log::warning('Unauthorized webhook attempt', [
                'ip' => $request->ip()
            ]);
            // Uncomment to enable IP filtering
            // abort(403, 'Unauthorized');
        }

        return $next($request);
    }
}
```

## Callback Handling

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class PaymentController extends Controller
{
    /**
     * Handle successful payment callback
     */
    public function handleSuccess(Request $request)
    {
        $paymentId = $request->get('paymentId');
        $invoiceId = $request->get('invoiceId');

        // Verify payment status
        $gateway = Payment::gateway('myfatoorah');
        $response = $gateway->verify(['payment_id' => $paymentId]);

        if ($response->success && $response->status === 'completed') {
            return view('payment.success', [
                'invoice_id' => $response->data['invoice_id'],
                'payment_id' => $response->data['payment_id'],
                'amount' => $response->data['paid_amount'],
                'currency' => $response->data['currency'],
                'payment_method' => $response->data['payment_method'],
                'transaction_date' => $response->data['transaction_date'],
                'customer_name' => $response->data['customer_name'],
            ]);
        }

        // Payment not completed yet
        return redirect()->route('payment.pending')
            ->with('invoice_id', $invoiceId);
    }

    /**
     * Show pending payment page
     */
    public function showPending(Request $request)
    {
        $invoiceId = $request->session('invoice_id');

        if (!$invoiceId) {
            return redirect()->route('home');
        }

        return view('payment.pending', [
            'invoice_id' => $invoiceId,
            'check_url' => route('payment.check.status'),
        ]);
    }

    /**
     * Check payment status (AJAX endpoint)
     */
    public function checkStatus(Request $request)
    {
        $invoiceId = $request->get('invoice_id');

        $gateway = Payment::gateway('myfatoorah');
        $response = $gateway->verify(['invoice_id' => $invoiceId]);

        return response()->json([
            'status' => $response->success ? 'success' : 'error',
            'data' => $response->data
        ]);
    }
}
```

## Testing

### 1. Test Environment

MyFatoorah provides a sandbox environment:

```env
MYFATOORAH_TEST_MODE=true
```

### 2. Test Credentials

Get test credentials from MyFatoorah:
1. Request sandbox access from MyFatoorah support
2. Use provided test API Key
3. Use sandbox URLs for testing

### 3. Test Payment Scenarios

```php
// Test payment link with email notification
$testLink = $payment->createPaymentLink([
    'amount' => 100.00,
    'currency' => 'SAR',
    'description' => 'Test payment',
    'customer_email' => 'test@example.com',
    'customer_phone' => '+966501234567',
    'notification_option' => 'Email',
    'language' => 'en'
]);
```

## Security Considerations

### 1. API Security

- Use HTTPS for all communications
- Validate all incoming data
- Never expose API keys in frontend code
- Implement proper error handling

### 2. Webhook Security

```php
// Always validate webhook data structure
if (!isset($data['EventType']) {
    Log::error('Invalid webhook structure', ['data' => $data]);
    abort(400, 'Invalid webhook');
}

// Optional: Additional security checks
$expectedEventTypes = ['1', '2', '3', '4']; // Payment event types
if (!in_array($data['EventType'], $expectedEventTypes)) {
    Log::warning('Unexpected event type', ['event' => $data['EventType']]);
    abort(400, 'Unexpected event type');
}
```

### 3. Data Protection

- Encrypt sensitive data at rest
- Use secure storage for customer data
- Comply with regional data protection laws
- Implement proper logging and monitoring

## Error Handling

### Common Error Codes

| Code | Description | Solution |
|------|-------------|----------|
| 400 | Bad Request | Check request parameters |
| 401 | Unauthorized | Verify API Key |
| 404 | Not Found | Check endpoint URL |
| 422 | Unprocessable Entity | Check request format |
| 500 | Server Error | Retry with backoff |
| 3001 | Invalid Invoice | Check invoice data |
| 3002 | Duplicate Invoice | Use unique IDs |
| 3003 | Invalid Amount | Check amount format |

### Error Handling Example

```php
try {
    $response = $payment->pay($paymentRequest);

    if (!$response->success) {
        // Log error details
        Log::error('MyFatoorah payment failed', [
            'error_code' => $response->errorCode,
            'message' => $response->message,
            'order_id' => $paymentRequest->orderId
        ]);

        // Show user-friendly message
        $userMessage = $this->getUserFriendlyErrorMessage($response->errorCode);
        return back()->with('error', $userMessage);
    }
} catch (\Exception $e) {
    Log::error('MyFatoorah gateway error', [
        'error' => $e->getMessage()
    ]);

    return back()->with('error', 'Payment service temporarily unavailable.');
}

private function getUserFriendlyErrorMessage(string $errorCode): string
{
    $errorMessages = [
        '3001' => 'Invalid invoice data. Please check the format.',
        '3002' => 'Duplicate invoice. Please use a unique reference.',
        '3003' => 'Invalid amount format. Please check the amount.',
        '3004' => 'Invalid currency. Please use supported currencies.',
        '3005' => 'Payment method not available. Please try another option.',
    ];

    return $errorMessages[$errorCode] ?? 'Payment failed. Please try again.';
}
```

## Best Practices

### 1. Transaction Management

- Always use unique customer references
- Store invoice IDs for verification
- Implement proper error handling
- Log all transactions

### 2. Customer Experience

- Show loading indicators during payment
- Provide clear error messages
- Support Arabic and English
- Show payment method icons for better UX

### 3. Performance Optimization

```php
// Cache payment methods information
$paymentMethods = Cache::remember('myfatoorah_payment_methods', 3600, function () {
    $gateway = Payment::gateway('myfatoorah');
    $response = $gateway->getPaymentMethods();

    return $response->success ? $response->data['payment_methods'] : [];
});

// Use CDN for static assets
<script src="https://portal.myfatoorah.com/portal/v1/js/payment-methods.js"></script>
```

### 4. Regional Compliance

```php
// Set language based on customer preference or country
$language = $request->metadata['language'] ?? 'en';

// Adjust currency formatting based on locale
if ($request->currency === 'SAR') {
    $formattedAmount = number_format($amount, 2, '.', ',');
} else {
    $formattedAmount = number_format($amount, 2, '.', ',');
}
```

## Localization

MyFatoorah supports multiple languages:

```php
// English (default)
'language' => 'en'

// Arabic
'language' => 'ar'
```

## Rate Limits

- Standard API: 100 requests per minute
- Direct payment API: 60 requests per minute
- Status check: 1000 requests per hour
- Implement proper rate limiting

## Support

- MyFatoorah Documentation: https://myfatoorah.readme.io/
- API Documentation: https://api.myfatoorah.com/
- Dashboard: https://portal.myfatoorah.com/
- Email: support@myfatoorah.com
- Phone: +966-920000024 (Saudi Arabia)

## Troubleshooting

### Common Issues

1. **Payment Link Not Created**
   - Check API key configuration
   - Verify test/live mode settings
   - Ensure proper request format

2. **Webhook Not Received**
   - Check webhook URL accessibility
   - Verify webhook URL in MyFatoorah dashboard
   - Check firewall configuration

3. **Payment Method Not Available**
   - Check country-specific payment methods
   - Verify merchant has enabled the payment method
   - Check currency compatibility

4. **Currency Issues**
   - Use supported currencies only
   - Check decimal point handling
   - Verify exchange rates

### Debug Mode

Enable debug logging:

```php
// config/payments.php
'myfatoorah' => [
    // ... other config
    'debug' => env('APP_DEBUG', false),
],
```

This will log all MyFatoorah requests and responses for debugging purposes.