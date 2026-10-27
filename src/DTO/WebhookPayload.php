<?php

namespace Mdiqbal\LaravelPayments\DTO;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class WebhookPayload
{
    /**
     * @param array<string, string> $headers lower-cased header name => value
     */
    public function __construct(
        public readonly string $gateway,
        public readonly array $payload,
        public readonly ?string $signature = null,
        public readonly array $headers = [],
        public readonly string $rawBody = ''
    ) {}

    /**
     * Create webhook payload from request data.
     *
     * Header values may be given as strings or as the arrays Symfony's
     * HeaderBag::all() returns; only the first value of each header is kept.
     */
    public static function fromRequest(string $gateway, array $payload, array $headers = [], string $rawBody = ''): self
    {
        $headers = self::normalizeHeaders($headers);

        return new self(
            gateway: $gateway,
            payload: $payload,
            signature: $headers['signature'] ?? $headers['x-signature'] ?? $headers['webhook-signature'] ?? null,
            headers: $headers,
            rawBody: $rawBody
        );
    }

    /**
     * Create webhook payload from an HTTP request.
     */
    public static function fromHttpRequest(string $gateway, Request $request): self
    {
        return self::fromRequest($gateway, $request->all(), $request->headers->all(), (string) $request->getContent());
    }

    /**
     * @return array<string, string>
     */
    public static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $value = reset($value);
            }

            if ($value === false || $value === null) {
                continue;
            }

            $normalized[strtolower((string) $name)] = (string) $value;
        }

        return $normalized;
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
     * Get header value by key (case-insensitive)
     */
    public function getHeader(string $key, mixed $default = null): mixed
    {
        return $this->headers[strtolower($key)] ?? $default;
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
