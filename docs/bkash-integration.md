# bKash Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
BKASH_MODE=
BKASH_SANDBOX_APP_KEY=
BKASH_SANDBOX_APP_SECRET=
BKASH_SANDBOX_USERNAME=
BKASH_SANDBOX_PASSWORD=
BKASH_LIVE_APP_KEY=
BKASH_LIVE_APP_SECRET=
BKASH_LIVE_USERNAME=
BKASH_LIVE_PASSWORD=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `BKASH_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` grants (and caches) a token, creates a Tokenized Checkout payment and returns `bkashURL`. The bKash `paymentID` is the transaction id. BDT only.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('bkash', new PaymentRequest(
    amount: 100.00,
    currency: 'BDT',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'bkash'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/bkash'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// bKash redirects to callbackURL with ?paymentID=...&status=success|failure|cancel
$result = Payment::verify('bkash', $request->only('paymentID', 'status')); // executes the payment

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Not needed: always call `verify()` from the callback, which executes the payment (or queries it if already executed).

## Refunds

`Payment::refund('bkash', $paymentID, 100.00)`.
