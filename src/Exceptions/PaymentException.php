<?php

namespace Mdiqbal\LaravelPayments\Exceptions;

use Exception;

class PaymentException extends Exception
{
    protected ?string $gateway = null;
    protected ?string $transactionId = null;
    protected array $context = [];

    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null, ?string $gateway = null, ?string $transactionId = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->gateway = $gateway;
        $this->transactionId = $transactionId;
        $this->context = $context;
    }

    /**
     * Get the gateway name
     */
    public function getGateway(): ?string
    {
        return $this->gateway;
    }

    /**
     * Get the transaction ID
     */
    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    /**
     * Get the context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Create a new payment exception with gateway
     */
    public static function gatewayError(string $gateway, string $message, ?string $transactionId = null): self
    {
        return new self(
            message: "Payment gateway error ({$gateway}): {$message}",
            gateway: $gateway,
            transactionId: $transactionId
        );
    }

    /**
     * Create a new validation exception
     */
    public static function validation(string $message, array $errors = []): self
    {
        return new self(
            message: "Validation error: {$message}",
            code: 422,
            context: ['errors' => $errors]
        );
    }

    /**
     * Create a new configuration exception
     */
    public static function configuration(string $gateway, string $message): self
    {
        return new self(
            message: "Configuration error ({$gateway}): {$message}",
            code: 500,
            gateway: $gateway
        );
    }

    /**
     * Create a new network exception
     */
    public static function network(string $gateway, string $message): self
    {
        return new self(
            message: "Network error ({$gateway}): {$message}",
            code: 503,
            gateway: $gateway
        );
    }

    /**
     * Convert exception to array
     */
    public function toArray(): array
    {
        return [
            'error' => true,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'gateway' => $this->gateway,
            'transaction_id' => $this->transactionId,
            'context' => $this->context,
        ];
    }
}