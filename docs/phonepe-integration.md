# PhonePe Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
PHONEPE_MODE=
PHONEPE_SANDBOX_MERCHANT_ID=
PHONEPE_SANDBOX_CLIENT_ID=
PHONEPE_SANDBOX_MERCHANT_USER_ID=
PHONEPE_SANDBOX_SALT_INDEX=
PHONEPE_SANDBOX_KEY_INDEX=
PHONEPE_SANDBOX_SALT_KEY=
PHONEPE_SANDBOX_SECRET_KEY=
PHONEPE_LIVE_MERCHANT_ID=
PHONEPE_LIVE_CLIENT_ID=
PHONEPE_LIVE_MERCHANT_USER_ID=
PHONEPE_LIVE_SALT_INDEX=
PHONEPE_LIVE_KEY_INDEX=
PHONEPE_LIVE_SALT_KEY=
PHONEPE_LIVE_SECRET_KEY=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `PHONEPE_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` calls the PG Standard Checkout pay API (X-VERIFY signed; the salt key is never sent) and returns the pay page URL. merchantTransactionId (your order id) is the transaction id. INR only.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('phonepe', new PaymentRequest(
    amount: 100.00,
    currency: 'INR',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'phonepe'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/phonepe'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
$result = Payment::verify('phonepe', ['merchantTransactionId' => $order->id]); // Status API

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Use `notifyUrl: url('/payments/webhook/phonepe')` as callbackUrl. The `X-VERIFY` header of the `{"response": base64}` body is verified, then the Status API is called.

## Refunds

`Payment::refund('phonepe', $merchantTransactionId, 100.00)`. Optional config: `refund_callback_url`.
