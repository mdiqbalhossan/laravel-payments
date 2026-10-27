# Midtrans Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
MIDTRANS_MODE=
MIDTRANS_SANDBOX_SERVER_KEY=
MIDTRANS_SANDBOX_CLIENT_KEY=
MIDTRANS_LIVE_SERVER_KEY=
MIDTRANS_LIVE_CLIENT_KEY=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `MIDTRANS_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` creates a Snap transaction and returns `redirect_url` (and `snap_token` for Snap.js). IDR only; amounts are whole rupiah.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('midtrans', new PaymentRequest(
    amount: 100.00,
    currency: 'IDR',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'midtrans'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/midtrans'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
$result = Payment::verify('midtrans', ['order_id' => $request->query('order_id')]);

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Set the Payment Notification URL to `/payments/webhook/midtrans`. `signature_key` (sha512 of order_id + status_code + gross_amount + server key) is required and checked, then the status API is called.

## Refunds

`Payment::refund('midtrans', $orderId, 50000)`.
