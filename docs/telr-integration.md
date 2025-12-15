# Telr Integration Guide

## Overview

Telr is a leading payment gateway in the Middle East and North Africa (MENA) region, providing secure payment processing solutions for businesses. This integration supports multiple payment methods including credit/debit cards, Apple Pay, Samsung Pay, and regional payment methods like SADAD (Saudi Arabia), KNET (Kuwait), and Fawry (Egypt).

## Features

- **Multiple Payment Methods**: Credit/Debit Cards, Apple Pay, Samsung Pay
- **Regional Payment Support**: SADAD, KNET, Fawry, NAPS
- **Hosted Payment Page**: No PCI compliance required
- **Multi-Currency Support**: 20+ currencies including AED, SAR, USD, EUR
- **Real-time Verification**: Instant transaction status checks
- **Secure Webhooks**: SHA256 signature verification
- **Refund Processing**: Full and partial refund capabilities

## Supported Currencies

| Currency | Code | Countries |
|----------|------|-----------|
| UAE Dirham | AED | United Arab Emirates |
| US Dollar | USD | International |
| Euro | EUR | European Union |
| British Pound | GBP | United Kingdom |
| Saudi Riyal | SAR | Saudi Arabia |
| Qatari Riyal | QAR | Qatar |
| Kuwaiti Dinar | KWD | Kuwait |
| Bahraini Dinar | BHD | Bahrain |
| Omani Rial | OMR | Oman |
| Jordanian Dinar | JOD | Jordan |
| Egyptian Pound | EGP | Egypt |
| Indian Rupee | INR | India |
| Pakistani Rupee | PKR | Pakistan |
| Sri Lankan Rupee | LKR | Sri Lanka |
- And more...

## Supported Payment Methods

| Method | Code | Description |
|--------|------|-------------|
| Credit/Debit Card | card | Visa, Mastercard, American Express |
| Apple Pay | apple_pay | Apple digital wallet |
| Samsung Pay | samsung_pay | Samsung digital wallet |
| SADAD | sadad | Saudi Arabia online banking |
| KNET | knet | Kuwait electronic payment |
| Fawry | fawry | Egypt cash payment network |
| NAPS | naps | Oman national payment system |

## Configuration

### 1. Environment Variables

Add these variables to your `.env` file:

```env
# Telr Configuration
TELR_STORE_ID=YOUR_STORE_ID
TELR_AUTH_KEY=YOUR_AUTH_KEY
TELR_TEST_MODE=true
TELR_RETURN_URL=https://yourapp.com/payment/telr/success
TELR_CANCEL_URL=https://yourapp.com/payment/telr/cancel
TELR_WEBHOOK_URL=https://yourapp.com/payment/telr/webhook
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="Mdiqbal\LaravelPayments\PaymentsServiceProvider"
```

### 3. Update Config File

```php
// config/payments.php
'gateways' => [
    'telr' => [
        'driver' => 'telr',
        'store_id' => env('TELR_STORE_ID'),
        'auth_key' => env('TELR_AUTH_KEY'),
        'test_mode' => env('TELR_TEST_MODE', true),
        'return_url' => env('TELR_RETURN_URL'),
        'cancel_url' => env('TELR_CANCEL_URL'),
        'webhook_url' => env('TELR_WEBHOOK_URL'),
    ],
],
```

## Telr Account Setup

### 1. Create Telr Account

1. [Sign up on Telr](https://www.telr.com/create-account/)
2. Complete the business verification process
3. Submit required documentation
4. Wait for approval (typically 2-3 business days)

### 2. Get API Credentials

Once approved:
1. Log into your Telr Dashboard
2. Navigate to Settings > API
3. Note down:
   - Store ID
   - Authentication Key
   - Test/Live environment settings

### 3. Configure Webhooks

In your Telr dashboard:
1. Go to Settings > Webhooks
2. Add your webhook URL: `https://yourapp.com/payment/telr/webhook`
3. Enable notifications for:
   - Payment Success
   - Payment Failure
   - Refund Status

## Usage Examples

### 1. Basic Payment Processing

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

// Initialize Telr gateway
$payment = Payment::gateway('telr');

// Create a payment request
$response = $payment->pay(new PaymentRequest(
    amount: 100.00,
    currency: 'AED',
    orderId: 'ORDER-' . uniqid(),
    description: 'Product purchase',
    customer: [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '+971501234567',
        'address' => '123 Main Street',
        'city' => 'Dubai',
        'state' => 'Dubai',
        'country' => 'AE',
        'postal_code' => '12345',
    ],
    returnUrl: route('payment.success'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: route('payment.webhook')
));

if ($response->success) {
    // Redirect to Telr payment page
    return redirect($response->redirectUrl);
} else {
    // Handle error
    return back()->with('error', $response->message);
}
```

### 2. Create Payment Link

```php
$linkResponse = $payment->createPaymentLink([
    'amount' => 250.00,
    'currency' => 'SAR',
    'order_id' => 'ORDER-' . time(),
    'description' => 'Premium subscription',
    'customer_name' => 'Ahmed Ali',
    'customer_email' => 'ahmed@example.com',
    'customer_phone' => '+966501234567',
    'customer_city' => 'Riyadh',
    'customer_country' => 'SA',
    'return_url' => route('subscription.success'),
    'cancel_url' => route('subscription.cancel'),
    'payment_method' => 'card' // Optional: specify preferred method
]);

if ($linkResponse->success) {
    // Send payment link to customer
    $paymentUrl = $linkResponse->redirectUrl;
    // Email or SMS the link
}
```

### 3. Transaction Status Check

```php
// Check payment status using transaction reference
$transactionRef = 'TXN123456789';
$response = $payment->verify(['transaction_ref' => $transactionRef]);

if ($response->success) {
    echo "Payment Status: " . $response->status;
    echo "Amount: " . $response->data['amount'];
    echo "Currency: " . $response->data['currency'];
    echo "Payment Method: " . $response->data['payment_method'];

    if ($response->status === 'completed') {
        // Update order status
        // Send confirmation email
        // Process order fulfillment
    }
}
```

### 4. Process Refunds

```php
// Full refund
$refundResponse = $payment->refund([
    'transaction_ref' => 'TXN123456789',
    'amount' => 100.00, // Full amount
    'reason' => 'Customer requested refund'
]);

// Partial refund
$partialRefund = $payment->refund([
    'transaction_ref' => 'TXN123456789',
    'amount' => 25.00, // Partial amount
    'reason' => 'Partial refund for damaged item'
]);

if ($refundResponse->success) {
    echo "Refund processed successfully";
    echo "Refund ID: " . $refundResponse->data['refund_ref'];
}
```

### 5. Payment with Regional Methods

```php
// For Saudi Arabia - SADAD
$sadadPayment = $payment->pay(new PaymentRequest(
    amount: 500.00,
    currency: 'SAR',
    orderId: 'SADAD-' . time(),
    description: 'SADAD payment',
    // ... other parameters
));

// For Kuwait - KNET
$knetPayment = $payment->pay(new PaymentRequest(
    amount: 75.00,
    currency: 'KWD',
    orderId: 'KNET-' . time(),
    description: 'KNET payment',
    // ... other parameters
));
```

## Webhook Handling

### 1. Create Webhook Route

```php
// routes/web.php
Route::post('/payment/telr/webhook', [TelrController::class, 'handleWebhook'])
    ->name('payment.telr.webhook')
    ->middleware('webhook.signature');
```

### 2. Webhook Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class TelrController extends Controller
{
    /**
     * Handle Telr webhook
     */
    public function handleWebhook(Request $request)
    {
        $gateway = Payment::gateway('telr');

        // Process webhook
        $response = $gateway->processWebhook($request->all());

        if ($response->success) {
            // Extract webhook data
            $webhookData = $response->data;

            // Process based on status
            switch ($response->status) {
                case 'completed':
                    $this->handleSuccessfulPayment($webhookData);
                    break;

                case 'failed':
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
            ->where('transaction_ref', $data['telr_ref'])
            ->update([
                'status' => 'completed',
                'telr_transaction_id' => $data['telr_transaction_id'],
                'payment_method' => $data['payment_method'],
                'card_type' => $data['card_type'],
                'card_last4' => $data['card_last4'],
                'authorization_code' => $data['authorization_code'],
                'paid_at' => now()
            ]);

        // Update order status
        DB::table('orders')
            ->where('payment_ref', $data['telr_ref'])
            ->update(['status' => 'paid']);

        // Send confirmation email
        // Generate invoice
        // Trigger fulfillment process
    }

    /**
     * Handle failed payment
     */
    private function handleFailedPayment(array $data)
    {
        DB::table('payments')
            ->where('transaction_ref', $data['telr_ref'])
            ->update([
                'status' => 'failed',
                'failure_reason' => $data['status_text'],
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
            ->where('transaction_ref', $data['telr_ref'])
            ->update([
                'status' => 'completed',
                'processed_at' => now()
            ]);

        // Notify customer
        // Update inventory if needed
    }
}
```

### 3. Webhook Signature Middleware

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WebhookSignature
{
    public function handle(Request $request, Closure $next)
    {
        $gateway = Payment::gateway('telr');
        $payload = $request->getContent();
        $receivedHash = $request->header('X-Telr-Signature');

        // Verify webhook signature
        $calculatedHash = $gateway->calculateWebhookHash($payload);

        if (!hash_equals($calculatedHash, $receivedHash)) {
            return response('Invalid signature', 401);
        }

        return $next($request);
    }
}
```

## Payment Flow

### 1. Hosted Payment Page Flow

```
Customer → Your App → Create Telr Session → Redirect to Telr → Customer Pays → Telr Webhook → Your App → Update Order
```

### 2. Status Check Flow

```
Your App → Telr Verify API → Check Status → Update Database
```

### 3. Refund Flow

```
Customer Request → Your App → Telr Refund API → Process Refund → Telr Confirmation → Update Records
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
        $transactionRef = $request->get('ref');
        $cartId = $request->get('cart');

        // Verify transaction status
        $gateway = Payment::gateway('telr');
        $response = $gateway->verify(['transaction_ref' => $transactionRef]);

        if ($response->success && $response->status === 'completed') {
            // Payment successful
            return view('payment.success', [
                'transaction_id' => $response->transactionId,
                'amount' => $response->data['amount'],
                'currency' => $response->data['currency'],
                'payment_method' => $response->data['payment_method']
            ]);
        }

        // Payment not completed yet
        return redirect()->route('payment.pending')
            ->with('transaction_ref', $transactionRef);
    }

    /**
     * Handle cancelled payment
     */
    public function handleCancel(Request $request)
    {
        $cartId = $request->get('cart');

        // Log cancellation
        Log::info('Payment cancelled', ['cart_id' => $cartId]);

        return redirect()->route('checkout')
            ->with('message', 'Payment was cancelled. Please try again.');
    }
}
```

## Testing

### 1. Test Environment

Telr provides a test environment for development:

```env
TELR_TEST_MODE=true
```

### 2. Test Cards

Use these test cards for testing:

| Card Type | Number | Expiry | CVV | Result |
|-----------|--------|--------|-----|---------|
| Visa Success | 4111111111111111 | Any future | Any | Success |
| Visa Fail | 4000000000000002 | Any future | Any | Failure |
| Mastercard | 5555555555554444 | Any future | Any | Success |

### 3. Test Payment

```php
// Create test payment
$testPayment = Payment::gateway('telr')->pay(new PaymentRequest(
    amount: 1.00,
    currency: 'AED',
    orderId: 'TEST_' . time(),
    description: 'Test payment',
    customer: [
        'name' => 'Test User',
        'email' => 'test@example.com',
    ],
    returnUrl: 'https://example.com/test/success',
    cancelUrl: 'https://example.com/test/cancel'
));
```

## Security Considerations

### 1. PCI Compliance

- Using Telr's hosted payment page: No PCI compliance required
- Using direct API: Full PCI DSS compliance required

### 2. Data Security

- Never store full card details
- Use HTTPS for all communications
- Validate all input data
- Implement rate limiting

### 3. Webhook Security

```php
// Always verify webhook signatures
$webhookData = $request->all();
$receivedHash = $request->header('X-Telr-Signature');

// Verify using gateway method
$isValid = $gateway->verifyWebhookSignature($webhookData, $receivedHash);
```

## Error Handling

### Common Error Codes

| Code | Description | Solution |
|------|-------------|----------|
| 400 | Bad Request | Check request parameters |
| 401 | Unauthorized | Verify store ID and auth key |
| 403 | Forbidden | Check IP whitelist settings |
| 404 | Not Found | Verify endpoint URL |
| 500 | Server Error | Retry with exponential backoff |

### Error Handling Example

```php
try {
    $response = $payment->pay($paymentRequest);

    if (!$response->success) {
        // Log error details
        Log::error('Telr payment failed', [
            'code' => $response->errorCode,
            'message' => $response->message,
            'order_id' => $paymentRequest->orderId
        ]);

        // Show user-friendly message
        return back()->with('error', 'Payment failed. Please try again.');
    }
} catch (\Exception $e) {
    Log::error('Telr gateway error', [
        'error' => $e->getMessage()
    ]);

    return back()->with('error', 'Payment service temporarily unavailable.');
}
```

## Best Practices

### 1. Transaction Management

- Always use unique order IDs
- Store transaction references for refunds
- Implement timeout handling
- Log all transactions

### 2. Currency Handling

- Always specify currency
- Display amounts with proper formatting
- Handle currency conversion if needed
- Support local currencies for better conversion

### 3. Customer Experience

- Clear payment instructions
- Multiple language support
- Mobile-optimized payment pages
- Progress indicators

### 4. Retry Logic

```php
// Implement exponential backoff for retries
$maxRetries = 3;
$retryDelay = 1; // seconds

for ($i = 0; $i < $maxRetries; $i++) {
    $response = $payment->verify(['transaction_ref' => $ref]);

    if ($response->success || $i === $maxRetries - 1) {
        break;
    }

    sleep($retryDelay * pow(2, $i)); // Exponential backoff
}
```

## Rate Limits

- Standard API: 100 requests per minute
- Webhook processing: No limit
- Status checks: 1000 requests per hour
- Implement proper rate limiting and backoff

## Support

- Telr Support Portal: https://support.telr.com/
- Email: support@telr.com
- Phone: +971 4 446 2620
- Documentation: https://docs.telr.com/

## Troubleshooting

### Common Issues

1. **Session Creation Failed**
   - Check store ID and auth key
   - Verify test/live mode settings
   - Ensure proper currency format

2. **Webhook Not Received**
   - Check webhook URL accessibility
   - Verify HTTPS is enabled
   - Check firewall settings

3. **Payment Status Issues**
   - Wait a few seconds after payment
   - Use transaction reference for status check
   - Implement retry logic

4. **Refund Failures**
   - Check if transaction is settled
   - Verify refund amount limits
   - Ensure sufficient balance

### Debug Mode

Enable debug logging:

```php
// config/payments.php
'telr' => [
    // ... other config
    'debug' => env('APP_DEBUG', false),
],
```

This will log all Telr requests and responses for debugging purposes.