# Telr Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
TELR_MODE=
TELR_SANDBOX_STORE_ID=
TELR_SANDBOX_STORE_AUTH_KEY=
TELR_LIVE_STORE_ID=
TELR_LIVE_STORE_AUTH_KEY=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `TELR_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` creates an order (order.json) and returns its URL. The Telr **order ref** is the transaction id — store it, Telr's return URLs do not include it. Sandbox mode sends `ivp_test=1`.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('telr', new PaymentRequest(
    amount: 100.00,
    currency: 'AED',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'telr'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/telr'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
$result = Payment::verify('telr', ['order_ref' => $order->gateway_txn]);

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Not used; call `verify()` from your return URL.

## Refunds

Not supported (`supportsRefund()` is false).
