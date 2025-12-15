<?php

/**
 * Complete Example of Using Laravel Payments Package
 *
 * This file demonstrates all major features of the package
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\WebhookPayload;

echo "=== Laravel Payments Package Example ===\n\n";

// 1. Create Payment Request
echo "1. Creating Payment Request...\n";
$request = new PaymentRequest(
    orderId: 'ORD_' . time(),
    amount: 99.99,
    currency: 'USD',
    customerEmail: 'customer@example.com',
    callbackUrl: 'https://example.com/callback',
    webhookUrl: 'https://example.com/webhook',
    customerName: 'John Doe',
    description: 'Test Payment from Package',
    meta: [
        'product_id' => 'PROD_123',
        'category' => 'electronics'
    ]
);

echo "   Order ID: {$request->orderId}\n";
echo "   Amount: {$request->amount} {$request->currency}\n";
echo "   Customer: {$request->customerEmail}\n\n";

// 2. Get Available Gateways
echo "2. Available Payment Gateways:\n";
$gateways = Payment::getAvailableGateways();
foreach ($gateways as $gateway) {
    $supportsRefund = Payment::supportsRefund($gateway) ? '✓' : '✗';
    echo "   - {$gateway} (Refund: {$supportsRefund})\n";
}
echo "\n";

// 3. Process Payment with Different Gateways
echo "3. Processing Payment Examples:\n";

// Stripe Example
echo "   Stripe Payment:\n";
try {
    $stripeResponse = Payment::gateway('stripe')->pay($request);
    if ($stripeResponse->requiresRedirect()) {
        echo "     ✓ Redirect URL: {$stripeResponse->redirectUrl}\n";
    } elseif ($stripeResponse->success) {
        echo "     ✓ Transaction ID: {$stripeResponse->transactionId}\n";
    } else {
        echo "     ✗ Error: {$stripeResponse->message}\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: {$e->getMessage()}\n";
}

// PayPal Example
echo "\n   PayPal Payment:\n";
try {
    $paypalResponse = Payment::gateway('paypal')->pay($request);
    if ($paypalResponse->requiresRedirect()) {
        echo "     ✓ Redirect URL: {$paypalResponse->redirectUrl}\n";
    } elseif ($paypalResponse->success) {
        echo "     ✓ Transaction ID: {$paypalResponse->transactionId}\n";
    } else {
        echo "     ✗ Error: {$paypalResponse->message}\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: {$e->getMessage()}\n";
}

// 4. Using Payment Context (Fluent Interface)
echo "\n4. Payment Context Example:\n";
try {
    $contextResponse = Payment::context()
        ->using('razorpay')
        ->with($request)
        ->execute();

    echo "   ✓ Payment processed via context\n";
    echo "   ✓ Status: {$contextResponse->status}\n";
} catch (Exception $e) {
    echo "   ✗ Error: {$e->getMessage()}\n";
}

// 5. Webhook Verification Example
echo "\n5. Webhook Verification Example:\n";
$webhookData = [
    'id' => 'evt_' . time(),
    'type' => 'payment_intent.succeeded',
    'data' => [
        'object' => [
            'id' => 'pi_test',
            'amount' => 9999,
            'currency' => 'usd',
            'status' => 'succeeded'
        ]
    ]
];

try {
    $webhookResponse = Payment::verify('stripe', $webhookData);
    echo "   ✓ Webhook verified\n";
    echo "   ✓ Transaction ID: {$webhookResponse->transactionId}\n";
    echo "   ✓ Status: {$webhookResponse->status}\n";
} catch (Exception $e) {
    echo "   ✗ Webhook verification failed: {$e->getMessage()}\n";
}

// 6. Refund Example
echo "\n6. Refund Example:\n";
$transactionId = 'pi_test_' . time();
$refundAmount = 50.00;

try {
    if (Payment::supportsRefund('stripe')) {
        $refundResult = Payment::refund('stripe', $transactionId, $refundAmount);
        echo "   ✓ Refund processed: " . ($refundResult ? 'Success' : 'Failed') . "\n";
    } else {
        echo "   ✗ Stripe does not support refunds\n";
    }
} catch (Exception $e) {
    echo "   ✗ Refund failed: {$e->getMessage()}\n";
}

// 7. Gateway Configuration Example
echo "\n7. Gateway Configuration:\n";
$stripeGateway = Payment::gateway('stripe');
echo "   Gateway Name: " . $stripeGateway->gatewayName() . "\n";
echo "   Mode: " . $stripeGateway->getMode() . "\n";
echo "   Refunds Supported: " . ($stripeGateway->supportsRefund() ? 'Yes' : 'No') . "\n";

// 8. Error Handling Example
echo "\n8. Error Handling Example:\n";
try {
    Payment::gateway('invalid_gateway');
} catch (\Mdiqbal\LaravelPayments\Exceptions\GatewayNotFoundException $e) {
    echo "   ✓ Caught GatewayNotFoundException\n";
    echo "   ✓ Message: {$e->getMessage()}\n";
}

echo "\n=== Example Completed ===\n";
echo "\nNote: This is a demonstration of the API structure.\n";
echo "Actual gateway implementations need to be completed.\n";