# Razorpay Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
RAZORPAY_MODE=
RAZORPAY_SANDBOX_KEY_ID=
RAZORPAY_SANDBOX_KEY_SECRET=
RAZORPAY_LIVE_KEY_ID=
RAZORPAY_LIVE_KEY_SECRET=
RAZORPAY_WEBHOOK_SECRET=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `RAZORPAY_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` creates an Order and returns its id with `key_id` in data. Open Razorpay Checkout.js
with `order_id` and `key`.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('razorpay', new PaymentRequest(
    amount: 100.00,
    currency: 'INR',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'razorpay'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/razorpay'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// Checkout.js handler posts razorpay_order_id, razorpay_payment_id, razorpay_signature
$result = Payment::verify('razorpay', $request->only('razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature'));

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Set `RAZORPAY_WEBHOOK_SECRET` (the secret entered when creating the webhook in the dashboard). `X-Razorpay-Signature` is checked against the raw body. `payment.captured` / `order.paid` are success; `payment.authorized` is pending.

## Refunds

`Payment::refund('razorpay', $paymentId, 100.00)`.
