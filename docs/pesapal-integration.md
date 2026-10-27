# Pesapal Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
PESAPAL_MODE=
PESAPAL_SANDBOX_CONSUMER_KEY=
PESAPAL_SANDBOX_CONSUMER_SECRET=
PESAPAL_SANDBOX_IPN_ID=
PESAPAL_LIVE_CONSUMER_KEY=
PESAPAL_LIVE_CONSUMER_SECRET=
PESAPAL_LIVE_IPN_ID=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `PESAPAL_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` submits an order (API 3.0) and returns `redirect_url`; order_tracking_id is the transaction id.
Register your IPN URL once (Pesapal RegisterIPN) and set the returned id as `PESAPAL_*_IPN_ID`.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('pesapal', new PaymentRequest(
    amount: 100.00,
    currency: 'KES',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'pesapal'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/pesapal'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// Pesapal redirects with ?OrderTrackingId=...&OrderMerchantReference=...
$result = Payment::verify('pesapal', $request->only('OrderTrackingId'));

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Register `/payments/webhook/pesapal` as the IPN URL. IPNs are unsigned; the status is always read from the API.

## Refunds

`Payment::refund('pesapal', $orderTrackingId, 100)`. Optional config: `refund_username`.
