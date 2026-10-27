# SSLCommerz Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
SSLCOMMERZ_MODE=
SSLCOMMERZ_SANDBOX_STORE_NAME=
SSLCOMMERZ_SANDBOX_STORE_ID=
SSLCOMMERZ_SANDBOX_STORE_PASSWORD=
SSLCOMMERZ_LIVE_STORE_NAME=
SSLCOMMERZ_LIVE_STORE_ID=
SSLCOMMERZ_LIVE_STORE_PASSWORD=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `SSLCOMMERZ_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` creates a v4 hosted session and returns `GatewayPageURL`. tran_id (your order id, required) is the transaction id. A customer email is required.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('sslcommerz', new PaymentRequest(
    amount: 100.00,
    currency: 'BDT',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'sslcommerz'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/sslcommerz'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// SSLCommerz POSTs to success_url with val_id, tran_id, ...
$result = Payment::verify('sslcommerz', $request->all()); // validated with the Validation API

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Pass `notifyUrl: url('/payments/webhook/sslcommerz')` as the IPN URL. The IPN `verify_sign`/`verify_key` hash is checked, then `val_id` is validated via the API. Fail URL defaults to the cancel URL; you can also set `success_url`, `fail_url`, `cancel_url`, `ipn_url` in the mode block.

## Refunds

`Payment::refund('sslcommerz', $bankTranIdOrTranId, 500)` — your tran_id is resolved to bank_tran_id automatically; refunds are processed asynchronously.
