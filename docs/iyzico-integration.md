# iyzico Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
IYZICO_MODE=
IYZICO_SANDBOX_API_KEY=
IYZICO_SANDBOX_SECRET_KEY=
IYZICO_LIVE_API_KEY=
IYZICO_LIVE_SECRET_KEY=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `IYZICO_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` initializes a Checkout Form (IYZWSv2 auth) and returns `paymentPageUrl`; `checkout_form_content`
is in data if you want to embed it. Pass buyer details in `customer` (`name`, `phone`,
`identity_number`, `address`, `city`, `country`); a customer email is required.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('iyzico', new PaymentRequest(
    amount: 100.00,
    currency: 'TRY',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'iyzico'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/iyzico'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// iyzico POSTs token to callbackUrl
$result = Payment::verify('iyzico', ['token' => $request->input('token')]);
$paymentId = $result->gatewayReference; // keep for refunds

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Not used; call `verify()` from the callback.

## Refunds

`Payment::refund('iyzico', $paymentId, 50.00)`.
