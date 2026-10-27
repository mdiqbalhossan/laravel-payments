<?php

namespace Mdiqbal\LaravelPayments\Exceptions;

use Exception;

class InvalidSignatureException extends PaymentException
{
    public function __construct(string $message = "Invalid webhook signature", int $code = 401, ?\Throwable $previous = null, ?string $gateway = null)
    {
        parent::__construct(
            message: $message,
            code: $code,
            previous: $previous,
            gateway: $gateway
        );
    }

    /**
     * Create for gateway
     */
    public static function forGateway(string $gateway): self
    {
        return new self(
            message: "Invalid webhook signature for gateway '{$gateway}'",
            gateway: $gateway
        );
    }
}