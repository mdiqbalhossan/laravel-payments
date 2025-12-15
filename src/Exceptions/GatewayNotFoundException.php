<?php

namespace Mdiqbal\LaravelPayments\Exceptions;

use Exception;

class GatewayNotFoundException extends PaymentException
{
    public function __construct(string $gateway, int $code = 404, ?Throwable $previous = null)
    {
        parent::__construct(
            message: "Payment gateway '{$gateway}' not found or not configured",
            code: $code,
            previous: $previous,
            gateway: $gateway
        );
    }

    /**
     * Create a static instance
     */
    public static function create(string $gateway): self
    {
        return new self($gateway);
    }
}