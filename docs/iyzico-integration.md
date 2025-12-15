# Iyzico Integration Guide

## Overview

Iyzico is Turkey's leading payment gateway, providing comprehensive payment solutions for businesses. This integration supports multiple payment methods including credit/debit cards, installments, BKM Express, and various digital wallets. Iyzico offers robust security features and supports both Turkish Lira and international currencies.

## Features

- **Multiple Payment Methods**: Credit/Debit Cards, Installments, BKM Express, Garanti Pay, Paycell
- **Multi-Currency Support**: TRY, USD, EUR, GBP, NOK, CHF
- **Installment Payments**: Support for 2-12 month installments
- **Secure Authentication**: IYZWSv2 HMAC-SHA256 authentication
- **Real-time Processing**: Instant payment authorization and verification
- **Fraud Protection**: Built-in fraud detection and prevention
- **Card Saving**: Tokenization for recurring payments
- **Comprehensive Webhooks**: Event-driven payment notifications

## Supported Currencies

| Currency | Code | Primary Market |
|----------|------|----------------|
| Turkish Lira | TRY | Turkey |
| US Dollar | USD | International |
| Euro | EUR | European Union |
| British Pound | GBP | United Kingdom |
| Norwegian Krone | NOK | Norway |
| Swiss Franc | CHF | Switzerland |

## Supported Payment Methods

| Method | Code | Description |
|--------|------|-------------|
| Credit/Debit Card | card | Visa, Mastercard, Amex |
| Installment | installment | 2-12 month installments |
| BKM Express | bkm_express | Turkish bank transfer system |
| Garanti Pay | garanti_pay | Garanti Bank digital wallet |
| Paycell | paycell | Turkcell mobile payment |
| Bank Transfer | bank_transfer | Direct bank transfer |
| Digital Wallet | wallet | Various e-wallets |

## Configuration

### 1. Environment Variables

Add these variables to your `.env` file:

```env
# Iyzico Configuration
IYZICO_API_KEY=YOUR_API_KEY
IYZICO_SECRET_KEY=YOUR_SECRET_KEY
IYZICO_TEST_MODE=true
IYZICO_CALLBACK_URL=https://yourapp.com/payment/iyzico/callback
IYZICO_WEBHOOK_URL=https://yourapp.com/payment/iyzico/webhook
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="Mdiqbal\LaravelPayments\PaymentsServiceProvider"
```

### 3. Update Config File

```php
// config/payments.php
'gateways' => [
    'iyzico' => [
        'driver' => 'iyzico',
        'api_key' => env('IYZICO_API_KEY'),
        'secret_key' => env('IYZICO_SECRET_KEY'),
        'test_mode' => env('IYZICO_TEST_MODE', true),
        'callback_url' => env('IYZICO_CALLBACK_URL'),
        'webhook_url' => env('IYZICO_WEBHOOK_URL'),
    ],
],
```

## Iyzico Account Setup

### 1. Create Iyzico Account

1. [Register on Iyzico](https://iyzico.com/)
2. Complete the merchant application
3. Submit business documents
4. Wait for approval (typically 1-2 business days)

### 2. Get API Credentials

Once approved:
1. Log into Iyzico Merchant Panel
2. Navigate to Settings > API Keys
3. Note down:
   - API Key
   - Secret Key
   - Test/Live mode settings

### 3. Configure Webhooks

In your Iyzico panel:
1. Go to Settings > Webhooks
2. Add your webhook URL: `https://yourapp.com/payment/iyzico/webhook`
3. Enable notifications for:
   - Payment Success
   - Payment Failure
   - Refund Status

## Usage Examples

### 1. Direct Card Payment (Non-3DS)

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTOs\PaymentRequest;

// Initialize Iyzico gateway
$payment = Payment::gateway('iyzico');

// Create direct payment request
$response = $payment->pay(new PaymentRequest(
    amount: 100.00,
    currency: 'TRY',
    orderId: 'ORDER-' . uniqid(),
    description: 'Product purchase',
    customer: [
        'id' => 'CUST123',
        'name' => 'Ahmet Yılmaz',
        'email' => 'ahmet@example.com',
        'phone' => '+905321234567',
        'identity_number' => '12345678901',
        'address' => 'İstanbul Cad. No:123',
        'city' => 'İstanbul',
        'country' => 'Turkey',
        'postal_code' => '34000',
        'registration_date' => '2021-01-01 00:00:00',
    ],
    metadata: [
        'card' => [
            'holder_name' => 'Ahmet Yılmaz',
            'number' => '5528790000000008', // Test card
            'expire_month' => '12',
            'expire_year' => '2030',
            'cvc' => '123',
            'register_card' => 0, // Don't save card
        ],
        'installment' => 1, // No installment
        'locale' => 'tr',
    ]
));

if ($response->success) {
    echo "Payment successful!";
    echo "Payment ID: " . $response->transactionId;
    echo "Auth Code: " . $response->data['auth_code'];
} else {
    echo "Payment failed: " . $response->message;
}
```

### 2. Payment with Installments

```php
$response = $payment->pay(new PaymentRequest(
    amount: 1200.00,
    currency: 'TRY',
    orderId: 'ORDER-' . uniqid(),
    description: 'Product purchase with installments',
    customer: [
        'name' => 'Mehmet Demir',
        'email' => 'mehmet@example.com',
        'phone' => '+905329876543',
        'identity_number' => '98765432109',
        'address' => 'Ankara Cad. No:456',
        'city' => 'Ankara',
        'country' => 'Turkey',
    ],
    metadata: [
        'card' => [
            'holder_name' => 'Mehmet Demir',
            'number' => '4111111111111111',
            'expire_month' => '12',
            'expire_year' => '2025',
            'cvc' => '123',
        ],
        'installment' => 6, // 6-month installment
        'locale' => 'tr',
    ]
));
```

### 3. Create Checkout Form Payment

```php
$checkoutResponse = $payment->createCheckoutForm([
    'price' => 250.00,
    'paid_price' => 275.50, // With commission
    'currency' => 'TRY',
    'basket_id' => 'BASKET' . uniqid(),
    'payment_group' => 'PRODUCT',
    'callback_url' => route('payment.callback'),
    'enabled_installments' => [1, 2, 3, 6, 9], // Available installments
    'buyer' => [
        'id' => 'BUYER123',
        'name' => 'Ayşe Kaya',
        'surname' => 'Kaya',
        'identity_number' => '11122233344',
        'email' => 'ayse@example.com',
        'phone' => '+905551112233',
        'address' => 'İzmir Cad. No:789',
        'city' => 'İzmir',
        'country' => 'Turkey',
    ],
    'shipping_address' => [
        'address' => 'İzmir Cad. No:789',
        'zip_code' => '35000',
        'contact_name' => 'Ayşe Kaya',
        'city' => 'İzmir',
        'country' => 'Turkey',
    ],
    'billing_address' => [
        'address' => 'İzmir Cad. No:789',
        'zip_code' => '35000',
        'contact_name' => 'Ayşe Kaya',
        'city' => 'İzmir',
        'country' => 'Turkey',
    ],
    'basket_items' => [
        [
            'id' => 'PROD1',
            'name' => 'Product 1',
            'category1' => 'Electronics',
            'category2' => 'Mobile',
            'itemType' => 'PHYSICAL',
            'price' => 150.00,
        ],
        [
            'id' => 'PROD2',
            'name' => 'Product 2',
            'category1' => 'Books',
            'category2' => 'Fiction',
            'itemType' => 'PHYSICAL',
            'price' => 100.00,
        ]
    ]
]);

if ($checkoutResponse->success) {
    // Redirect to checkout form
    return redirect($checkoutResponse->redirectUrl);
}
```

### 4. Payment Status Check

```php
// Check payment status
$paymentId = '123456789';
$response = $payment->verify(['payment_id' => $paymentId]);

if ($response->success) {
    echo "Payment Status: " . $response->status;
    echo "Amount: " . $response->data['price'];
    echo "Currency: " . $response->data['currency'];
    echo "Installment: " . $response->data['installment'];
    echo "Auth Code: " . $response->data['auth_code'];

    if ($response->status === 'completed') {
        // Process order fulfillment
        // Send confirmation email
        // Update inventory
    }
}
```

### 5. Process Refunds

```php
// Full refund
$refundResponse = $payment->refund([
    'payment_id' => '123456789',
    'amount' => null, // Full refund
    'reason' => 'Customer requested refund',
    'currency' => 'TRY',
]);

// Partial refund
$partialRefund = $payment->refund([
    'payment_id' => '123456789',
    'amount' => 50.00, // Partial amount
    'reason' => 'Partial refund for returned item',
    'currency' => 'TRY',
]);

if ($refundResponse->success) {
    echo "Refund processed successfully";
    echo "Refund ID: " . $refundResponse->data['refund_transaction_id'];
}
```

### 6. Save Card for Future Payments

```php
$saveCardResponse = $payment->saveCard([
    'email' => 'customer@example.com',
    'card' => [
        'holder_name' => 'John Doe',
        'number' => '4111111111111111',
        'expire_month' => '12',
        'expire_year' => '2025',
        'cvc' => '123',
    ],
    'card_user_key' => 'CUSTOMER_CARD_KEY', // Optional
]);

if ($saveCardResponse->success) {
    $cardToken = $saveCardResponse->data['card_token'];
    $cardUserKey = $saveCardResponse->data['card_user_key'];

    // Save these tokens for future payments
}
```

### 7. Payment with Saved Card

```php
$response = $payment->pay(new PaymentRequest(
    amount: 100.00,
    currency: 'TRY',
    orderId: 'ORDER-' . uniqid(),
    description: 'Payment with saved card',
    metadata: [
        'card_user_key' => $savedCardUserKey,
        'card_token' => $savedCardToken,
        'installment' => 1,
        'locale' => 'tr',
    ]
));
```

## Webhook Handling

### 1. Create Webhook Route

```php
// routes/web.php
Route::post('/payment/iyzico/webhook', [IyzicoController::class, 'handleWebhook'])
    ->name('payment.iyzico.webhook')
    ->middleware(['webhook.signature']);
```

### 2. Webhook Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mdiqbal\LaravelPayments\Facades\Payment;

class IyzicoController extends Controller
{
    /**
     * Handle Iyzico webhook
     */
    public function handleWebhook(Request $request)
    {
        $gateway = Payment::gateway('iyzico');

        // Process webhook
        $response = $gateway->processWebhook($request->all());

        if ($response->success) {
            // Extract webhook data
            $webhookData = $response->data;
            $eventType = $webhookData['webhook_type'];

            // Process based on event type
            switch ($eventType) {
                case 'PAYMENT_SUCCESS':
                    $this->handleSuccessfulPayment($webhookData);
                    break;

                case 'PAYMENT_FAILURE':
                    $this->handleFailedPayment($webhookData);
                    break;

                case 'REFUND_SUCCESS':
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
            ->where('payment_id', $data['payment_id'])
            ->update([
                'status' => 'completed',
                'auth_code' => $data['auth_code'] ?? null,
                'fraud_status' => $data['fraud_status'] ?? null,
                'paid_at' => now()
            ]);

        // Update order status
        DB::table('orders')
            ->where('id', $data['basket_id'])
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
            ->where('payment_id', $data['payment_id'])
            ->update([
                'status' => 'failed',
                'failure_reason' => $data['error_message'] ?? 'Payment failed',
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
        // Iyzico sends webhooks with event types
        // Additional verification can be added if needed
        $payload = $request->getContent();

        // Log webhook for debugging
        Log::info('Iyzico webhook received', [
            'ip' => $request->ip(),
            'payload' => $payload
        ]);

        return $next($request);
    }
}
```

## Payment Flow

### 1. Direct Card Payment Flow

```
Customer → Your App → Card Details → Iyzico API → Instant Auth → Success/Failure
```

### 2. Checkout Form Flow

```
Customer → Your App → Create Checkout Form → Redirect to Iyzico → Customer Pays → Iyzico Webhook → Your App → Update Order
```

### 3. Refund Flow

```
Customer Request → Your App → Iyzico Refund API → Process Refund → Iyzico Confirmation → Update Records
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
     * Handle payment callback from checkout form
     */
    public function handleCallback(Request $request)
    {
        $token = $request->get('token');

        if (!$token) {
            return redirect()->route('checkout')
                ->with('error', 'Invalid payment token');
        }

        // You may need to verify payment status using token
        // This depends on your callback configuration

        return redirect()->route('payment.success')
            ->with('token', $token);
    }

    /**
     * Show payment success page
     */
    public function showSuccess(Request $request)
    {
        $token = $request->session('token');

        if (!$token) {
            return redirect()->route('home');
        }

        return view('payment.success', [
            'token' => $token,
            'message' => 'Payment completed successfully'
        ]);
    }
}
```

## Testing

### 1. Test Environment

Iyzico provides a sandbox environment:

```env
IYZICO_TEST_MODE=true
```

### 2. Test Cards

Use these test cards for testing:

| Bank | Card Number | Expiry | CVC | Result |
|------|-------------|--------|-----|---------|
| Akbank | 5528790000000008 | 12/30 | 123 | Success |
| DenizBank | 4111111111111111 | 12/30 | 123 | Success |
| İş Bankası | 5400010000000004 | 12/30 | 123 | Success |
| Fail Card | 4242424242424242 | 12/30 | 123 | Failure |

### 3. Test Identity Numbers

Use these test Turkish Identity Numbers:
- `10000000000` - Valid test identity
- `11111111111` - Valid test identity

### 4. Test Payment

```php
// Create test payment
$testPayment = Payment::gateway('iyzico')->pay(new PaymentRequest(
    amount: 1.00,
    currency: 'TRY',
    orderId: 'TEST_' . time(),
    description: 'Test payment',
    customer: [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'identity_number' => '10000000000',
    ],
    metadata: [
        'card' => [
            'holder_name' => 'Test User',
            'number' => '5528790000000008',
            'expire_month' => '12',
            'expire_year' => '2030',
            'cvc' => '123',
        ],
        'locale' => 'tr',
    ]
));
```

## Security Considerations

### 1. PCI Compliance

- Direct payment: Requires full PCI DSS compliance
- Checkout form: Reduced PCI requirements
- Never store full card details
- Use tokenization for recurring payments

### 2. Data Protection

- Encrypt sensitive data at rest
- Use HTTPS for all communications
- Validate all input data
- Implement proper logging

### 3. Fraud Prevention

```php
// Use Iyzico's fraud protection features
$paymentData = [
    'buyer' => [
        'ip' => request()->ip(),
        'identityNumber' => $customer->identity_number,
        'registrationDate' => $customer->created_at->format('Y-m-d H:i:s'),
        'lastLoginDate' => $customer->last_login_at->format('Y-m-d H:i:s'),
    ],
];
```

## Error Handling

### Common Error Codes

| Code | Description | Solution |
|------|-------------|----------|
| 5001 | Invalid request | Check request parameters |
| 5002 | Authentication failed | Verify API keys |
| 5003 | Invalid currency | Use supported currencies |
| 5004 | Card verification failed | Check card details |
| 5005 | Insufficient funds | Customer to check balance |
| 5006 | Fraud suspicion | Contact customer |

### Error Handling Example

```php
try {
    $response = $payment->pay($paymentRequest);

    if (!$response->success) {
        // Log error details
        Log::error('Iyzico payment failed', [
            'error_code' => $response->errorCode,
            'message' => $response->message,
            'order_id' => $paymentRequest->orderId
        ]);

        // Show user-friendly message based on error
        $userMessage = $this->getUserFriendlyErrorMessage($response->errorCode);
        return back()->with('error', $userMessage);
    }
} catch (\Exception $e) {
    Log::error('Iyzico gateway error', [
        'error' => $e->getMessage()
    ]);

    return back()->with('error', 'Payment service temporarily unavailable.');
}

private function getUserFriendlyErrorMessage(string $errorCode): string
{
    $errorMessages = [
        '5004' => 'Invalid card details. Please check your card information.',
        '5005' => 'Insufficient funds. Please use a different card.',
        '5006' => 'Transaction declined by bank. Please try another payment method.',
        '5007' => 'Transaction timed out. Please try again.',
    ];

    return $errorMessages[$errorCode] ?? 'Payment failed. Please try again.';
}
```

## Best Practices

### 1. Transaction Management

- Always use unique conversation IDs
- Store payment IDs for future reference
- Implement proper error handling
- Log all transactions

### 2. Installment Handling

```php
// Get available installments for a card
function getAvailableInstallments($binNumber) {
    $response = Http::withHeaders([
        'Authorization' => $this->generateAuthorizationHeader(),
        'Content-Type' => 'application/json',
    ])->post($this->baseUrl . '/payment/iyzipos/installment/check', [
        'binNumber' => $binNumber,
        'price' => $amount,
        'currency' => 'TRY',
    ]);

    return $response->json();
}
```

### 3. Customer Experience

- Show installment options dynamically
- Display fees upfront
- Provide clear error messages
- Support both Turkish and English

### 4. Performance Optimization

- Cache installment information
- Use CDN for static resources
- Implement proper session management
- Monitor API response times

## Localization

Iyzico supports multiple languages:

```php
// Turkish (default)
'locale' => 'tr'

// English
'locale' => 'en'
```

## Rate Limits

- Standard API: 100 requests per minute
- Payment API: 60 requests per minute
- Implement proper rate limiting
- Use retry logic with exponential backoff

## Support

- Iyzico Documentation: https://docs.iyzico.com/
- Support Portal: https://merchant.iyzipos.com/
- Email: support@iyzico.com
- Phone: 0850 266 09 44 (Turkey)

## Troubleshooting

### Common Issues

1. **Authentication Failed**
   - Check API key and secret key
   - Verify test/live mode settings
   - Ensure proper header format

2. **Invalid Currency**
   - Use supported currencies only
   - Check currency code format

3. **Payment Declined**
   - Verify card details
   - Check if card supports installments
   - Review fraud settings

4. **Webhook Not Received**
   - Check webhook URL accessibility
   - Verify HTTPS configuration
   - Check firewall settings

### Debug Mode

Enable debug logging:

```php
// config/payments.php
'iyzico' => [
    // ... other config
    'debug' => env('APP_DEBUG', false),
],
```

This will log all Iyzico requests and responses for debugging purposes.