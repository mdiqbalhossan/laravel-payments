<?php

namespace Mdiqbal\LaravelPayments\Commands;

use Illuminate\Console\Command;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Symfony\Component\Console\Helper\Table;

class TestPaymentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:test
                            {gateway? : The gateway to test (default: all)}
                            {--amount=100.00 : The amount to test}
                            {--currency=USD : The currency to use}
                            {--email=test@example.com : Test email}
                            {--mode=sandbox : Test mode (sandbox/live)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test payment gateway configuration and connectivity';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $gateway = $this->argument('gateway');
        $amount = (float) $this->option('amount');
        $currency = $this->option('currency');
        $email = $this->option('email');
        $mode = $this->option('mode');

        $this->info('🚀 Laravel Payments - Gateway Testing');
        $this->info('=====================================');

        // Get available gateways
        $availableGateways = Payment::getAvailableGateways();

        if ($gateway && !in_array($gateway, $availableGateways)) {
            $this->error("❌ Gateway '{$gateway}' is not supported!");
            $this->line("Available gateways: " . implode(', ', $availableGateways));
            return 1;
        }

        // Configuration check
        $this->checkConfiguration();

        // Test specified gateway or all
        $gatewaysToTest = $gateway ? [$gateway] : $availableGateways;

        foreach ($gatewaysToTest as $gw) {
            $this->testGateway($gw, $amount, $currency, $email, $mode);
        }

        $this->info("\n✅ Testing completed!");
        return 0;
    }

    /**
     * Check package configuration
     */
    protected function checkConfiguration()
    {
        $this->info("\n📋 Configuration Check:");
        $this->line('-------------------');

        $config = config('payments');
        $defaultGateway = Payment::getDefaultGateway();

        // Check default gateway
        if ($defaultGateway) {
            $this->info("✅ Default Gateway: {$defaultGateway}");
        } else {
            $this->warn("⚠️  No default gateway configured");
        }

        // Check mode
        $mode = $config['mode'] ?? 'sandbox';
        $this->info("✅ Mode: {$mode}");

        // Check webhook configuration
        if (isset($config['webhook'])) {
            $prefix = $config['webhook']['prefix'] ?? 'payments/webhook';
            $this->info("✅ Webhook URL: /{$prefix}/{gateway}");
        }

        // Check configured gateways
        $configuredGateways = array_keys($config['gateways'] ?? []);
        if (!empty($configuredGateways)) {
            $this->info("✅ Configured Gateways: " . count($configuredGateways));
        }
    }

    /**
     * Test a specific gateway
     */
    protected function testGateway(string $gateway, float $amount, string $currency, string $email, string $mode)
    {
        $this->info("\n🔍 Testing Gateway: " . strtoupper($gateway));
        $this->line(str_repeat('-', 40));

        try {
            // Check if gateway exists
            if (!Payment::hasGateway($gateway)) {
                $this->error("❌ Gateway not found!");
                return;
            }

            // Get gateway instance
            $gatewayInstance = Payment::gateway($gateway);
            $this->info("✅ Gateway loaded successfully");
            $this->info("   Name: " . $gatewayInstance->gatewayName());
            $this->info("   Mode: " . $gatewayInstance->getMode());
            $this->info("   Refunds: " . ($gatewayInstance->supportsRefund() ? '✅' : '❌'));

            // Check configuration
            $config = $gatewayInstance->getConfig();
            if (empty($config)) {
                $this->warn("⚠️  No configuration found for this gateway");
            } else {
                $this->info("✅ Configuration loaded");

                // Show masked credentials of the active mode
                $active = array_merge(
                    array_filter($config, fn ($v) => !is_array($v)),
                    (array) ($config[$gatewayInstance->getMode()] ?? [])
                );

                foreach ($active as $key => $value) {
                    if (is_string($value) && !empty($value)) {
                        if (str_contains(strtolower($key), 'secret') ||
                            str_contains(strtolower($key), 'key') ||
                            str_contains(strtolower($key), 'password') ||
                            str_contains(strtolower($key), 'token')) {
                            $masked = str_repeat('*', max(4, strlen($value) - 4)) . substr($value, -4);
                            $this->line("   {$key}: {$masked}");
                        } else {
                            $this->line("   {$key}: {$value}");
                        }
                    }
                }
            }

            // Test payment request
            $request = new PaymentRequest(
                amount: $amount,
                currency: $currency,
                description: "Test payment for {$gateway} gateway",
                orderId: 'TEST_' . time() . '_' . substr(uniqid(), -6),
                email: $email,
                customer: ['name' => 'Test Customer', 'phone' => '01700000000'],
                returnUrl: url('/payments/test/return'),
                cancelUrl: url('/payments/test/cancel'),
                notifyUrl: url("/payments/webhook/{$gateway}")
            );

            // Process payment
            $this->info("\n💳 Processing Test Payment...");
            $response = $gatewayInstance->pay($request);

            // Display response
            $this->info("✅ Payment request processed");

            $table = new Table($this->output);
            $table->setHeaders(['Field', 'Value']);

            $table->addRow(['Success', $response->success ? '✅' : '❌']);
            $table->addRow(['Status', $response->status]);

            if ($response->transactionId) {
                $table->addRow(['Transaction ID', $response->transactionId]);
            }

            if ($response->redirectUrl) {
                $table->addRow(['Redirect URL', substr($response->redirectUrl, 0, 50) . '...']);
            }

            if ($response->message) {
                $table->addRow(['Message', $response->message]);
            }

            $table->render();

            if ($response->redirectUrl) {
                $this->line("\nOpen the redirect URL to complete the sandbox payment, then verify it with Payment::verify('{$gateway}', ...).");
            }
        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());

            if (config('app.debug')) {
                $this->line("\nStack trace:");
                $this->line($e->getTraceAsString());
            }
        }
    }
}