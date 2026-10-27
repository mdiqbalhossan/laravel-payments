# Skrill Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
SKRILL_MODE=
SKRILL_SANDBOX_MERCHANT_EMAIL=
SKRILL_SANDBOX_SECRET_WORD=
SKRILL_SANDBOX_API_PASSWORD=
SKRILL_LIVE_MERCHANT_EMAIL=
SKRILL_LIVE_SECRET_WORD=
SKRILL_LIVE_API_PASSWORD=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `SKRILL_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` prepares a Quick Checkout session and returns the checkout URL. Configure `merchant_email` and `secret_word` (Settings > Developer Settings).

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('skrill', new PaymentRequest(
    amount: 100.00,
    currency: 'EUR',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'skrill'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/skrill'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// status_url POST contains md5sig
$result = Payment::verify('skrill', $request->all());
// Without md5sig, verify(['transaction_id' => ...]) queries MQI (needs api_password)

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Use `notifyUrl: url('/payments/webhook/skrill')` (status_url); `md5sig` and `pay_to_email` are checked.

## Refunds

Not supported (`supportsRefund()` is false).
