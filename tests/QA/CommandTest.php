<?php

namespace Mdiqbal\LaravelPayments\Tests\QA;

use Illuminate\Support\Facades\Http;
use Mdiqbal\LaravelPayments\Tests\TestCase;

class CommandTest extends TestCase
{
    public function test_artisan_commands_run(): void
    {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => 'r',
        ]])]);

        $this->artisan('payments:list')->assertExitCode(0);
        $this->artisan('payments:webhook-url')->assertExitCode(0);
        $this->artisan('payments:test', ['gateway' => 'paystack', '--currency' => 'NGN'])
            ->expectsOutputToContain('Payment request processed')
            ->doesntExpectOutputToContain('sk_test_dummy');
    }
}
