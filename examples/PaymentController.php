<?php

/**
 * Example controller showing the full flow with any gateway:
 * checkout -> redirect/POST -> return URL verify -> webhook -> refund.
 *
 * Routes:
 *   Route::post('/checkout/{order}/{gateway}', [PaymentController::class, 'checkout']);
 *   Route::match(['get', 'post'], '/payment/return/{gateway}', [PaymentController::class, 'return'])->name('payment.return');
 *   Route::get('/payment/cancel', [PaymentController::class, 'cancel'])->name('payment.cancel');
 *
 * Exclude 'payment/return/*' and 'payments/webhook/*' from CSRF verification:
 * several gateways POST back to these URLs.
 */

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\Events\WebhookHandled;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use Mdiqbal\LaravelPayments\Facades\Payment;

class PaymentController extends Controller
{
    public function checkout(Order $order, string $gateway)
    {
        try {
            $response = Payment::pay($gateway, new PaymentRequest(
                amount: $order->total,
                currency: $order->currency,
                description: "Order #{$order->id}",
                orderId: (string) $order->id,
                email: $order->customer_email,
                customer: ['name' => $order->customer_name, 'phone' => $order->customer_phone],
                returnUrl: route('payment.return', $gateway),
                cancelUrl: route('payment.cancel'),
                notifyUrl: url("/payments/webhook/{$gateway}"),
            ));
        } catch (PaymentException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        // Keep the gateway's id: verify() and refund() need it
        $order->update(['gateway' => $gateway, 'gateway_txn' => $response->transactionId]);

        if ($response->getData('redirect_method') === 'POST') {
            // Paytm, PayFast, Easypaisa: auto-submitting form
            return view('payments.post-redirect', [
                'action' => $response->redirectUrl,
                'fields' => $response->getData('form_params'),
            ]);
        }

        if ($response->requiresRedirect()) {
            return redirect()->away($response->redirectUrl);
        }

        // Stripe (client_secret), Razorpay (order id + key_id), Cashfree (payment_session_id)
        return view('payments.js-checkout', ['gateway' => $gateway, 'response' => $response]);
    }

    public function return(Request $request, string $gateway)
    {
        $order = Order::where('gateway_txn', $request->input('transaction_id'))->first()
            ?? Order::findOrFail(session('checkout_order_id'));

        // Some gateways need the stored id rather than callback data (Mollie, Telr)
        $payload = $request->all() + ['id' => $order->gateway_txn, 'order_ref' => $order->gateway_txn];

        try {
            $result = Payment::verify($gateway, $payload);
        } catch (PaymentException $e) {
            return redirect()->route('orders.show', $order)->withErrors(['payment' => $e->getMessage()]);
        }

        if ($result->success && $result->matchesAmount($order->total, $order->currency)) {
            $order->markPaid($result->transactionId);
        }

        return redirect()->route('orders.show', $order);
    }

    public function cancel()
    {
        return redirect()->route('cart')->withErrors(['payment' => 'Payment was cancelled.']);
    }

    public function refund(Order $order)
    {
        if (!Payment::supportsRefund($order->gateway)) {
            return back()->withErrors(['refund' => 'This gateway does not support API refunds.']);
        }

        Payment::refund($order->gateway, $order->gateway_txn, $order->total);

        $order->update(['status' => 'refunded']);

        return back();
    }

    /**
     * Register in a service provider's boot() method.
     */
    public static function listenForWebhooks(): void
    {
        Event::listen(WebhookHandled::class, function (WebhookHandled $event) {
            $order = Order::where('gateway_txn', $event->response->transactionId)->first();

            if ($order && $event->response->success && $event->response->matchesAmount($order->total, $order->currency)) {
                $order->markPaid($event->response->transactionId);
            }
        });
    }
}
