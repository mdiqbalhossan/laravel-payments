# Mercado Pago Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
MERCADOPAGO_MODE=
MERCADOPAGO_SANDBOX_ACCESS_TOKEN=
MERCADOPAGO_LIVE_ACCESS_TOKEN=
MERCADOPAGO_COUNTRY=
MERCADOPAGO_WEBHOOK_SECRET=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `MERCADOPAGO_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` creates a Checkout Pro preference and returns its `init_point` (`sandbox_init_point` in sandbox).

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('mercadopago', new PaymentRequest(
    amount: 100.00,
    currency: 'BRL',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'mercadopago'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/mercadopago'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// back_urls receive ?payment_id=...&status=...&external_reference=...
$result = Payment::verify('mercadopago', ['payment_id' => $request->query('payment_id')]);

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

`notification_url` is set from `notifyUrl`. If `MERCADOPAGO_WEBHOOK_SECRET` is set, the `x-signature` header is checked; the payment is always re-read from the API. Only `approved` counts as paid.

## Refunds

`Payment::refund('mercadopago', $paymentId, 20.00)`.
