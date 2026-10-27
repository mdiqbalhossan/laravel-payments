# senangPay Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
SENANGPAY_MODE=
SENANGPAY_SANDBOX_MERCHANT_ID=
SENANGPAY_SANDBOX_SECRET_KEY=
SENANGPAY_LIVE_MERCHANT_ID=
SENANGPAY_LIVE_SECRET_KEY=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `SENANGPAY_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` returns the senangPay payment URL with a signed (HMAC-SHA256) query string. Set the hash type to **SHA256** in the senangPay dashboard and configure the return/callback URLs there. MYR only.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('senangpay', new PaymentRequest(
    amount: 100.00,
    currency: 'MYR',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'senangpay'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/senangpay'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// Return URL / callback receive status_id, order_id, transaction_id, msg, hash
$result = Payment::verify('senangpay', $request->all());

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Point the senangPay callback URL to `/payments/webhook/senangpay`; the hash is verified. The callback does not include the amount, so `matchesAmount()` returns false — the amount was fixed by the signed payment URL; mark the order matching `order_id` as paid.

## Refunds

Not supported (`supportsRefund()` is false).
