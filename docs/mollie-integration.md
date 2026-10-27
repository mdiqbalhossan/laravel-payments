# Mollie Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
MOLLIE_MODE=
MOLLIE_SANDBOX_API_KEY=
MOLLIE_LIVE_API_KEY=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `MOLLIE_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` creates a payment and returns the checkout URL. The Mollie payment id (`tr_...`) is the transaction id — store it with the order. Test vs live is decided by the API key prefix.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('mollie', new PaymentRequest(
    amount: 100.00,
    currency: 'EUR',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'mollie'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/mollie'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
$result = Payment::verify('mollie', ['id' => $order->gateway_txn]);

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

Pass `notifyUrl` (must be publicly reachable). Mollie only POSTs `id=tr_...`; the payment is always fetched from the API, so no secret is needed.

## Refunds

`Payment::refund('mollie', 'tr_xxx', 5.00)` — `0` refunds the remaining amount.
