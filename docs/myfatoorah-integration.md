# MyFatoorah Integration

> Generated from the current implementation (`src/Gateways`). See the README for the
> general flow (pay → verify → `matchesAmount()` → webhooks).

## Configuration

```env
MYFATOORAH_MODE=
MYFATOORAH_SANDBOX_API_KEY=
MYFATOORAH_LIVE_API_KEY=
MYFATOORAH_COUNTRY=
MYFATOORAH_WEBHOOK_SECRET=
```

Credentials live in the `sandbox` / `live` blocks of `config/payments.php`; `MYFATOORAH_MODE`
selects the block (`sandbox` or `live`). Missing credentials throw `PaymentException`.

## Start a payment

`pay()` calls SendPayment and returns the invoice URL; your order id is sent as CustomerReference and the InvoiceId is in data. Set `MYFATOORAH_COUNTRY` (KW, SA, QA, EG) for live.

```php
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;

$response = Payment::pay('myfatoorah', new PaymentRequest(
    amount: 100.00,
    currency: 'KWD',
    description: 'Order #1001',
    orderId: '1001',
    email: 'buyer@example.com',
    customer: ['name' => 'Buyer Name', 'phone' => '01700000000'],
    returnUrl: route('payment.return', 'myfatoorah'),
    cancelUrl: route('payment.cancel'),
    notifyUrl: url('/payments/webhook/myfatoorah'),
));

$order->update(['gateway_txn' => $response->transactionId]);
```

## Verify

```php
// Callback: ?paymentId=...
$result = Payment::verify('myfatoorah', ['paymentId' => $request->query('paymentId')]);

if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
    // mark the order as paid
}
```

## Webhooks

If `MYFATOORAH_WEBHOOK_SECRET` is set, `MyFatoorah-Signature` is checked; the invoice is always re-read with GetPaymentStatus.

## Refunds

`Payment::refund('myfatoorah', $invoiceId, 5.000)`.
