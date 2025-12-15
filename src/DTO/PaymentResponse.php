<?php

namespace Mdiqbal\LaravelPayments\DTO;

use Illuminate\Support\Arr;

class PaymentResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $transactionId = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $message = null,
        public readonly ?string $status = null,
        public readonly array $data = [],
        public readonly ?string $gatewayReference = null,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly array $meta = []
    ) {}

    /**
     * Create a successful response
     */
    public static function success(array $data = []): self
    {
        return new self(
            success: true,
            transactionId: Arr::get($data, 'transaction_id'),
            redirectUrl: Arr::get($data, 'redirect_url'),
            message: Arr::get($data, 'message', 'Payment processed successfully'),
            status: Arr::get($data, 'status', 'success'),
            data: $data,
            gatewayReference: Arr::get($data, 'gateway_reference'),
            amount: Arr::get($data, 'amount'),
            currency: Arr::get($data, 'currency'),
            meta: Arr::get($data, 'meta', [])
        );
    }

    /**
     * Create a failed response
     */
    public static function failure(string $message, array $data = []): self
    {
        return new self(
            success: false,
            message: $message,
            status: Arr::get($data, 'status', 'failed'),
            data: $data,
            meta: Arr::get($data, 'meta', [])
        );
    }

    /**
     * Create a response that requires redirect
     */
    public static function redirect(string $url, array $data = []): self
    {
        return new self(
            success: true,
            redirectUrl: $url,
            message: Arr::get($data, 'message', 'Redirect to payment page'),
            status: Arr::get($data, 'status', 'pending'),
            data: $data,
            transactionId: Arr::get($data, 'transaction_id'),
            gatewayReference: Arr::get($data, 'gateway_reference'),
            meta: Arr::get($data, 'meta', [])
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'transaction_id' => $this->transactionId,
            'redirect_url' => $this->redirectUrl,
            'message' => $this->message,
            'status' => $this->status,
            'data' => $this->data,
            'gateway_reference' => $this->gatewayReference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'meta' => $this->meta,
        ];
    }

    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Check if response requires redirect
     */
    public function requiresRedirect(): bool
    {
        return $this->success && !empty($this->redirectUrl);
    }

    /**
     * Get data value by key
     */
    public function getData(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    /**
     * Get meta value by key
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->meta, $key, $default);
    }
}