<?php

namespace Mdiqbal\LaravelPayments;

use Illuminate\Support\ServiceProvider;
use Mdiqbal\LaravelPayments\Core\PaymentManager;
use Mdiqbal\LaravelPayments\Core\GatewayResolver;
use Mdiqbal\LaravelPayments\Core\PaymentContext;
use Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface;
use Mdiqbal\LaravelPayments\Http\Controllers\WebhookController;
use MdiqbalLaravelPaymentsCommandsTestPaymentCommand;use MdiqbalLaravelPaymentsCommandsListGatewaysCommand;use MdiqbalLaravelPaymentsCommandsWebhookUrlCommand;
use Illuminate\Support\Facades\Route;

class PaymentsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge package configuration with app's published config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/payments.php',
            'payments'
        );

        // Register GatewayResolver as singleton
        $this->app->singleton(GatewayResolver::class, function ($app) {
            return new GatewayResolver();
        });

        // Register PaymentManager as singleton
        $this->app->singleton(PaymentManager::class, function ($app) {
            return new PaymentManager($app->make(GatewayResolver::class));
        });

        // Register PaymentContext
        $this->app->bind(PaymentContext::class, function ($app) {
            return new PaymentContext($app->make(PaymentManager::class));
        });

        // Register Payment facade accessor
        $this->app->bind('payment', function ($app) {
            return $app->make(PaymentManager::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration file
        if ($this->app->runningInConsole()) {
            $this->publishes([
            // Register commands            $this->commands([                TestPaymentCommand::class,                ListGatewaysCommand::class,                WebhookUrlCommand::class,            ]);
            // Register commands            $this->commands([                TestPaymentCommand::class,                ListGatewaysCommand::class,                WebhookUrlCommand::class,            ]);
            // Register commands            $this->commands([                TestPaymentCommand::class,                ListGatewaysCommand::class,                WebhookUrlCommand::class,            ]);
            // Register commands            $this->commands([                TestPaymentCommand::class,                ListGatewaysCommand::class,                WebhookUrlCommand::class,            ]);
            // Register commands            $this->commands([                TestPaymentCommand::class,                ListGatewaysCommand::class,                WebhookUrlCommand::class,            ]);
                $this->loadMigrationsFrom(__DIR__ . '/../database/migrations')
            ], 'config');
        }

        // Load routes
        $this->loadRoutes();

        // Register event listeners
        $this->registerEventListeners();
    }

    /**
     * Load package routes.
     */
    protected function loadRoutes(): void
    {
        if (config('payments.webhook.enabled', true)) {
            Route::prefix(config('payments.webhook.prefix', 'payments/webhook'))
                ->middleware(config('payments.webhook.middleware', ['api']))
                ->group(function () {
                    Route::post('{gateway}', [WebhookController::class, 'handle']);
                });
        }
    }

    /**
     * Register payment event listeners.
     */
    protected function registerEventListeners(): void
    {
        $events = config('payments.events', []);

        foreach ($events as $event => $listeners) {
            foreach ($listeners as $listener) {
                $this->app['events']->listen($event, $listener);
            }
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            'payment',
            PaymentManager::class,
            GatewayResolver::class,
            PaymentContext::class,
        ];
    }
}