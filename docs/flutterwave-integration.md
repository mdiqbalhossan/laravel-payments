# Flutterwave Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
FLUTTERWAVE_MODE=
FLUTTERWAVE_SANDBOX_PUBLIC_KEY=
FLUTTERWAVE_SANDBOX_SECRET_KEY=
FLUTTERWAVE_LIVE_PUBLIC_KEY=
FLUTTERWAVE_LIVE_SECRET_KEY=
FLUTTERWAVE_SECRET_HASH=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `FLUTTERWAVE_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` creates a Flutterwave Standard payment and returns the hosted checkout link. tx_ref (your order id) is the transaction id.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('flutterwave', new PaymentRequest(
    amount: 100.00,
    currency: 'NGN',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'flutterwave'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/flutterwave'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// Redirect: ?status=successful&tx_ref=...&transaction_id=...
$result = Payment::verify('flutterwave', $request->only('status', 'tx_ref', 'transaction_id'));

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Set `FLUTTERWAVE_SECRET_HASH` to the secret hash in Settings > Webhooks. The `verif-hash` header must match it; the transaction is then re-verified via the API.

## Refunds

`Payment::refund('flutterwave', $flutterwaveIdOrTxRef, 1000)`.
