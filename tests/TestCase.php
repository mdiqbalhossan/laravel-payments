<?php

namespace Mdiqbal\LaravelPayments\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Mdiqbal\LaravelPayments\PaymentsServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders($app)
    {
        return [
            PaymentsServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // Set up test configuration
        $app['config']->set('payments.default', 'stripe');
        $app['config']->set('payments.mode', 'sandbox');

        // Set up test gateway configurations
        $app['config']->set('payments.gateways', [
            'stripe' => [
                'mode' => 'sandbox',
                'sandbox' => [
                    'secret_key' => 'sk_test_dummy',
                    'api_key' => 'pk_test_dummy',
                ],
            ],
            'paypal' => [
                'mode' => 'sandbox',
                'sandbox' => [
                    'client_id' => 'test_client_id',
                    'client_secret' => 'test_client_secret',
                ],
            ],
        ]);
    }
}