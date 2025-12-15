<?php

namespace Mdiqbal\LaravelPayments\DTO;

use Illuminate\Support\Arr;

class PaymentRequest
{
    public function __construct(
        public readonly string $orderId,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $customerEmail,
        public readonly string $callbackUrl,
        public readonly ?string $webhookUrl = null,
        public readonly array $meta = [],
        public readonly ?string $customerName = null,
        public readonly ?string $customerPhone = null,
        public readonly ?string $description = null,
        public readonly array $customData = []
    ) {}

    /**
     * Create a new payment request from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            orderId: $data['order_id'],
            amount: (float) $data['amount'],
            currency: $data['currency'],
            customerEmail: $data['customer_email'],
            callbackUrl: $data['callback_url'],
            webhookUrl: Arr::get($data, 'webhook_url'),
            meta: Arr::get($data, 'meta', []),
            customerName: Arr::get($data, 'customer_name'),
            customerPhone: Arr::get($data, 'customer_phone'),
            description: Arr::get($data, 'description'),
            customData: Arr::get($data, 'custom_data', [])
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'customer_email' => $this->customerEmail,
            'callback_url' => $this->callbackUrl,
            'webhook_url' => $this->webhookUrl,
            'meta' => $this->meta,
            'customer_name' => $this->customerName,
            'customer_phone' => $this->customerPhone,
            'description' => $this->description,
            'custom_data' => $this->customData,
        ];
    }

    /**
     * Get amount in cents (for gateways that require it)
     */
    public function getAmountInCents(): int
    {
        return (int) round($this->amount * 100);
    }

    /**
     * Get meta value by key
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->meta, $key, $default);
    }

    /**
     * Get custom data value by key
     */
    public function getCustomData(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->customData, $key, $default);
    }
}