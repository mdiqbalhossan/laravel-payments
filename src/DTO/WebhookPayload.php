<?php

namespace Mdiqbal\LaravelPayments\DTO;

use Illuminate\Support\Arr;

class WebhookPayload
{
    public function __construct(
        public readonly string $gateway,
        public readonly array $payload,
        public readonly ?string $signature = null,
        public readonly array $headers = []
    ) {}

    /**
     * Create webhook payload from request
     */
    public static function fromRequest(string $gateway, array $payload, array $headers = []): self
    {
        return new self(
            gateway: $gateway,
            payload: $payload,
            signature: Arr::get($headers, 'signature') ?: Arr::get($headers, 'x-signature') ?: Arr::get($headers, 'webhook-signature'),
            headers: $headers
        );
    }

    /**
     * Get payload value by key
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->payload, $key, $default);
    }

    /**
     * Check if payload has key
     */
    public function has(string $key): bool
    {
        return Arr::has($this->payload, $key);
    }

    /**
     * Get all payload
     */
    public function all(): array
    {
        return $this->payload;
    }

    /**
     * Get header value by key
     */
    public function getHeader(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->headers, $key, $default);
    }

    /**
     * Check if webhook has valid signature
     */
    public function hasSignature(): bool
    {
        return !empty($this->signature);
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'payload' => $this->payload,
            'signature' => $this->signature,
            'headers' => $this->headers,
        ];
    }
}