<?php

namespace Mdiqbal\LaravelPayments\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class WebhookUrlCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:webhook-url
                            {--show-all : Show webhook URLs for all gateways}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show webhook URLs for payment gateways';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔗 Laravel Payments - Webhook URLs');
        $this->info('===================================');

        $webhookPrefix = config('payments.webhook.prefix', 'payments/webhook');
        $baseUrl = config('app.url', 'http://localhost');
        $showAll = $this->option('show-all');

        // Construct full webhook URLs
        $webhookBaseUrl = rtrim($baseUrl, '/') . '/' . ltrim($webhookPrefix, '/');

        $this->line("\n📡 Base Webhook URL:");
        $this->info("   {$webhookBaseUrl}/{gateway}");

        $this->line("\n🏦 Gateway-specific URLs:");
        $this->line("   PayPal:   {$webhookBaseUrl}/paypal");
        $this->line("   Stripe:   {$webhookBaseUrl}/stripe");
        $this->line("   Razorpay: {$webhookBaseUrl}/razorpay");
        $this->line("   Paystack: {$webhookBaseUrl}/paystack");
        $this->line("   Paytm:    {$webhookBaseUrl}/paytm");
        $this->line("   Flutterwave: {$webhookBaseUrl}/flutterwave");
        $this->line("   SSLCommerz: {$webhookBaseUrl}/sslcommerz");
        $this->line("   Mollie:   {$webhookBaseUrl}/mollie");
        $this->line("   Senangpay: {$webhookBaseUrl}/senangpay");
        $this->line("   bKash:    {$webhookBaseUrl}/bkash");
        $this->line("   Mercado Pago: {$webhookBaseUrl}/mercadopago");
        $this->line("   Cashfree: {$webhookBaseUrl}/cashfree");
        $this->line("   Payfast:  {$webhookBaseUrl}/payfast");
        $this->line("   Skrill:   {$webhookBaseUrl}/skrill");
        $this->line("   PhonePe:  {$webhookBaseUrl}/phonepe");
        $this->line("   Telr:     {$webhookBaseUrl}/telr");
        $this->line("   Iyzico:   {$webhookBaseUrl}/iyzico");
        $this->line("   Pesapal:  {$webhookBaseUrl}/pesapal");
        $this->line("   Midtrans: {$webhookBaseUrl}/midtrans");
        $this->line("   MyFatoorah: {$webhookBaseUrl}/myfatoorah");
        $this->line("   EasyPaisa: {$webhookBaseUrl}/easypaisa");

        // Show webhook configuration
        $this->line("\n⚙️ Webhook Configuration:");
        $webhookConfig = config('payments.webhook', []);
        $this->line("   Prefix: " . ($webhookConfig['prefix'] ?? 'payments/webhook'));
        $this->line("   Middleware: " . implode(', ', $webhookConfig['middleware'] ?? ['api']));

        // Show testing instructions
        $this->line("\n🧪 Testing Webhooks:");
        $this->line("   You can test webhooks using curl:");
        $this->info("   curl -X POST {$webhookBaseUrl}/stripe \\");
        $this->line("        -H 'Content-Type: application/json' \\");
        $this->line("        -d '{\"type\":\"payment_intent.succeeded\",\"data\":{\"object\":{\"id\":\"pi_test\",\"status\":\"succeeded\"}}}'");

        // Show security recommendations
        $this->line("\n🔒 Security Recommendations:");
        $this->line("   1. Always use HTTPS in production");
        $this->line("   2. Configure webhook secrets in your gateway dashboard");
        $this->line("   3. Consider IP whitelisting for webhook endpoints");
        $this->line("   4. Validate webhook signatures in your handlers");

        // Show integration notes
        $this->line("\n📝 Integration Notes:");
        $this->line("   • Webhook routes are automatically registered");
        $this->line("   • You can listen to payment events in your EventServiceProvider");
        $this->line("   • Example: php artisan event:generate");

        return 0;
    }
}