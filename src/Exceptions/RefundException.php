<?php

namespace Mdiqbal\LaravelPayments\Exceptions;

class RefundException extends PaymentException
{
    protected ?string $refundId = null;

    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null, ?string $gateway = null, ?string $transactionId = null, ?string $refundId = null)
    {
        parent::__construct($message, $code, $previous, $gateway, $transactionId);
        $this->refundId = $refundId;
    }

    /**
     * Get the refund ID
     */
    public function getRefundId(): ?string
    {
        return $this->refundId;
    }

    /**
     * Create refund failed exception
     */
    public static function failed(string $gateway, string $transactionId, string $reason): self
    {
        return new self(
            message: "Refund failed for transaction '{$transactionId}' on gateway '{$gateway}': {$reason}",
            gateway: $gateway,
            transactionId: $transactionId
        );
    }

    /**
     * Create refund not supported exception
     */
    public static function notSupported(string $gateway): self
    {
        return new self(
            message: "Refunds are not supported by gateway '{$gateway}'",
            code: 501,
            gateway: $gateway
        );
    }

    /**
     * Create refund amount mismatch exception
     */
    public static function amountMismatch(string $gateway, string $transactionId, float $requested, float $allowed): self
    {
        return new self(
            message: "Refund amount mismatch for transaction '{$transactionId}'. Requested: {$requested}, Allowed: {$allowed}",
            gateway: $gateway,
            transactionId: $transactionId,
            context: ['requested_amount' => $requested, 'allowed_amount' => $allowed]
        );
    }
}