# EasyPaisa Integration Guide

## Overview

EasyPaisa is Pakistan's leading digital payment platform, operated by Telenor Microfinance Bank. It provides comprehensive payment solutions including mobile wallet payments, over-the-counter (OTC) payments, and bank transfers, serving millions of users across Pakistan.

## Supported Features

- ✅ Mobile Account Payments
- ✅ Over The Counter (OTC) Payments
- ✅ Bank Account Payments
- ✅ Real-time Payment Notifications
- ✅ Refund Processing
- ✅ Transaction Status Checking
- ✅ Webhook Integration
- ✅ PKR Currency Support

## Supported Payment Methods

| Method | Type | Description |
|--------|------|-------------|
| Mobile Account | Wallet | Direct payment from EasyPaisa mobile account balance |
| OTC | Cash | Generate payment voucher and pay cash at any EasyPaisa shop |
| Bank Account | Bank Transfer | Payment through linked bank accounts |

## Installation

1. Install the EasyPaisa package:
```bash
composer require zfhassaan/easypaisa
```

2. Configure your EasyPaisa merchant account:
- Register at [EasyPaisa Merchant Portal](https://merchant.easypaisa.com.pk)
- Complete the merchant onboarding process
- Get your merchant credentials from the dashboard

## Configuration

Add the following to your `.env` file:

```env
# EasyPaisa Configuration
EASYPAISA_MERCHANT_ID=your_merchant_id
EASYPAISA_MERCHANT_PASSWORD=your_merchant_password
EASYPAISA_STORE_ID=your_store_id
EASYPAISA_ACCOUNT_NUMBER=your_account_number
EASYPAISA_TEST_MODE=true
```

For production:
```env
EASYPAISA_MERCHANT_ID=your_production_merchant_id
EASYPAISA_MERCHANT_PASSWORD=your_production_password
EASYPAISA_TEST_MODE=false
```

## Basic Usage

### 1. Mobile Account Payment

```php
use Mdiqbal\LaravelPayments\Facades\Payment;

$paymentData = [
    'amount' => 1000.00,
    'currency' => 'PKR',
    'user_email' => 'customer@example.com',
    'user_name' => 'Ahmed Khan',
    'description' => 'Payment for Order #123',
    'metadata' => [
        'payment_type' => 'mobile_account',
        'mobile_account' => '03xxxxxxxxx', // EasyPaisa mobile number
    ],
];

$response = Payment::via('easypaisa')->pay($paymentData);

if ($response->success) {
    // Redirect to payment URL or show payment details
    if ($response->redirect_url) {
        return redirect($response->redirect_url);
    }

    // Or show payment instructions
    return view('payment.instructions', [
        'transaction_id' => $response->transaction_id,
        'message' => $response->message,
    ]);
}

// Handle error
return back()->with('error', $response->message);
```

### 2. OTC (Over The Counter) Payment

```php
$paymentData = [
    'amount' => 2500.00,
    'currency' => 'PKR',
    'user_email' => 'customer@example.com',
    'user_name' => 'Muhammad Ali',
    'description' => 'Product purchase',
    'metadata' => [
        'payment_type' => 'otc',
        'expiry_date' => date('Ymd', strtotime('+3 days')),
    ],
];

$response = Payment::gateway('easypaisa')
    ->config('test_mode', false) // Force production
    ->pay($paymentData);

if ($response->success) {
    $paymentCode = $response->data['payment_code'] ?? null;
    $expiryDate = $response->data['expiry_date'] ?? null;

    return view('payment.otc', [
        'payment_code' => $paymentCode,
        'amount' => 2500.00,
        'expiry_date' => $expiryDate,
        'instructions' => 'Visit any EasyPaisa shop and provide this payment code',
    ]);
}
```

### 3. Bank Account Payment

```php
$paymentData = [
    'amount' => 5000.00,
    'currency' => 'PKR',
    'user_email' => 'customer@example.com',
    'user_name' => 'Fatima Ahmed',
    'description' => 'Service payment',
    'metadata' => [
        'payment_type' => 'bank_account',
        'bank_account' => '1234567890123', // Bank account number
    ],
];

$response = Payment::via('easypaisa')->pay($paymentData);

if ($response->success) {
    return redirect($response->redirect_url);
}
```

### 4. Payment Verification

```php
use Illuminate\Http\Request;

public function verifyPayment(Request $request)
{
    $transactionId = $request->input('transaction_id');

    $response = Payment::via('easypaisa')->verify([
        'transaction_id' => $transactionId
    ]);

    if ($response->success) {
        // Payment successful
        $status = $response->status;
        $amount = $response->amount;
        $currency = $response->currency;

        return response()->json([
            'status' => 'success',
            'payment_status' => $status,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    return response()->json([
        'status' => 'error',
        'message' => $response->message,
    ]);
}
```

### 5. Get Available Payment Methods

```php
$methods = Payment::via('easypaisa')->methods();

foreach ($methods as $method) {
    echo $method['name'] . ': ' . $method['description'] . "\n";
}
```

## Webhook Handling

Create a webhook endpoint to receive payment notifications:

```php
// routes/web.php
Route::post('/webhooks/easypaisa', 'PaymentController@handleEasyPaisaWebhook');

// PaymentController.php
use Illuminate\Http\Request;

public function handleEasyPaisaWebhook(Request $request)
{
    $response = Payment::via('easypaisa')->webhook($request);

    if ($response->success) {
        $transactionId = $response->transaction_id;
        $status = $response->status;
        $amount = $response->amount;
        $paymentMethod = $response->data['payment_method'] ?? null;

        // Handle based on payment status
        switch ($status) {
            case 'completed':
                // Update order as paid
                $this->updateOrderAsPaid($transactionId, $amount);
                break;

            case 'failed':
                // Handle failed payment
                $this->handleFailedPayment($transactionId);
                break;

            case 'cancelled':
                // Handle cancelled payment
                $this->handleCancelledPayment($transactionId);
                break;

            case 'pending':
                // Payment is pending confirmation
                $this->markOrderAsPending($transactionId);
                break;
        }

        return response()->json(['status' => 'received']);
    }

    return response()->json(['error' => 'Invalid webhook'], 400);
}
```

## Refunds

Process refunds through the EasyPaisa gateway:

```php
$refundData = [
    'transaction_id' => 'original_transaction_id',
    'amount' => 500.00, // Optional - defaults to full refund
    'reason' => 'Customer requested refund',
];

$response = Payment::via('easypaisa')->refund($refundData);

if ($response->success) {
    $refundId = $response->transaction_id;
    // Update your records
}
```

## Currency Support

EasyPaisa exclusively supports Pakistani Rupee (PKR):

```php
// Check currency support
$supportsPKR = Payment::via('easypaisa')->supportsCurrency('PKR'); // true
$supportsUSD = Payment::via('easypaisa')->supportsCurrency('USD'); // false
```

## Error Handling

EasyPaisa gateway provides comprehensive error handling:

```php
try {
    $response = Payment::via('easypaisa')->pay($paymentData);

    if (!$response->success) {
        // Handle specific EasyPaisa errors
        switch ($response->code) {
            case '002':
                return back()->with('error', 'Payment failed - insufficient funds');

            case '097':
                return back()->with('error', 'Transaction rejected by bank');

            case '098':
                return back()->with('error', 'Payment cancelled by user');

            default:
                return back()->with('error', $response->message);
        }
    }

    return redirect($response->redirect_url);

} catch (\Exception $e) {
    Log::error('EasyPaisa payment error: ' . $e->getMessage());
    return back()->with('error', 'Payment processing error');
}
```

## Response Codes

| Code | Description | Action |
|------|-------------|--------|
| 000 | Success | Payment completed |
| 001 | Pending | Payment is processing |
| 002 | Failed | Payment failed - retry |
| 003 | Cancelled | Payment cancelled by user |
| 096 | Pending | Awaiting confirmation |
| 097 | Failed | Bank rejected transaction |
| 098 | Cancelled | User cancelled |
| 099 | Failed | General failure |

## Testing

### Test Mode

EasyPaisa provides a sandbox environment:

```php
// Enable test mode
Payment::via('easypaisa')->config('test_mode', true);

// Or use test credentials directly
Payment::gateway('easypaisa')
    ->withConfig('test_mode', true)
    ->pay($paymentData);
```

### Test Credentials

Use these test credentials for development:

```php
'test_mode' => true,
'merchant_id' => 'TEST_MERCHANT_ID',
'merchant_password' => 'TEST_PASSWORD',
'store_id' => 'TEST_STORE',
```

### Test Payment Flow

1. Create a test payment using the sandbox
2. Use the test payment URL or payment code
3. Verify payment status using test transaction ID
4. Test webhook handling in sandbox mode

## Transaction Limits

- **Minimum transaction**: PKR 100
- **Maximum transaction**: PKR 500,000
- **Daily limit**: Based on merchant account type
- **Monthly limit**: Based on merchant account type

## Security Considerations

1. **API Security**
   - Always use HTTPS for all communications
   - Validate all incoming data
   - Never expose credentials in frontend code

2. **Webhook Security**
   - Implement webhook signature verification
   - Use IP whitelisting if possible
   - Validate all webhook data

3. **Data Protection**
   - Store transaction IDs securely
   - Log all transactions for auditing
   - Comply with SBP regulations

## Best Practices

1. **Payment Flow**
   - Always verify payment status before fulfilling orders
   - Implement proper error handling
   - Store all transaction details

2. **User Experience**
   - Show clear payment instructions
   - Provide multiple payment options
   - Display payment status updates

3. **Monitoring**
   - Monitor webhook delivery
   - Track transaction success rates
   - Implement alerting for failures

## Troubleshooting

### Common Issues

1. **"Invalid Merchant Credentials"**
   - Verify merchant ID and password
   - Check if test mode is enabled correctly
   - Ensure store ID is correct

2. **"Payment Failed"**
   - Check if amount is within limits (PKR 100 - 500,000)
   - Verify customer mobile number format
   - Ensure currency is set to PKR

3. **"Webhook Not Received"**
   - Check webhook URL accessibility
   - Verify webhook is publicly accessible
   - Check firewall configuration

4. **"Token Generation Failed"**
   - Verify merchant credentials
   - Check system time synchronization
   - Ensure API endpoints are correct

### Debug Mode

Enable debug logging:

```php
// Enable debug logging
Payment::gateway('easypaisa')
    ->withConfig('debug', true)
    ->pay($paymentData);
```

## Support

- **EasyPaisa Merchant Portal**: [https://merchant.easypaisa.com.pk](https://merchant.easypaisa.com.pk)
- **API Documentation**: Available in merchant portal
- **Support Email**: merchant.support@easypaisa.com.pk
- **Phone Support**: 021-111-003-279

## Regional Compliance

EasyPaisa operates under State Bank of Pakistan (SBP) regulations:
- KYC verification required for all transactions
- AML/CFT compliance mandatory
- Transaction monitoring implemented
- Data residency requirements met

## Rate Limits

- Payment initiation: 60 requests per minute
- Status checking: 120 requests per minute
- Refund processing: 30 requests per minute
- Webhook processing: No rate limiting

## Integration Checklist

- [ ] Register for EasyPaisa merchant account
- [ ] Complete merchant onboarding
- [ ] Get production credentials
- [ ] Configure webhook endpoints
- [ ] Implement payment methods
- [ ] Test in sandbox environment
- [ ] Implement error handling
- [ ] Set up monitoring and logging
- [ ] Deploy to production
- [ ] Monitor initial transactions