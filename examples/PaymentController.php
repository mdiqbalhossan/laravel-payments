<?php

namespace Mdiqbal\LaravelPayments\Examples;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Show payment form
     */
    public function create()
    {
        return view('payments.create');
    }

    /**
     * Process payment
     */
    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'currency' => 'required|string|size:3',
            'customer_email' => 'required|email',
            'gateway' => 'required|string|in:stripe,paypal,razorpay'
        ]);

        try {
            $paymentRequest = new PaymentRequest(
                orderId: 'ORDER_' . uniqid(),
                amount: (float) $request->amount,
                currency: strtoupper($request->currency),
                customerEmail: $request->customer_email,
                callbackUrl: route('payment.callback'),
                webhookUrl: route('payment.webhook'),
                customerName: $request->customer_name,
                description: $request->description,
                meta: [
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip()
                ]
            );

            $response = Payment::gateway($request->gateway)->pay($paymentRequest);

            if ($response->requiresRedirect()) {
                // Store transaction in session/database
                session(['payment_data' => [
                    'order_id' => $paymentRequest->orderId,
                    'amount' => $paymentRequest->amount,
                    'gateway' => $request->gateway
                ]]);

                return redirect($response->redirectUrl);
            }

            if ($response->success) {
                return redirect()->route('payment.success')
                    ->with('transaction_id', $response->transactionId);
            }

            return back()->withErrors(['payment' => $response->message]);

        } catch (\Exception $e) {
            Log::error('Payment processing failed', [
                'error' => $e->getMessage(),
                'gateway' => $request->gateway
            ]);

            return back()->withErrors(['payment' => 'Payment processing failed. Please try again.']);
        }
    }

    /**
     * Handle payment callback
     */
    public function callback(Request $request)
    {
        $paymentData = session('payment_data');

        if (!$paymentData) {
            return redirect()->route('payment.create')
                ->withErrors(['payment' => 'Payment session expired']);
        }

        // Verify payment status
        try {
            $response = Payment::verify($paymentData['gateway'], $request->all());

            if ($response->success) {
                session()->forget('payment_data');
                return redirect()->route('payment.success')
                    ->with('transaction_id', $response->transactionId);
            }

            return redirect()->route('payment.failed')
                ->with('message', $response->message);

        } catch (\Exception $e) {
            Log::error('Payment verification failed', [
                'error' => $e->getMessage(),
                'payment_data' => $paymentData
            ]);

            return redirect()->route('payment.failed')
                ->with('message', 'Payment verification failed');
        }
    }

    /**
     * Payment success page
     */
    public function success()
    {
        $transactionId = session('transaction_id');
        return view('payments.success', compact('transactionId'));
    }

    /**
     * Payment failed page
     */
    public function failed()
    {
        $message = session('message');
        return view('payments.failed', compact('message'));
    }

    /**
     * Refund payment
     */
    public function refund(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|string',
            'amount' => 'required|numeric|min:0.01'
        ]);

        $gateway = $request->gateway ?? 'stripe';

        if (!Payment::supportsRefund($gateway)) {
            return back()->withErrors(['refund' => 'Refunds are not supported by this gateway']);
        }

        try {
            $result = Payment::refund(
                gateway: $gateway,
                transactionId: $request->transaction_id,
                amount: (float) $request->amount
            );

            if ($result) {
                return back()->with('success', 'Refund processed successfully');
            }

            return back()->withErrors(['refund' => 'Refund failed']);

        } catch (\Exception $e) {
            Log::error('Refund processing failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $request->transaction_id
            ]);

            return back()->withErrors(['refund' => 'Refund processing failed']);
        }
    }

    /**
     * List available gateways
     */
    public function gateways()
    {
        $gateways = Payment::getAvailableGateways();
        $defaultGateway = Payment::getDefaultGateway();

        return view('payments.gateways', compact('gateways', 'defaultGateway'));
    }
}