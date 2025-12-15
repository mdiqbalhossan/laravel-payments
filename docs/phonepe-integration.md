# PhonePe Integration Guide

## Overview

PhonePe is one of India's leading digital payment platforms that supports UPI, credit/debit cards, net banking, and other payment methods. This gateway integrates seamlessly with the Laravel Payments package to provide Indian users with a comprehensive payment solution.

## Features

- **Multiple Payment Methods**: UPI, Credit/Debit Cards, Net Banking, Paytm Wallet, and more
- **Instant Refunds**: Process refunds directly through PhonePe's API
- **Real-time Status**: Check transaction status in real-time
- **Webhook Support**: Secure webhook notifications for payment events
- **Secure Authentication**: X-VERIFY header based authentication
- **Indian Currency**: Native support for INR

## Supported Payment Methods

| Method | Code | Description |
|--------|------|-------------|
| UPI Collect | `UPI_COLLECT` | UPI payment collection |
- UPI Intent | `UPI_INTENT` | UPI payment intent |
| Credit Card | `CARD` | Credit and Debit Card payments |
| Net Banking | `NETBANKING` | Internet banking |
| Paytm Wallet | `PAYTM` | Paytm wallet payments |
| PhonePe Wallet | `PHONEPE` | PhonePe wallet payments |
| Google Pay | `GOOGLEPAY` | Google Pay UPI payments |

## Configuration

### 1. Environment Variables

Add these variables to your `.env` file:

```env
# PhonePe Configuration
PHONEPE_ENVIRONMENT=UAT
PHONEPE_MERCHANT_ID=YOUR_MERCHANT_ID
PHONEPE_SALT_KEY=YOUR_SALT_KEY
PHONEPE_SALT_INDEX=1
PHONEPE_REDIRECT_URL=https://yourapp.com/payment/phonepe/callback
PHONEPE_CALLBACK_URL=https://yourapp.com/payment/phonepe/webhook
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="Mdiqbal\LaravelPayments\PaymentsServiceProvider"
```

### 3. Update Config File

```php
// config/payments.php
'gateways' => [
    'phonepe' => [
        'driver' => 'phonepe',
        'environment' => env('PHONEPE_ENVIRONMENT', 'UAT'),
        'merchant_id' => env('PHONEPE_MERCHANT_ID'),
        'salt_key' => env('PHONEPE_SALT_KEY'),
        'salt_index' => env('PHONEPE_SALT_INDEX', 1),
        'redirect_url' => env('PHONEPE_REDIRECT_URL'),
        'callback_url' => env('PHONEPE_CALLBACK_URL'),
        'success_url' => env('PHONEPE_SUCCESS_URL'),
        'failure_url' => env('PHONEPE_FAILURE_URL'),
    ],
],
```

## PhonePe Account Setup

### 1. Get PhonePe Credentials

1. [Register on PhonePe Dashboard](https://dashboard.phonepe.com/)
2. Complete the KYC process
3. Create a new application
4. Note down:
   - Merchant ID
   - Salt Key
   - Salt Index
   - Environment (UAT for testing, PROD for production)

### 2. Configure Webhooks

In your PhonePe dashboard:
1. Navigate to Webhook Settings
2. Add your webhook URL: `https://yourapp.com/payment/phonepe/webhook`
3. Select the events you want to receive:
   - Payment Success
   - Payment Failure
   - Refund Status

## Usage Examples

### 1. Basic Payment Processing

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

// Initialize PhonePe gateway
$payment = Payment::gateway('phonepe');

// Create a payment request
$response = $payment->pay([
    'merchantTransactionId' => 'TXN' . uniqid(),
    'merchantUserId' => 'USER123',
    'amount' => 10000, // Amount in paise (10000 paise = ₹100)
    'redirectUrl' => route('payment.callback'),
    'redirectMode' => 'REDIRECT',
    'callbackUrl' => route('payment.webhook'),
    'paymentInstrument' => [
        'type' => 'UPI_COLLECT',
        'vpa' => 'user@upi' // Optional for UPI collect
    ],
]);

if ($response->success) {
    // Redirect to PhonePe payment page
    return redirect($response->redirectUrl);
} else {
    // Handle error
    return back()->with('error', $response->message);
}
```

### 2. Card Payment

```php
$response = $payment->pay([
    'merchantTransactionId' => 'TXN' . uniqid(),
    'merchantUserId' => 'USER123',
    'amount' => 10000,
    'redirectUrl' => route('payment.callback'),
    'redirectMode' => 'REDIRECT',
    'callbackUrl' => route('payment.webhook'),
    'paymentInstrument' => [
        'type' => 'CARD',
        'cardNumber' => '4111111111111111', // Test card
        'expiryMonth' => '12',
        'expiryYear' => '25',
        'cardHolderName' => 'Test User',
        'cvv' => '123'
    ],
]);
```

### 3. UPI Intent Payment

```php
$response = $payment->pay([
    'merchantTransactionId' => 'TXN' . uniqid(),
    'merchantUserId' => 'USER123',
    'amount' => 10000,
    'redirectUrl' => route('payment.callback'),
    'redirectMode' => 'REDIRECT',
    'callbackUrl' => route('payment.webhook'),
    'paymentInstrument' => [
        'type' => 'UPI_INTENT',
        'targetApp' => 'com.phonepe.app' // or other UPI apps
    ],
]);
```

### 4. Transaction Status Check

```php
// Check payment status
$transactionId = 'TXN123456789';
$response = $payment->verify([
    'merchantTransactionId' => $transactionId
]);

if ($response->success) {
    echo "Payment Status: " . $response->status;
    echo "Amount: " . ($response->amount / 100); // Convert paise to rupees
    echo "Payment Method: " . $response->paymentInstrument->type;
}
```

### 5. Process Refunds

```php
// Process a refund
$refundResponse = $payment->refund([
    'merchantTransactionId' => 'TXN123456789',
    'originalTransactionId' => 'ORIG_TXN123',
    'amount' => 5000, // Refund amount in paise
    'refundReason' => 'Customer requested refund'
]);

if ($refundResponse->success) {
    echo "Refund ID: " . $refundResponse->refundTransactionId;
}
```

### 6. Check Refund Status

```php
// Check refund status
$refundStatus = $payment->verify([
    'merchantTransactionId' => 'TXN123456789',
    'type' => 'refund'
]);

if ($refundStatus->success) {
    echo "Refund Status: " . $refundStatus->status;
    echo "Refund Amount: " . ($refundStatus->amount / 100);
}
```

## Webhook Handling

### 1. Create Webhook Route

```php
// routes/web.php
Route::post('/payment/phonepe/webhook', [PhonePeController::class, 'handleWebhook'])
    ->name('payment.phonepe.webhook');
```

### 2. Webhook Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class PhonePeController extends Controller
{
    /**
     * Handle PhonePe webhook
     */
    public function handleWebhook(Request $request)
    {
        $gateway = Payment::gateway('phonepe');

        // Verify webhook signature
        $response = $gateway->processWebhook($request->all());

        if ($response->success) {
            // Extract webhook data
            $webhookData = $response->data;

            // Process based on event type
            switch ($webhookData['type']) {
                case 'PAYMENT_SUCCESS':
                    $this->handlePaymentSuccess($webhookData);
                    break;

                case 'PAYMENT_FAILED':
                    $this->handlePaymentFailure($webhookData);
                    break;

                case 'REFUND_STATUS':
                    $this->handleRefundStatus($webhookData);
                    break;
            }

            return response('Webhook processed successfully');
        }

        return response('Invalid webhook signature', 400);
    }

    /**
     * Handle successful payment
     */
    private function handlePaymentSuccess(array $data)
    {
        $paymentData = $data['data']['paymentInstrument'];

        // Update your database
        DB::table('payments')
            ->where('transaction_id', $data['data']['merchantTransactionId'])
            ->update([
                'status' => 'completed',
                'phonepe_transaction_id' => $data['data']['transactionId'],
                'payment_method' => $paymentData['type'],
                'amount_paid' => $data['data']['amount'] / 100,
                'paid_at' => now()
            ]);
    }

    /**
     * Handle failed payment
     */
    private function handlePaymentFailure(array $data)
    {
        DB::table('payments')
            ->where('transaction_id', $data['data']['merchantTransactionId'])
            ->update([
                'status' => 'failed',
                'failure_reason' => $data['data']['responseCode'],
                'failed_at' => now()
            ]);
    }

    /**
     * Handle refund status update
     */
    private function handleRefundStatus(array $data)
    {
        DB::table('refunds')
            ->where('refund_transaction_id', $data['data']['merchantTransactionId'])
            ->update([
                'status' => $data['data']['status'],
                'processed_at' => now()
            ]);
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
     * Handle payment callback from PhonePe
     */
    public function handleCallback(Request $request)
    {
        $transactionId = $request->get('transactionId');

        // Verify transaction status with PhonePe
        $gateway = Payment::gateway('phonepe');
        $response = $gateway->verify(['transactionId' => $transactionId]);

        if ($response->success && $response->status === 'SUCCESS') {
            // Payment successful
            return redirect()->route('payment.success')
                ->with('transaction_id', $response->merchantTransactionId);
        } else {
            // Payment failed
            return redirect()->route('payment.failure')
                ->with('error', $response->message ?? 'Payment failed');
        }
    }
}
```

## Testing

### 1. Test Environment

PhonePe provides a UAT (User Acceptance Testing) environment for testing:

```env
PHONEPE_ENVIRONMENT=UAT
```

### 2. Test Credentials

Use these test values for UAT environment:
- Test UPI ID: `test@phonepe`
- Test Card: `4111111111111111`
- Test Expiry: Any future date
- Test CVV: `123`

### 3. Testing Payment Flow

```php
// Test payment creation
$testPayment = Payment::gateway('phonepe')->pay([
    'merchantTransactionId' => 'TEST_' . time(),
    'merchantUserId' => 'TEST_USER',
    'amount' => 100, // ₹1 in paise
    'redirectUrl' => 'https://example.com/test/callback',
    'callbackUrl' => 'https://example.com/test/webhook',
    'paymentInstrument' => [
        'type' => 'UPI_COLLECT',
        'vpa' => 'test@phonepe'
    ],
]);
```

## Security Considerations

### 1. Signature Verification

PhonePe uses X-VERIFY header authentication. The gateway automatically:
- Generates SHA256 hash of request payload
- Appends salt key with separator
- Includes in X-VERIFY header
- Verifies webhook signatures

### 2. Sensitive Data

- Never store card details on your server
- Use HTTPS for all communications
- Validate all incoming data
- Log all transactions for audit

### 3. Webhook Security

```php
// Verify webhook is from PhonePe
$webhookHeaders = getallheaders();
$xVerify = $webhookHeaders['X-VERIFY'] ?? null;
$webhookBody = file_get_contents('php://input');

// The gateway handles verification automatically
$gateway = Payment::gateway('phonepe');
$isValid = $gateway->verifyWebhookSignature($webhookBody, $xVerify);
```

## Error Handling

### Common Error Codes

| Code | Description | Solution |
|------|-------------|----------|
| BAD_REQUEST | Invalid request parameters | Check request format |
| AUTHORIZATION_FAILED | Invalid credentials | Verify merchant ID and salt key |
| INTERNAL_SERVER_ERROR | PhonePe server error | Retry after delay |
| TRANSACTION_NOT_FOUND | Invalid transaction ID | Verify transaction ID |
| TIMEOUT | Request timeout | Implement retry logic |

### Error Handling Example

```php
try {
    $response = $payment->pay($paymentData);

    if (!$response->success) {
        // Log error details
        Log::error('PhonePe payment failed', [
            'code' => $response->code,
            'message' => $response->message,
            'transaction_id' => $paymentData['merchantTransactionId']
        ]);

        // Show user-friendly message
        return back()->with('error', 'Payment failed. Please try again.');
    }
} catch (\Exception $e) {
    Log::error('PhonePe gateway error', [
        'error' => $e->getMessage()
    ]);

    return back()->with('error', 'Payment service unavailable. Please try later.');
}
```

## Best Practices

### 1. Transaction IDs

- Always use unique transaction IDs
- Include timestamp to avoid collisions
- Store original transaction ID for refunds
- Maximum length: 35 characters

### 2. Amount Handling

- Always use amounts in paise (integer)
- Convert for display: `amount / 100`
- Validate minimum/maximum amounts
- Handle currency conversion if needed

### 3. Redirect Flow

```php
// Store payment details before redirect
Session::put('phonepe_payment', [
    'transaction_id' => $transactionId,
    'amount' => $amount,
    'user_id' => auth()->id()
]);

// Redirect to PhonePe
return redirect($paymentUrl);
```

### 4. Status Polling

```php
// Poll for payment status if needed
$maxRetries = 10;
$retryDelay = 2; // seconds

for ($i = 0; $i < $maxRetries; $i++) {
    $status = $payment->verify(['transactionId' => $transactionId]);

    if ($status->status !== 'PENDING') {
        break;
    }

    sleep($retryDelay);
}
```

## Rate Limits

- Standard Rate Limit: 1000 requests per minute
- Refund API: 100 requests per minute
- Status Check: 1000 requests per minute
- Implement exponential backoff for retries

## Support

- PhonePe Merchant Dashboard: https://dashboard.phonepe.com/
- API Documentation: https://developer.phonepe.com/
- Support Email: support@phonepe.com
- Merchant Helpline: 022-6826-5824

## Troubleshooting

### Common Issues

1. **X-VERIFY Header Error**
   - Check salt key and index
   - Verify string concatenation format
   - Ensure UTF-8 encoding

2. **Transaction Not Found**
   - Wait 2-3 seconds after payment
   - Check if correct transaction ID is used
   - Verify environment (UAT/PROD)

3. **Webhook Not Received**
   - Check webhook URL accessibility
   - Verify HTTPS is enabled
   - Check firewall settings

4. **Card Payment Failed**
   - Ensure 3D Secure is enabled
   - Check card expiry
   - Verify CVV is correct

### Debug Mode

Enable debug logging to troubleshoot:

```php
// config/payments.php
'phonepe' => [
    // ... other config
    'debug' => env('APP_DEBUG', false),
],
```

This will log all PhonePe requests and responses for debugging purposes.