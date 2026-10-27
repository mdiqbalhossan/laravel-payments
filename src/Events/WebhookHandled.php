<?php

namespace Mdiqbal\LaravelPayments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;

/**
 * Fired by the package webhook route after a gateway has verified an incoming
 * webhook/IPN. Listen to this to update orders: check $response->success and
 * $response->matchesAmount() against your stored order before fulfilling it.
 */
class WebhookHandled
{
    use Dispatchable;

    public function __construct(
        public string $gateway,
        public PaymentResponse $response,
        public array $payload
    ) {}

    public function isSuccessful(): bool
    {
        return $this->response->success;
    }
}
