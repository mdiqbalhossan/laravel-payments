# Paystack Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
PAYSTACK_MODE=
PAYSTACK_SANDBOX_SECRET=
PAYSTACK_SANDBOX_PUBLIC_KEY=
PAYSTACK_LIVE_SECRET=
PAYSTACK_LIVE_PUBLIC_KEY=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `PAYSTACK_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` initializes a transaction and returns `authorization_url`. The reference (your order id) is the transaction id. A customer email is required.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('paystack', new PaymentRequest(
    amount: 100.00,
    currency: 'NGN',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'paystack'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/paystack'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// Paystack redirects to callback_url with ?reference=...
$result = Payment::verify('paystack', ['reference' => $request->query('reference')]);

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

No extra secret: `x-paystack-signature` (HMAC-SHA512 of the raw body with your secret key) is verified, then the transaction is re-verified via the API.

## Refunds

`Payment::refund('paystack', $reference, 500.00)`.
