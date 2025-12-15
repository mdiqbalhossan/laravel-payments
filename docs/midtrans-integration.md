# Midtrans Integration Guide

## Overview

Midtrans is Indonesia's leading payment gateway solution that provides comprehensive payment processing services across Southeast Asia. This integration supports multiple payment methods including credit/debit cards, e-wallets (GoPay, ShopeePay), bank transfers, QRIS, and convenience store payments, making it ideal for businesses operating in Indonesia and the broader Southeast Asian market.

## Features

- **Multiple Payment Methods**: 25+ payment options including credit/debit cards, e-wallets, bank transfers
- **Snap Integration**: Modern, responsive payment popup with customizable appearance
- **Payment Links**: Generate shareable payment links for invoicing
- **Multi-Currency Support**: IDR, USD, SGD, MYR, and more
- **Real-time Processing**: Instant payment authorization and verification
- **Secure Authentication**: SHA512 signature verification for webhooks
- **Fraud Detection**: Built-in fraud detection and prevention features
- **Mobile-First**: Optimized for mobile payments and Indonesian market preferences

## Supported Countries

| Country | Currency | Code | Primary Payment Methods |
|---------|----------|------|-----------------------|
| Indonesia | Indonesian Rupiah | IDR | GoPay, ShopeePay, QRIS, Bank Transfer, Credit Cards |
| Singapore | Singapore Dollar | SGD | Credit/Debit Cards, PayNow |
| Malaysia | Malaysian Ringgit | MYR | Credit/Debit Cards, FPX |
| Vietnam | Vietnamese Dong | VND | Credit/Debit Cards, MoMo |
| Philippines | Philippine Peso | PHP | Credit/Debit Cards, GCash |
| Thailand | Thai Baht | THB | Credit/Debit Cards, PromptPay |

## Supported Payment Methods

| Method | Code | Description |
|--------|------|-------------|
| Credit/Debit Card | credit_card | Visa, Mastercard, JCB, Amex |
| GoPay | gopay | GoJek's digital wallet |
| ShopeePay | shopeepay | Shopee's digital wallet |
| QRIS | qris | Indonesia's QR code payment standard |
| Bank Transfer | bank_transfer | Virtual account transfers |
| BCA KlikPay | bca_klikpay | BCA's online payment platform |
| BCA KlikBCA | bca_klikbca | BCA's internet banking |
| BRI e-Pay | bri_epay | BRI's online payment |
| Indomaret | indomaret | Convenience store payment |
| Alfamart | alfamart | Convenience store payment |

## Configuration

### 1. Install Package

```bash
composer require midtrans/midtrans-php
```

### 2. Environment Variables

Add these variables to your `.env` file:

```env
# Midtrans Configuration
MIDTRANS_SERVER_KEY=YOUR_SERVER_KEY
MIDTRANS_CLIENT_KEY=YOUR_CLIENT_KEY
MIDTRANS_TEST_MODE=true
MIDTRANS_WEBHOOK_URL=https://yourapp.com/payment/midtrans/webhook
MIDTRANS_RETURN_URL=https://yourapp.com/payment/midtrans/success
```

### 3. Publish Configuration

```bash
php artisan vendor:publish --provider="Mdiqbal\LaravelPayments\PaymentsServiceProvider"
```

### 4. Update Config File

```php
// config/payments.php
'gateways' => [
    'midtrans' => [
        'driver' => 'midtrans',
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'test_mode' => env('MIDTRANS_TEST_MODE', true),
        'webhook_url' => env('MIDTRANS_WEBHOOK_URL'),
        'return_url' => env('MIDTRANS_RETURN_URL'),
        'enabled_payments' => [
            'credit_card',
            'gopay',
            'shopeepay',
            'bank_transfer',
            'qris',
        ],
    ],
],
```

## Midtrans Account Setup

### 1. Create Midtrans Account

1. [Register on Midtrans](https://midtrans.com/register)
2. Complete the merchant registration form
3. Submit business documents (Business License, Tax ID, ID)
4. Wait for approval (typically 1-2 business days)

### 2. Get API Credentials

Once approved:
1. Log into Midtrans Dashboard
2. Navigate to Settings > Integration Settings
3. Note down:
   - Server Key (for server-side operations)
   - Client Key (for client-side operations)
   - Sandbox/Production environment settings

### 3. Configure Webhooks

In your Midtrans dashboard:
1. Go to Settings > Integration Settings
2. Add your webhook URL: `https://yourapp.com/payment/midtrans/webhook`
3. Enable notifications for:
   - Payment Success
   - Payment Failed
   - Payment Challenge
   - Payment Cancelled

## Usage Examples

### 1. Basic Payment Processing with Snap

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTOs\PaymentRequest;

// Initialize Midtrans gateway
$payment = Payment::gateway('midtrans');

// Create a payment request
$response = $payment->pay(new PaymentRequest(
    amount: 50000, // IDR 50,000
    currency: 'IDR',
    orderId: 'ORDER-' . uniqid(),
    description: 'Product purchase',
    customer: [
        'name' => 'Budi Santoso',
        'email' => 'budi@example.com',
        'phone' => '+628123456789',
        'address' => 'Jl. Sudirman No. 123',
        'city' => 'Jakarta',
        'country' => 'IDN',
        'postal_code' => '12345',
    ],
    returnUrl: route('payment.success'),
    notifyUrl: route('payment.webhook'),
    metadata: [
        'credit_card' => [
            'secure' => true,
            'bank' => 'bca',
            'installment' => [
                'required' => false,
            ],
        ],
        'items' => [
            [
                'id' => 'PROD1',
                'price' => 30000,
                'quantity' => 1,
                'name' => 'Product A',
                'brand' => 'Brand A',
                'category' => 'Electronics',
                'merchant_name' => 'Your Store',
                'url' => 'https://yourstore.com/product1',
                'image_url' => 'https://yourstore.com/images/product1.jpg',
            ],
            [
                'id' => 'PROD2',
                'price' => 20000,
                'quantity' => 1,
                'name' => 'Product B',
                'brand' => 'Brand B',
                'category' => 'Accessories',
                'merchant_name' => 'Your Store',
                'url' => 'https://yourstore.com/product2',
                'image_url' => 'https://yourstore.com/images/product2.jpg',
            ]
        ],
    ]
));

if ($response->success) {
    // Store the snap token for later use
    session(['snap_token' => $response->data['snap_token']]);

    // Redirect to Midtrans payment page
    return redirect($response->redirectUrl);
} else {
    // Handle error
    return back()->with('error', $response->message);
}
```

### 2. Payment with Specific Payment Methods

```php
// Limit payment options to GoPay and ShopeePay only
$response = $payment->pay(new PaymentRequest(
    amount: 25000,
    currency: 'IDR',
    orderId: 'EWALLET-' . uniqid(),
    description: 'E-wallet payment',
    customer: [
        'name' => 'Siti Nurhaliza',
        'email' => 'siti@example.com',
        'phone' => '+628987654321',
    ],
    metadata: [
        'credit_card' => [
            'secure' => true,
        ],
        'expiry' => [
            'unit' => 'minutes',
            'duration' => 30,
        ],
    ]
));

// In your Midtrans configuration, set:
// 'enabled_payments' => ['gopay', 'shopeepay']
```

### 3. Payment Link Creation

```php
$linkResponse = $payment->createPaymentLink([
    'amount' => 100000,
    'order_id' => 'INVOICE-' . time(),
    'currency' => 'IDR',
    'description' => 'Monthly subscription fee',
    'customer_name' => 'Ahmad Fadli',
    'customer_email' => 'ahmad@example.com',
    'customer_phone' => '+6281122334455',
    'expiry_duration' => 72, // 72 hours
    'items' => [
        [
            'id' => 'SUBSCRIPTION',
            'price' => 100000,
            'quantity' => 1,
            'name' => 'Monthly Subscription',
            'category' => 'Service',
        ]
    ],
    'customer_address' => [
        'first_name' => 'Ahmad',
        'last_name' => 'Fadli',
        'address' => 'Jl. Gatot Subroto No. 456',
        'city' => 'Bandung',
        'postal_code' => '40123',
        'country_code' => 'IDN',
    ],
    'redirect_url' => route('payment.success'),
    'callback_url' => route('payment.webhook'),
]);

if ($linkResponse->success) {
    $paymentUrl = $linkResponse->redirectUrl;
    // Send payment link via WhatsApp, email, or SMS
    // Example: WhatsApp share link
    $whatsappUrl = 'https://wa.me/?text=' . urlencode(
        "Halo! Silakan lakukan pembayaran Anda melalui link berikut: " . $paymentUrl
    );

    return redirect($whatsappUrl);
}
```

### 4. Payment Status Check

```php
// Check payment status using order ID
$orderId = 'ORDER-123456789';
$response = $payment->verify(['order_id' => $orderId]);

if ($response->success) {
    echo "Payment Status: " . $response->status;
    echo "Transaction ID: " . $response->transactionId;
    echo "Payment Type: " . $response->data['payment_type'];
    echo "Gross Amount: " . $response->data['gross_amount'];
    echo "Currency: " . $response->data['currency'];
    echo "Fraud Status: " . $response->data['fraud_status'];

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
    'transaction_id' => 'MIDTRANS-123456789',
    'amount' => 25000, // Partial refund or null for full refund
    'reason' => 'Customer requested refund'
]);

if ($refundResponse->success) {
    echo "Refund processed successfully";
    echo "Refund Key: " . $refundResponse->data['refund_key'];
    echo "Refund Amount: " . $refundResponse->data['refund_amount'];
}
```

### 6. Handling Different Payment Types

```php
// GoPay specific configuration
$payment->pay(new PaymentRequest(
    amount: 50000,
    currency: 'IDR',
    orderId: 'GOPAY-' . uniqid(),
    customer: [
        'name' => 'Customer Name',
        'phone' => '+628123456789', // Required for GoPay
    ],
    metadata: [
        'items' => [
            [
                'id' => 'ITEM1',
                'price' => 50000,
                'quantity' => 1,
                'name' => 'Product',
                'category' => 'Digital Goods',
                'url' => 'https://yourstore.com/product',
                'image_url' => 'https://yourstore.com/images/product.jpg',
            ]
        ],
        'credit_card' => [
            'save_card' => false,
            'secure' => true,
        ],
        'enabled_payments' => ['gopay'],
    ]
));
```

## Webhook Handling

### 1. Create Webhook Route

```php
// routes/web.php
Route::post('/payment/midtrans/webhook', [MidtransController::class, 'handleWebhook'])
    ->name('payment.midtrans.webhook')
    ->middleware('midtrans.webhook');
```

### 2. Webhook Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class MidtransController extends Controller
{
    /**
     * Handle Midtrans webhook
     */
    public function handleWebhook(Request $request)
    {
        $gateway = Payment::gateway('midtrans');

        // Process webhook
        $response = $gateway->processWebhook($request->all());

        if ($response->success) {
            // Extract webhook data
            $webhookData = $response->data;
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
            ->where('order_id', $data['order_id'])
            ->update([
                'status' => 'completed',
                'midtrans_transaction_id' => $data['transaction_id'],
                'payment_type' => $data['payment_type'] ?? null,
                'gross_amount' => $data['gross_amount'] ?? 0,
                'currency' => $data['currency'] ?? 'IDR',
                'fraud_status' => $data['fraud_status'] ?? null,
                'approval_code' => $data['approval_code'] ?? null,
                'bank' => $data['bank'] ?? null,
                'masked_card' => $data['masked_card'] ?? null,
                'card_type' => $data['card_type'] ?? null,
                'settlement_time' => $data['settlement_time'] ?? null,
                'paid_at' => now()
            ]);

        // Update order status
        DB::table('orders')
            ->where('id', $data['order_id'])
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
            ->where('order_id', $data['order_id'])
            ->update([
                'status' => 'pending',
                'pending_at' => now()
            ]);

        // Log pending payment
        // Send follow-up email
    }

    /**
     * Handle failed payment
     */
    private function handleFailedPayment(array $data)
    {
        DB::table('payments')
            ->where('order_id', $data['order_id'])
            ->update([
                'status' => 'failed',
                'failure_reason' => $data['status_message'] ?? 'Payment failed',
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
            ->where('order_id', $data['order_id'])
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

class MidtransWebhook
{
    public function handle(Request $request, Closure $next)
    {
        // Log webhook for debugging
        Log::info('Midtrans webhook received', [
            'ip' => $request->ip(),
            'payload' => $request->all()
        ]);

        // You can add IP filtering here if needed
        $allowedIps = [
            '103.20.50.155', // Midtrans IP
            '103.20.50.157', // Midtrans IP
            // Add more IPs as provided by Midtrans
        ];

        if (!in_array($request->ip(), $allowedIps)) {
            Log::warning('Unauthorized webhook attempt', [
                'ip' => $request->ip()
            ]);
            abort(403, 'Unauthorized');
        }

        return $next($request);
    }
}
```

## Frontend Integration

### 1. Basic Snap Implementation

```html
<!DOCTYPE html>
<html>
<head>
    <title>Payment with Midtrans</title>
    <script src="https://app.sandbox.midtrans.com/snap/snap.js"
            data-client-key="{{ $clientKey }}"></script>
    <style>
        .payment-button {
            background-color: #4CAF50;
            color: white;
            padding: 14px 20px;
            border: none;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
        }

        .payment-button:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
    <h2>Complete Your Payment</h2>
    <p>Amount: Rp {{ number_format($amount, 0, ',', '.') }}</p>

    <button id="pay-button" class="payment-button">Pay Now</button>

    <script>
        document.getElementById('pay-button').onclick = function() {
            snap.pay('{{ $snapToken }}', {
                onSuccess: function(result) {
                    console.log('Payment success:', result);
                    window.location.href = '{{ route("payment.success") }}';
                },
                onPending: function(result) {
                    console.log('Payment pending:', result);
                    window.location.href = '{{ route("payment.pending") }}';
                },
                onError: function(result) {
                    console.log('Payment error:', result);
                    window.location.href = '{{ route("payment.error") }}';
                },
                onClose: function() {
                    console.log('Customer closed the popup without finishing the payment');
                    window.location.href = '{{ route("payment.cancel") }}';
                }
            });
        };
    </script>
</body>
</html>
```

### 2. Using Midtrans Snap in React/Vue

```javascript
// Example in Vue.js
export default {
    methods: {
        payWithMidtrans() {
            const snapScript = document.createElement('script');
            snapScript.src = 'https://app.sandbox.midtrans.com/snap/snap.js';
            snapScript.setAttribute('data-client-key', this.clientKey);
            snapScript.onload = () => {
                window.snap.pay(this.snapToken, {
                    onSuccess: (result) => {
                        this.$emit('payment-success', result);
                    },
                    onPending: (result) => {
                        this.$emit('payment-pending', result);
                    },
                    onError: (result) => {
                        this.$emit('payment-error', result);
                    }
                });
            };
            document.head.appendChild(snapScript);
        }
    }
}
```

## Testing

### 1. Test Environment

Midtrans provides a sandbox environment:

```env
MIDTRANS_TEST_MODE=true
```

### 2. Test Credentials

Get test credentials from Midtrans:
1. Request sandbox access from Midtrans support
2. Use provided test Server Key and Client Key
3. Use sandbox URLs for testing

### 3. Test Cards

Use these test cards for testing:

| Card Type | Number | Expiry | CVV | Result |
|-----------|---------|--------|-----|---------|
| Visa Success | 4811111111111114 | Any future | Any | Success |
| Visa 3DS | 4811111111111116 | Any future | Any | Challenge |
| Visa Failure | 4811111111111115 | Any future | Any | Denied |
| Mastercard | 5111111111111116 | Any future | Any | Success |
| JCB | 3511111111111116 | Any future | Any | Success |

### 4. Test Payment Scenarios

```php
// Test GoPay payment
$testGoPay = $payment->pay(new PaymentRequest(
    amount: 10000,
    currency: 'IDR',
    orderId: 'TEST-GOPAY-' . time(),
    description: 'Test GoPay payment',
    customer: [
        'name' => 'Test User',
        'phone' => '+628123456789',
    ],
    metadata: [
        'enabled_payments' => ['gopay'],
    ]
));
```

## Security Considerations

### 1. Webhook Security

- Verify webhook signatures using SHA512
- Implement IP whitelisting for Midtrans servers
- Use HTTPS for all endpoints
- Validate all incoming data

### 2. Client Key Protection

```php
// Never expose server-side keys to frontend
// Use client_key in JavaScript, server_key in backend

// Good: In Blade template
<script src="https://app.sandbox.midtrans.com/snap/snap.js"
        data-client-key="{{ config('services.midtrans.client_key') }}"></script>

// Bad: Exposing server key
<script>
    const serverKey = '{{ config('services.midtrans.server_key') }}'; // NEVER DO THIS
</script>
```

### 3. Data Validation

```php
// Always validate order amounts before payment
if ($order->total != $request->amount) {
    abort(403, 'Invalid amount');
}

// Verify order ownership
if ($order->user_id != auth()->id()) {
    abort(403, 'Unauthorized');
}
```

## Error Handling

### Common Error Codes

| Code | Description | Solution |
|------|-------------|----------|
| 400 | Bad Request | Check request parameters |
| 401 | Unauthorized | Verify Server Key |
| 402 | Duplicate Order ID | Use unique order IDs |
| 404 | Not Found | Check transaction status |
| 410 | Expired | Create new payment |
| 411 | Invalid Currency | Use supported currency |
| 412 | Invalid Amount | Check amount format |

### Error Handling Example

```php
try {
    $response = $payment->pay($paymentRequest);

    if (!$response->success) {
        // Log error details
        Log::error('Midtrans payment failed', [
            'error_code' => $response->errorCode,
            'message' => $response->message,
            'order_id' => $paymentRequest->orderId
        ]);

        // Show user-friendly message
        $userMessage = $this->getUserFriendlyErrorMessage($response->errorCode);
        return back()->with('error', $userMessage);
    }
} catch (\Exception $e) {
    Log::error('Midtrans gateway error', [
        'error' => $e->getMessage()
    ]);

    return back()->with('error', 'Payment service temporarily unavailable.');
}

private function getUserFriendlyErrorMessage(string $errorCode): string
{
    $errorMessages = [
        '410' => 'Payment link has expired. Please try again.',
        '411' => 'Currency not supported. Please use IDR.',
        '412' => 'Invalid amount format. Please check the amount.',
        '413' => 'Invalid payment method. Please try another option.',
        '414' => 'Card not authorized. Please use another card.',
    ];

    return $errorMessages[$errorCode] ?? 'Payment failed. Please try again.';
}
```

## Best Practices

### 1. Transaction Management

- Always use unique order IDs
- Store transaction references for verification
- Implement proper error handling
- Log all transactions

### 2. Customer Experience

- Show loading indicators during payment
- Provide clear error messages
- Support mobile-first design
- Show payment method icons

### 3. Performance Optimization

```php
// Cache payment methods information
$paymentMethods = Cache::remember('midtrans_payment_methods', 3600, function () {
    return [
        'credit_card' => ['name' => 'Credit/Debit Card', 'icon' => 'credit-card'],
        'gopay' => ['name' => 'GoPay', 'icon' => 'gopay'],
        'shopeepay' => ['name' => 'ShopeePay', 'icon' => 'shopeepay'],
        // ... other methods
    ];
});

// Use CDN for static assets
<script src="https://app.sandbox.midtrans.com/snap/snap.js"></script>
```

### 4. Mobile Optimization

- Ensure Snap popup works on mobile
- Test on various mobile browsers
- Optimize loading times
- Consider using Midtrans mobile SDK for native apps

## Localization

Midtrans supports Indonesian and English:

```php
// Set language in transaction data
$transactionData['language'] = 'id'; // Indonesian
// or
$transactionData['language'] = 'en'; // English
```

## Rate Limits

- Standard API: 100 requests per minute
- Snap API: No specific limit (reasonable usage expected)
- Status check: 1000 requests per hour
- Implement proper rate limiting

## Support

- Midtrans Documentation: https://docs.midtrans.com/
- Dashboard: https://dashboard.midtrans.com/
- Email: support@midtrans.com
- WhatsApp: +62 812-1500-3522
- Live Chat: Available on website

## Troubleshooting

### Common Issues

1. **Snap Not Loading**
   - Check if Snap script is properly loaded
   - Verify client key is correct
   - Check browser console for errors

2. **Payment Not Redirecting**
   - Verify callback URLs
   - Check if return URL is accessible
   - Ensure HTTPS is used

3. **Webhook Not Received**
   - Check webhook URL accessibility
   - Verify IP whitelist settings
   - Check firewall configuration

4. **Currency Issues**
   - Use IDR for Indonesian transactions
   - For international, check supported currencies
   - Ensure decimal points are handled correctly

### Debug Mode

Enable debug logging:

```php
// config/payments.php
'midtrans' => [
    // ... other config
    'debug' => env('APP_DEBUG', false),
],
```

This will log all Midtrans requests and responses for debugging purposes.