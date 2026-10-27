# PayFast Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
PAYFAST_MODE=
PAYFAST_SANDBOX_MERCHANT_ID=
PAYFAST_SANDBOX_MERCHANT_KEY=
PAYFAST_SANDBOX_PASS_PHRASE=
PAYFAST_LIVE_MERCHANT_ID=
PAYFAST_LIVE_MERCHANT_KEY=
PAYFAST_LIVE_PASS_PHRASE=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `PAYFAST_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` returns the process URL plus signed `form_params` that must be **POSTed** (auto-submit form).
`returnUrl` and `notifyUrl` are required. ZAR only.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('payfast', new PaymentRequest(
    amount: 100.00,
    currency: 'ZAR',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'payfast'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/payfast'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// PayFast sends the ITN to notify_url
$result = Payment::verify('payfast', $request->all());

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Use `notifyUrl: url('/payments/webhook/payfast')`. The ITN signature (with your passphrase) is checked and confirmed with PayFast's validate endpoint.

## Refunds

Not supported (`supportsRefund()` is false).
