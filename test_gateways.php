<?php

// Simple test script to check if all gateway files can be loaded without fatal errors
require_once __DIR__ . '/vendor/autoload.php';

$gatewayFiles = [
    'src/Gateways/Bkash/BkashGateway.php',
    'src/Gateways/Cashfree/CashfreeGateway.php',
    'src/Gateways/EasyPaisa/EasyPaisaGateway.php',
    'src/Gateways/Flutterwave/FlutterwaveGateway.php',
    'src/Gateways/Iyzico/IyzicoGateway.php',
    'src/Gateways/Midtrans/MidtransGateway.php',
    'src/Gateways/Mollie/MollieGateway.php',
    'src/Gateways/MyFatoorah/MyfatoorahGateway.php',
    'src/Gateways/Paypal/PaypalGateway.php',
    'src/Gateways/Paytm/PaytmGateway.php',
    'src/Gateways/Pesapal/PesapalGateway.php',
    'src/Gateways/Senangpay/SenangpayGateway.php',
    'src/Gateways/Sslcommerz/SslcommerzGateway.php',
    'src/Gateways/Telr/TelrGateway.php'
];

echo "Testing gateway files for syntax errors...\n\n";

$errors = [];
$success = [];

foreach ($gatewayFiles as $file) {
    echo "Testing $file... ";

    // Check if file exists
    if (!file_exists($file)) {
        echo "❌ FILE NOT FOUND\n";
        $errors[] = "$file: File not found";
        continue;
    }

    // Try to include and check syntax
    $output = [];
    $returnCode = 0;

    // Use php -l for syntax checking (if available)
    exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnCode);

    if ($returnCode === 0) {
        echo "✅ OK\n";
        $success[] = $file;
    } else {
        echo "❌ SYNTAX ERROR\n";
        $errors[] = "$file: " . implode("\n", $output);
    }
}

echo "\n=== SUMMARY ===\n";
echo "✅ Successful: " . count($success) . "\n";
echo "❌ Errors: " . count($errors) . "\n\n";

if (!empty($errors)) {
    echo "ERRORS:\n";
    foreach ($errors as $error) {
        echo "- $error\n";
    }
    exit(1);
} else {
    echo "🎉 All gateway files passed syntax check!\n";
    exit(0);
}