<?php

namespace Mdiqbal\LaravelPayments\Commands;

use Illuminate\Console\Command;
use Mdiqbal\LaravelPayments\Facades\Payment;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;

class ListGatewaysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:list
                            {--all : Show all gateways including not configured}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all available payment gateways and their status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🏦 Laravel Payments - Gateway List');
        $this->info('==================================');

        $gateways = Payment::getAvailableGateways();
        $defaultGateway = Payment::getDefaultGateway();
        $showAll = $this->option('all');

        $table = new Table($this->output);
        $table->setHeaders(['Gateway', 'Status', 'Refunds', 'Default', 'Configuration']);

        foreach ($gateways as $gateway) {
            $status = '✅ Available';
            $refundSupport = Payment::supportsRefund($gateway) ? '✅' : '❌';
            $isDefault = $gateway === $defaultGateway ? '✅' : '❌';
            $configStatus = $this->checkConfigStatus($gateway);

            if ($showAll || $configStatus !== 'Not Configured') {
                $table->addRow([
                    strtoupper($gateway),
                    $status,
                    $refundSupport,
                    $isDefault,
                    $configStatus
                ]);

                // Add separator for better readability
                if ($gateway !== $gateways[array_key_last($gateways)]) {
                    $table->addRow(new TableSeparator());
                }
            }
        }

        if ($showAll) {
            $table->render();
        } else {
            // Show only configured gateways
            $this->line("\nShowing configured gateways only. Use --all to show all.");
            $table->render();
        }

        // Show summary
        $this->info("\n📊 Summary:");
        $this->line("- Total Gateways: " . count($gateways));
        $this->line("- Default Gateway: " . ($defaultGateway ?? 'Not set'));
        $this->line("- With Refund Support: " . count(array_filter($gateways, function($g) { return Payment::supportsRefund($g); })));

        return 0;
    }

    /**
     * Check configuration status for a gateway
     */
    protected function checkConfigStatus(string $gateway): string
    {
        try {
            $gatewayInstance = Payment::gateway($gateway);
            $config = $gatewayInstance->getConfig();

            if (empty($config)) {
                return 'Not Configured';
            }

            $requiredKeys = $this->getRequiredConfigKeys($gateway);
            $configuredKeys = 0;

            foreach ($requiredKeys as $key) {
                $mode = $gatewayInstance->getMode();
                $modeKey = $mode . '.' . $key;

                if (!empty($config[$modeKey]) || !empty($config[$key])) {
                    $configuredKeys++;
                }
            }

            if ($configuredKeys === count($requiredKeys)) {
                return '✅ Fully Configured';
            } elseif ($configuredKeys > 0) {
                return '⚠️ Partially Configured';
            } else {
                return '❌ Not Configured';
            }

        } catch (\Exception $e) {
            return '❌ Error';
        }
    }

    /**
     * Get required configuration keys for a gateway
     */
    protected function getRequiredConfigKeys(string $gateway): array
    {
        $requiredKeys = [
            'paypal' => ['client_id', 'client_secret'],
            'stripe' => ['secret_key', 'api_key'],
            'razorpay' => ['key_id', 'key_secret'],
            'paystack' => ['secret_key'],
            'paytm' => ['merchant_id', 'merchant_key'],
            'flutterwave' => ['public_key', 'secret_key'],
            'sslcommerz' => ['store_id', 'store_password'],
            'mollie' => ['api_key'],
            'senangpay' => ['merchant_id', 'secret_key'],
            'bkash' => ['app_key', 'app_secret', 'username', 'password'],
            'mercadopago' => ['client_id', 'client_secret'],
            'cashfree' => ['app_id', 'secret_key'],
            'payfast' => ['merchant_id', 'merchant_key', 'pass_phrase'],
            'skrill' => ['merchant_email', 'api_password'],
            'phonepe' => ['client_id', 'merchant_user_id', 'key_index', 'secret_key'],
            'telr' => ['store_id', 'store_auth_key'],
            'iyzico' => ['api_key', 'secret_key'],
            'pesapal' => ['consumer_key', 'consumer_secret', 'ipn_id'],
            'midtrans' => ['server_key', 'client_key'],
            'myfatoorah' => ['api_key'],
            'easypaisa' => ['store_id', 'hash_key', 'username', 'password'],
        ];

        return $requiredKeys[$gateway] ?? [];
    }
}