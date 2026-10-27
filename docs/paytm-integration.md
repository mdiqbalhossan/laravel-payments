# Paytm Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
PAYTM_MODE=
PAYTM_SANDBOX_MERCHANT_ID=
PAYTM_SANDBOX_MERCHANT_KEY=
PAYTM_LIVE_MERCHANT_ID=
PAYTM_LIVE_MERCHANT_KEY=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `PAYTM_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` calls the Initiate Transaction API (signed with the official PaytmChecksum algorithm)
and returns the payment page URL plus `form_params` (mid, orderId, txnToken) that must be
**POSTed** to it. Alternatively use `txn_token` with Paytm JS Checkout.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('paytm', new PaymentRequest(
    amount: 100.00,
    currency: 'INR',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'paytm'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/paytm'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// Paytm POSTs to callbackUrl with CHECKSUMHASH; verify() checks it and calls the Order Status API
$result = Payment::verify('paytm', $request->all());

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Use the callback POST as above (it is checksum-protected). Optional config: `website` (defaults to WEBSTAGING/DEFAULT).

## Refunds

`Payment::refund('paytm', $orderId, 50.00)` — asynchronous; `true` means Paytm accepted the request.
