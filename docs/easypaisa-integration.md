# Easypaisa Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
EASYPAISA_MODE=
EASYPAISA_SANDBOX_STORE_ID=
EASYPAISA_SANDBOX_HASH_KEY=
EASYPAISA_SANDBOX_USERNAME=
EASYPAISA_SANDBOX_PASSWORD=
EASYPAISA_SANDBOX_ACCOUNT_NUM=
EASYPAISA_LIVE_STORE_ID=
EASYPAISA_LIVE_HASH_KEY=
EASYPAISA_LIVE_USERNAME=
EASYPAISA_LIVE_PASSWORD=
EASYPAISA_LIVE_ACCOUNT_NUM=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `EASYPAISA_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` returns the Easypay `Index.jsf` URL with `form_params` (including `merchantHashedReq`) to **POST**.
Easypay redirects to `postBackURL` with `auth_token`; POST `auth_token` and `postBackURL` to
`$response->getData('confirm_url')`. PKR only.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('easypaisa', new PaymentRequest(
    amount: 100.00,
    currency: 'PKR',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'easypaisa'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/easypaisa'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// Final redirect carries orderRefNumber
$result = Payment::verify('easypaisa', $request->only('orderRefNumber')); // Inquire Transaction API

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Not used; call `verify()` after the final redirect. Requires `username`, `password` and `account_num` for the inquiry API.

## Refunds

Not supported (`supportsRefund()` is false).
