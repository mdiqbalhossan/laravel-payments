# PayPal Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
PAYPAL_MODE=
PAYPAL_SANDBOX_CLIENT_ID=
PAYPAL_SANDBOX_CLIENT_SECRET=
PAYPAL_LIVE_CLIENT_ID=
PAYPAL_LIVE_CLIENT_SECRET=
PAYPAL_WEBHOOK_SECRET=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `PAYPAL_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` creates a v2 Checkout order (intent CAPTURE) and returns the approval link.
The order id is the transaction id.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('paypal', new PaymentRequest(
    amount: 100.00,
    currency: 'USD',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'paypal'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/paypal'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// PayPal returns to returnUrl with ?token=ORDER_ID&PayerID=...
// verify() captures an APPROVED order; success only when the capture is COMPLETED
$result = Payment::verify('paypal', ['token' => $request->query('token')]);
$captureId = $result->transactionId; // store for refunds

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Set `PAYPAL_WEBHOOK_SECRET` to the **Webhook ID** shown in the PayPal developer dashboard. Each webhook is verified with PayPal's `verify-webhook-signature` API. `PAYMENT.CAPTURE.COMPLETED` is reported as success; `CHECKOUT.ORDER.APPROVED` is only `pending`.

## Refunds

`Payment::refund('paypal', $captureIdOrOrderId, 10.00)` — refunds in the capture's own currency; `0` refunds the full capture.
