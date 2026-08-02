<?php

namespace Mdiqbal\LaravelPayments\DTO;

use Illuminate\Support\Arr;

class PaymentRequest
{
    /**
     * Transaction/Order ID
     */
    public ?string $orderId = null;

    /**
     * Transaction ID (alias for orderId)
     */
    public ?string $transaction_id = null;

    /**
     * Payment amount
     */
    public float $amount = 0.0;

    /**
     * Currency code (e.g., USD, EUR)
     */
    public string $currency = 'USD';

    /**
     * Payment description
     */
    public ?string $description = null;

    /**
     * Customer email
     */
    public ?string $email = null;

    /**
     * Customer information
     */
    public ?array $customer = null;

    /**
     * Return/Success URL
     */
    public ?string $returnUrl = null;

    /**
     * Cancel URL
     */
    public ?string $cancelUrl = null;

    /**
     * Webhook/Notify URL
     */
    public ?string $notifyUrl = null;

    /**
     * Additional metadata
     */
    public array $metadata = [];

    /**
     * Create a new PaymentRequest instance.
     * All parameters are optional to allow flexible instantiation.
     */
    public function __construct(
        ?float $amount = null,
        ?string $currency = null,
        ?string $description = null,
        ?string $orderId = null,
        ?string $email = null,
        ?array $customer = null,
        ?string $returnUrl = null,
        ?string $cancelUrl = null,
        ?string $notifyUrl = null,
        ?array $metadata = null
    ) {
        if ($amount !== null) {
            $this->amount = $amount;
        }
        if ($currency !== null) {
            $this->currency = $currency;
        }
        if ($description !== null) {
            $this->description = $description;
        }
        if ($orderId !== null) {
            $this->orderId = $orderId;
            $this->transaction_id = $orderId;
        }
        if ($email !== null) {
            $this->email = $email;
        }
        if ($customer !== null) {
            $this->customer = $customer;
        }
        if ($returnUrl !== null) {
            $this->returnUrl = $returnUrl;
        }
        if ($cancelUrl !== null) {
            $this->cancelUrl = $cancelUrl;
        }
        if ($notifyUrl !== null) {
            $this->notifyUrl = $notifyUrl;
        }
        if ($metadata !== null) {
            $this->metadata = $metadata;
        }
    }

    /**
     * Create a new payment request from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            amount: (float) Arr::get($data, 'amount', 0),
            currency: Arr::get($data, 'currency', 'USD'),
            description: Arr::get($data, 'description'),
            orderId: Arr::get($data, 'order_id') ?? Arr::get($data, 'orderId') ?? Arr::get($data, 'transaction_id'),
            email: Arr::get($data, 'email') ?? Arr::get($data, 'customer_email'),
            customer: Arr::get($data, 'customer'),
            returnUrl: Arr::get($data, 'return_url') ?? Arr::get($data, 'callback_url'),
            cancelUrl: Arr::get($data, 'cancel_url'),
            notifyUrl: Arr::get($data, 'notify_url') ?? Arr::get($data, 'webhook_url'),
            metadata: Arr::get($data, 'metadata', Arr::get($data, 'meta', []))
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'transaction_id' => $this->transaction_id ?? $this->orderId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'email' => $this->email,
            'customer' => $this->customer,
            'return_url' => $this->returnUrl,
            'cancel_url' => $this->cancelUrl,
            'notify_url' => $this->notifyUrl,
            'metadata' => $this->metadata,
        ];
    }

    // ==========================================
    // Getter Methods
    // ==========================================

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getAmountInCents(): int
    {
        return (int) round($this->amount * 100);
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function getTransactionId(): ?string
    {
        return $this->transaction_id ?? $this->orderId;
    }

    public function getEmail(): ?string
    {
        return $this->email ?? ($this->customer['email'] ?? null);
    }

    public function getCustomer(): ?array
    {
        return $this->customer;
    }

    public function getReturnUrl(): ?string
    {
        return $this->returnUrl;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->returnUrl;
    }

    public function getCancelUrl(): ?string
    {
        return $this->cancelUrl;
    }

    public function getNotifyUrl(): ?string
    {
        return $this->notifyUrl;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->notifyUrl;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->metadata, $key, $default);
    }

    // ==========================================
    // Setter Methods (Fluent Interface)
    // ==========================================

    public function setAmount(float $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function setOrderId(?string $orderId): self
    {
        $this->orderId = $orderId;
        $this->transaction_id = $orderId;
        return $this;
    }

    public function setTransactionId(?string $transactionId): self
    {
        $this->transaction_id = $transactionId;
        if ($this->orderId === null) {
            $this->orderId = $transactionId;
        }
        return $this;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function setCustomer(?array $customer): self
    {
        $this->customer = $customer;
        return $this;
    }

    public function setReturnUrl(?string $returnUrl): self
    {
        $this->returnUrl = $returnUrl;
        return $this;
    }

    public function setRedirectUrl(?string $redirectUrl): self
    {
        $this->returnUrl = $redirectUrl;
        return $this;
    }

    public function setCancelUrl(?string $cancelUrl): self
    {
        $this->cancelUrl = $cancelUrl;
        return $this;
    }

    public function setNotifyUrl(?string $notifyUrl): self
    {
        $this->notifyUrl = $notifyUrl;
        return $this;
    }

    public function setWebhookUrl(?string $webhookUrl): self
    {
        $this->notifyUrl = $webhookUrl;
        return $this;
    }

    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }
}