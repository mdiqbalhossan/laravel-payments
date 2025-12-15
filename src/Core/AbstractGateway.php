<?php

namespace Mdiqbal\LaravelPayments\Core;

use Mdiqbal\LaravelPayments\Contracts\PaymentGatewayInterface;
use Mdiqbal\LaravelPayments\DTO\PaymentRequest;
use Mdiqbal\LaravelPayments\DTO\PaymentResponse;
use Mdiqbal\LaravelPayments\DTO\WebhookPayload;
use Mdiqbal\LaravelPayments\Exceptions\PaymentException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

abstract class AbstractGateway implements PaymentGatewayInterface
{
    protected Client $client;
    protected array $config = [];
    protected string $mode = 'sandbox';

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->mode = $config['mode'] ?? 'sandbox';
        $this->client = $this->createHttpClient();
    }

    /**
     * Get gateway name
     */
    abstract public function gatewayName(): string;

    /**
     * Process payment
     */
    abstract public function pay(PaymentRequest $request): PaymentResponse;

    /**
     * Verify webhook
     */
    public function verify(array $payload): PaymentResponse
    {
        // Default implementation - should be overridden by gateways
        return $this->parseWebhookPayload($payload);
    }

    /**
     * Refund transaction
     */
    public function refund(string $transactionId, float $amount): bool
    {
        // Default implementation - should be overridden by gateways that support refunds
        throw new PaymentException("Refunds are not supported by {$this->gatewayName()} gateway");
    }

    /**
     * Check if refunds are supported
     */
    public function supportsRefund(): bool
    {
        return false;
    }

    /**
     * Get configuration value
     */
    public function getConfig(?string $key = null, ?string $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return Arr::get($this->config, $key, $default);
    }

    /**
     * Set configuration
     */
    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        $this->mode = $this->config['mode'] ?? 'sandbox';

        return $this;
    }

    /**
     * Get current mode
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Set mode
     */
    public function setMode(string $mode): self
    {
        $this->mode = $mode;
        $this->config['mode'] = $mode;

        return $this;
    }

    /**
     * Check if in sandbox mode
     */
    public function isSandbox(): bool
    {
        return $this->mode === 'sandbox';
    }

    /**
     * Check if in live mode
     */
    public function isLive(): bool
    {
        return $this->mode === 'live';
    }

    /**
     * Create HTTP client
     */
    protected function createHttpClient(array $options = []): Client
    {
        $defaultOptions = [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'Laravel-Payments/1.0.0',
            ],
        ];

        return new Client(array_merge($defaultOptions, $options));
    }

    /**
     * Make HTTP request
     */
    protected function makeRequest(string $method, string $url, array $options = []): array
    {
        try {
            $response = $this->client->request($method, $url, $options);
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($data === null) {
                throw new PaymentException("Invalid JSON response from {$this->gatewayName()}");
            }

            return $data;
        } catch (RequestException $e) {
            $message = "HTTP request failed: {$e->getMessage()}";

            if ($e->hasResponse()) {
                $body = $e->getResponse()->getBody()->getContents();
                $data = json_decode($body, true);

                if ($data && isset($data['message'])) {
                    $message = $data['message'];
                }
            }

            throw new PaymentException($message, 0, $e);
        } catch (\Exception $e) {
            throw new PaymentException("Request failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get API endpoint URL
     */
    protected function getEndpoint(string $path): string
    {
        $baseUrl = $this->getConfig('api_base_url');

        if (!$baseUrl) {
            throw new PaymentException("API base URL not configured for {$this->gatewayName()}");
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Get configuration value for current mode
     */
    protected function getModeConfig(string $key, mixed $default = null): mixed
    {
        $modeKey = "{$this->mode}.{$key}";
        return $this->getConfig($modeKey, $default);
    }

    /**
     * Parse webhook payload
     */
    protected function parseWebhookPayload(array $payload): PaymentResponse
    {
        // Default webhook parsing logic - should be overridden by gateways
        $status = $payload['status'] ?? 'unknown';
        $transactionId = $payload['transaction_id'] ?? $payload['id'] ?? null;
        $success = in_array($status, ['success', 'completed', 'paid']);

        return new PaymentResponse(
            success: $success,
            transactionId: $transactionId,
            status: $status,
            data: $payload
        );
    }

    /**
     * Generate unique order ID
     */
    protected function generateOrderId(): string
    {
        return 'order_' . Str::random(16);
    }

    /**
     * Format amount for gateway
     */
    protected function formatAmount(float $amount): string|int
    {
        // Most gateways use amount in cents, but override in gateway class if different
        return (int) round($amount * 100);
    }

    /**
     * Validate webhook signature
     */
    protected function validateWebhookSignature(array $payload, string $signature): bool
    {
        // Default implementation - should be overridden by gateways
        return true;
    }

    /**
     * Log payment data
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $context['gateway'] = $this->gatewayName();
        $context['mode'] = $this->mode;

        logger()->log($level, "[Payments] {$message}", $context);
    }

    /**
     * Handle API errors
     */
    protected function handleError(array $response): void
    {
        $message = $response['message'] ?? 'Unknown error occurred';

        if (isset($response['errors'])) {
            $errors = implode(', ', (array) $response['errors']);
            $message .= ": {$errors}";
        }

        throw new PaymentException($message);
    }
}