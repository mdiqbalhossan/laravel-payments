# Cashfree Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
CASHFREE_MODE=
CASHFREE_SANDBOX_APP_ID=
CASHFREE_SANDBOX_SECRET_KEY=
CASHFREE_LIVE_APP_ID=
CASHFREE_LIVE_SECRET_KEY=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `CASHFREE_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` creates an order (PG API 2023-08-01) and returns `payment_session_id` in data. Open checkout
with the Cashfree JS SDK: `cashfree.checkout({ paymentSessionId })`. `customer.phone` is required.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('cashfree', new PaymentRequest(
    amount: 100.00,
    currency: 'INR',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'cashfree'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/cashfree'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// return_url: append ?order_id={order_id}
$result = Payment::verify('cashfree', ['order_id' => $request->query('order_id')]);

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

`x-webhook-signature` (base64 HMAC-SHA256 of timestamp + raw body with your secret key) is verified, then the order is re-read.

## Refunds

`Payment::refund('cashfree', $orderId, 100.00)`.
