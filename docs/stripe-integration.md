# Stripe Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
STRIPE_MODE=
STRIPE_SANDBOX_SECRET=
STRIPE_SANDBOX_KEY=
STRIPE_LIVE_SECRET=
STRIPE_LIVE_KEY=
STRIPE_WEBHOOK_SECRET=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `STRIPE_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` creates a PaymentIntent (automatic payment methods) and returns its id as the
transaction id plus `client_secret` in data. Confirm it on the frontend with Stripe.js
(Payment Element). For a hosted page use `Payment::gateway('stripe')->createCheckoutSession($request)`,
which returns a redirect to Stripe Checkout.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('stripe', new PaymentRequest(
    amount: 100.00,
    currency: 'USD',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'stripe'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/stripe'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// After Stripe.js redirects back (?payment_intent=pi_...), or Checkout (?session_id=cs_...)
$result = Payment::verify('stripe', $request->only('payment_intent', 'session_id'));

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Set `STRIPE_WEBHOOK_SECRET` (whsec_...) and point a Stripe webhook at `/payments/webhook/stripe`. The `Stripe-Signature` header is verified against the raw body (5-minute tolerance). Handled events: `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`, `checkout.session.completed`, `charge.*`.

## Refunds

`Payment::refund('stripe', $paymentIntentId, 25.00)` — partial or full (`0`). Amounts use the currency's minor unit (JPY etc. handled).
