# Pesapal Integration Guide

## Overview

Pesapal is a leading pan-African payment gateway that enables businesses to accept payments from customers across multiple African countries. This integration supports various payment methods including mobile money (M-Pesa, Airtel Money), credit/debit cards, and bank transfers, making it ideal for businesses operating in Africa.

## Features

- **Multiple Payment Methods**: Credit/Debit Cards, Mobile Money, Bank Transfer, Pesapal Wallet
- **Multi-Country Support**: Kenya, Uganda, Tanzania, Rwanda, Malawi, Zambia, Zimbabwe
- **Mobile Money Integration**: M-Pesa, Airtel Money, Tigo Pesa, MTN Mobile Money
- **Real-time Processing**: Instant payment authorization and verification
- **Secure Authentication**: OAuth 2.0 with Consumer Key/Secret
- **Comprehensive Webhooks**: IPN (Instant Payment Notification) support
- **Multi-Language Support**: English and Swahili interfaces

## Supported Countries

| Country | Currency | Code | Mobile Money Options |
|---------|----------|------|---------------------|
| Kenya | Kenyan Shilling | KES | M-Pesa, Airtel Money, Tigo Pesa |
| Uganda | Ugandan Shilling | UGX | MTN Mobile Money, Airtel Money |
| Tanzania | Tanzanian Shilling | TZS | M-Pesa, Tigo Pesa, Airtel Money |
| Rwanda | Rwandan Franc | RWF | MTN Mobile Money, Airtel Money |
| Malawi | Malawian Kwacha | MWK | TNM Mpamba, Airtel Money |
| Zambia | Zambian Kwacha | ZMW | MTN Mobile Money, Airtel Money |
| Zimbabwe | USD/ZWL | USD | EcoCash, OneMoney |

## Supported Payment Methods

| Method | Code | Description |
|--------|------|-------------|
| Credit/Debit Card | card | Visa, Mastercard |
| Mobile Money | mobile | M-Pesa, Airtel Money, Tigo Pesa, MTN Mobile Money |
| Bank Transfer | bank_transfer | Direct bank deposit |
| Pesapal Wallet | pesapal_wallet | Pesapal digital wallet |

## Configuration

### 1. Install Package

```bash
composer require njoguamos/laravel-pesapal
```

### 2. Environment Variables

Add these variables to your `.env` file:

```env
# Pesapal Configuration
PESAPAL_CONSUMER_KEY=YOUR_CONSUMER_KEY
PESAPAL_CONSUMER_SECRET=YOUR_CONSUMER_SECRET
PESAPAL_TEST_MODE=true
PESAPAL_WEBHOOK_URL=https://yourapp.com/payment/pesapal/webhook
PESAPAL_RETURN_URL=https://yourapp.com/payment/pesapal/success
PESAPAL_BRANCH=MAIN
```

### 3. Publish Configuration

```bash
php artisan vendor:publish --provider="Mdiqbal\LaravelPayments\PaymentsServiceProvider"
```

### 4. Update Config File

```php
// config/payments.php
'gateways' => [
    'pesapal' => [
        'driver' => 'pesapal',
        'consumer_key' => env('PESAPAL_CONSUMER_KEY'),
        'consumer_secret' => env('PESAPAL_CONSUMER_SECRET'),
        'test_mode' => env('PESAPAL_TEST_MODE', true),
        'webhook_url' => env('PESAPAL_WEBHOOK_URL'),
        'return_url' => env('PESAPAL_RETURN_URL'),
        'branch' => env('PESAPAL_BRANCH', 'MAIN'),
    ],
],
```

## Pesapal Account Setup

### 1. Create Pesapal Account

1. [Register on Pesapal](https://www.pesapal.com/)
2. Complete the merchant registration form
3. Submit business documents (KRA PIN, Certificate of Incorporation, ID)
4. Wait for approval (typically 2-3 business days)

### 2. Get API Credentials

Once approved:
1. Log into Pesapal Merchant Dashboard
2. Navigate to Settings > API Integration
3. Note down:
   - Consumer Key
   - Consumer Secret
   - Test/Live mode settings

### 3. Configure IPN

In your Pesapal dashboard:
1. Go to Settings > IPN Settings
2. Add your IPN URL: `https://yourapp.com/payment/pesapal/webhook`
3. Enable notifications for:
   - Payment Success
   - Payment Failed
   - Payment Cancelled

## Usage Examples

### 1. Basic Payment Processing

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTOs\PaymentRequest;

// Initialize Pesapal gateway
$payment = Payment::gateway('pesapal');

// Create a payment request
$response = $payment->pay(new PaymentRequest(
    amount: 1000.00,
    currency: 'KES',
    orderId: 'ORDER-' . uniqid(),
    description: 'Product purchase',
    customer: [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '+254712345678',
        'address' => 'Nairobi, Kenya',
        'city' => 'Nairobi',
        'country' => 'Kenya',
    ],
    returnUrl: route('payment.success'),
    notifyUrl: route('payment.webhook'),
    metadata: [
        'language' => 'EN', // or 'SW' for Swahili
    ]
));

if ($response->success) {
    // Redirect to Pesapal payment page
    return redirect($response->redirectUrl);
} else {
    // Handle error
    return back()->with('error', $response->message);
}
```

### 2. Mobile Money Payment (M-Pesa)

```php
$response = $payment->pay(new PaymentRequest(
    amount: 2500.00,
    currency: 'KES',
    orderId: 'MPESA-' . uniqid(),
    description: 'M-Pesa payment',
    customer: [
        'name' => 'Jane Smith',
        'email' => 'jane@example.com',
        'phone' => '+254722345678', // M-Pesa registered number
    ],
    metadata: [
        'payment_method' => 'MPESA',
        'language' => 'EN',
    ]
));
```

### 3. Payment Link Creation

```php
$linkResponse = $payment->createPaymentLink([
    'amount' => 5000.00,
    'currency' => 'KES',
    'id' => 'LINK_' . time(),
    'description' => 'Invoice payment',
    'customer_name' => 'David Mwangi',
    'customer_email' => 'david@example.com',
    'customer_phone' => '+254733456789',
    'redirect_url' => route('payment.success'),
    'callback_url' => route('payment.webhook'),
]);

if ($linkResponse->success) {
    $paymentUrl = $linkResponse->redirectUrl;
    // Send payment link via SMS or email
}
```

### 4. Payment Status Check

```php
// Check payment status using order tracking ID
$orderTrackingId = 'PESAPAL-123456789';
$response = $payment->verify(['order_tracking_id' => $orderTrackingId]);

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

### 5. Process Refunds

```php
// Process a refund
$refundResponse = $payment->refund([
    'order_tracking_id' => 'PESAPAL-123456789',
    'amount' => 500.00,
    'reason' => 'Customer requested refund'
]);

if ($refundResponse->success) {
    echo "Refund processed successfully";
    echo "Refund Reference: " . $refundResponse->data['refund_reference'];
}
```

### 6. Cross-Border Payment (Uganda)

```php
$response = $payment->pay(new PaymentRequest(
    amount: 150000.00,
    currency: 'UGX', // Ugandan Shillings
    orderId: 'UG-' . uniqid(),
    description: 'Payment for service',
    customer: [
        'name' => 'Sarah Katumba',
        'email' => 'sarah@example.com',
        'phone' => '+256772123456', // MTN Uganda number
        'city' => 'Kampala',
        'country' => 'Uganda',
    ],
    metadata: [
        'payment_method' => 'MTN_MOBILE_MONEY',
        'language' => 'EN',
    ]
));
```

## Webhook Handling

### 1. Create Webhook Route

```php
// routes/web.php
Route::post('/payment/pesapal/webhook', [PesapalController::class, 'handleWebhook'])
    ->name('payment.pesapal.webhook')
    ->middleware('ipn.whitelist');
```

### 2. Webhook Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class PesapalController extends Controller
{
    /**
     * Handle Pesapal IPN (Instant Payment Notification)
     */
    public function handleWebhook(Request $request)
    {
        $gateway = Payment::gateway('pesapal');

        // Process IPN
        $response = $gateway->processWebhook($request->all());

        if ($response->success) {
            // Extract IPN data
            $ipnData = $response->data;
            $status = $response->status;

            // Process based on status
            switch ($status) {
                case 'completed':
                    $this->handleSuccessfulPayment($ipnData);
                    break;

                case 'failed':
                    $this->handleFailedPayment($ipnData);
                    break;

                case 'cancelled':
                    $this->handleCancelledPayment($ipnData);
                    break;
            }

            return response('IPN processed successfully');
        }

        return response('IPN processing failed', 400);
    }

    /**
     * Handle successful payment
     */
    private function handleSuccessfulPayment(array $data)
    {
        // Update your database
        DB::table('payments')
            ->where('order_tracking_id', $data['order_tracking_id'])
            ->update([
                'status' => 'completed',
                'pesapal_transaction_id' => $data['order_id'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'confirmation_code' => $data['confirmation_code'] ?? null,
                'payment_account' => $data['payment_account'] ?? null,
                'paid_at' => now()
            ]);

        // Update order status
        DB::table('orders')
            ->where('order_tracking_id', $data['order_tracking_id'])
            ->update(['status' => 'paid']);

        // Send confirmation email
        // Generate receipt
        // Trigger fulfillment process
    }

    /**
     * Handle failed payment
     */
    private function handleFailedPayment(array $data)
    {
        DB::table('payments')
            ->where('order_tracking_id', $data['order_tracking_id'])
            ->update([
                'status' => 'failed',
                'failure_reason' => $data['status'] ?? 'Payment failed',
                'failed_at' => now()
            ]);

        // Notify customer
        // Log the failure for review
    }

    /**
     * Handle cancelled payment
     */
    private function handleCancelledPayment(array $data)
    {
        DB::table('payments')
            ->where('order_tracking_id', $data['order_tracking_id'])
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now()
            ]);

        // Notify customer
        // Restore inventory if needed
    }
}
```

### 3. IPN Whitelist Middleware

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IpnWhitelist
{
    // Pesapal IP ranges (check documentation for latest IPs)
    private $allowedIps = [
        '196.201.214.200',
        '196.201.214.206',
        '196.201.214.207',
        '196.201.214.208',
        // Add more IPs as provided by Pesapal
    ];

    public function handle(Request $request, Closure $next)
    {
        if (!in_array($request->ip(), $this->allowedIps)) {
            Log::warning('Unauthorized IPN attempt', [
                'ip' => $request->ip(),
                'data' => $request->all()
            ]);
            abort(403, 'Unauthorized');
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
        $orderTrackingId = $request->get('OrderTrackingId');
        $merchantReference = $request->get('OrderMerchantReference');

        if (!$orderTrackingId) {
            return redirect()->route('checkout')
                ->with('error', 'Invalid payment reference');
        }

        // Verify payment status
        $gateway = Payment::gateway('pesapal');
        $response = $gateway->verify(['order_tracking_id' => $orderTrackingId]);

        if ($response->success && $response->status === 'completed') {
            return view('payment.success', [
                'transaction_id' => $response->transactionId,
                'amount' => $response->data['amount'],
                'currency' => $response->data['currency'],
                'payment_method' => $response->data['payment_method'],
                'confirmation_code' => $response->data['confirmation_code'],
            ]);
        }

        // Payment not completed yet
        return redirect()->route('payment.pending')
            ->with('order_tracking_id', $orderTrackingId);
    }

    /**
     * Show pending payment page
     */
    public function showPending(Request $request)
    {
        $orderTrackingId = $request->session('order_tracking_id');

        if (!$orderTrackingId) {
            return redirect()->route('home');
        }

        return view('payment.pending', [
            'order_tracking_id' => $orderTrackingId,
            'check_url' => route('payment.check.status'),
        ]);
    }
}
```

## Testing

### 1. Test Environment

Pesapal provides a sandbox environment:

```env
PESAPAL_TEST_MODE=true
```

### 2. Test Credentials

Get test credentials from Pesapal:
1. Contact Pesapal support for sandbox access
2. Request test Consumer Key and Secret
3. Use sandbox URLs for testing

### 3. Test Payment Scenarios

```php
// Test mobile money payment
$testMobilePayment = $payment->pay(new PaymentRequest(
    amount: 100.00,
    currency: 'KES',
    orderId: 'TEST_' . time(),
    description: 'Test payment',
    customer: [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone' => '+254712345678',
    ],
    metadata: [
        'payment_method' => 'MPESA',
    ]
));
```

## Security Considerations

### 1. IPN Security

- Whitelist Pesapal IP addresses
- Verify IPN data structure
- Use HTTPS for all endpoints
- Log all IPN requests

### 2. Data Protection

- Encrypt sensitive data at rest
- Use HTTPS for all communications
- Validate all input data
- Implement proper logging

### 3. Mobile Money Security

```php
// Verify phone number format for specific countries
function validatePhoneNumber($phone, $country) {
    $patterns = [
        'KE' => '/^\+2547[0-9]{8}$/', // Kenya
        'UG' => '/^\+2567[0-9]{8}$/', // Uganda
        'TZ' => '/^\+255[67][0-9]{8}$/', // Tanzania
    ];

    return isset($patterns[$country]) && preg_match($patterns[$country], $phone);
}
```

## Error Handling

### Common Error Codes

| Code | Description | Solution |
|------|-------------|----------|
| 400 | Bad Request | Check request parameters |
| 401 | Unauthorized | Verify Consumer Key/Secret |
| 403 | Forbidden | Check IP whitelist |
| 404 | Not Found | Verify endpoint URL |
| 500 | Server Error | Retry with backoff |
| 1001 | Invalid Amount | Check amount format |
| 1002 | Invalid Currency | Use supported currency |
| 1003 | Invalid Phone | Verify phone number format |

### Error Handling Example

```php
try {
    $response = $payment->pay($paymentRequest);

    if (!$response->success) {
        // Log error details
        Log::error('Pesapal payment failed', [
            'error_code' => $response->errorCode,
            'message' => $response->message,
            'order_id' => $paymentRequest->orderId
        ]);

        // Show user-friendly message based on error
        $userMessage = $this->getUserFriendlyErrorMessage($response->errorCode);
        return back()->with('error', $userMessage);
    }
} catch (\Exception $e) {
    Log::error('Pesapal gateway error', [
        'error' => $e->getMessage()
    ]);

    return back()->with('error', 'Payment service temporarily unavailable.');
}

private function getUserFriendlyErrorMessage(string $errorCode): string
{
    $errorMessages = [
        '1001' => 'Invalid payment amount. Please check the amount.',
        '1002' => 'Currency not supported. Please use a supported currency.',
        '1003' => 'Invalid phone number. Please check the format.',
        '1004' => 'Payment method not available. Please try another method.',
    ];

    return $errorMessages[$errorCode] ?? 'Payment failed. Please try again.';
}
```

## Best Practices

### 1. Transaction Management

- Always use unique order IDs
- Store order tracking IDs for verification
- Implement proper error handling
- Log all transactions

### 2. Mobile Money Integration

```php
// Country-specific mobile money detection
function getMobileMoneyOperator($phone, $country) {
    $operators = [
        'KE' => [
            '0711' => 'Safaricom M-Pesa',
            '0757' => 'Airtel Money',
            '0765' => 'Tigo Pesa',
        ],
        'UG' => [
            '0772' => 'MTN Mobile Money',
            '0757' => 'Airtel Money',
        ],
    ];

    $prefix = substr($phone, 4, 4); // Get country code prefix + first digits
    return $operators[$country][$prefix] ?? 'Unknown';
}
```

### 3. Customer Experience

- Show payment method options dynamically
- Display fees upfront
- Provide clear error messages
- Support local languages
- Show estimated processing times

### 4. Performance Optimization

- Cache mobile money operator information
- Use CDN for static resources
- Implement proper session management
- Monitor API response times

## Localization

Pesapal supports multiple languages:

```php
// English (default)
'language' => 'EN'

// Swahili
'language' => 'SW'
```

## Rate Limits

- Standard API: 100 requests per minute
- IPN processing: No rate limit
- Status check: 100 requests per hour
- Implement proper rate limiting

## Support

- Pesapal Website: https://pesapal.com/
- Developer Portal: https://developer.pesapal.com/
- Email: support@pesapal.com
- Phone: +254 020 5000 999 (Kenya)
- Live Chat: Available on website

## Troubleshooting

### Common Issues

1. **Authentication Failed**
   - Check Consumer Key and Secret
   - Verify test/live mode settings
   - Ensure proper endpoint URL

2. **Mobile Money Not Working**
   - Verify phone number format
   - Check if operator supports the amount
   - Ensure customer has sufficient funds

3. **IPN Not Received**
   - Check IPN URL accessibility
   - Verify IP whitelist settings
   - Check firewall configuration

4. **Cross-border Issues**
   - Verify currency support
   - Check mobile money compatibility
   - Confirm customer location

### Debug Mode

Enable debug logging:

```php
// config/payments.php
'pesapal' => [
    // ... other config
    'debug' => env('APP_DEBUG', false),
],
```

This will log all Pesapal requests and responses for debugging purposes.