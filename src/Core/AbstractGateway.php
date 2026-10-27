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
use Illuminate\Support\Facades\Http;
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
    public function getConfig(?string $key = null, mixed $default = null): mixed
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
     * Make HTTP request through Laravel's HTTP client (so apps can fake,
     * log and inspect gateway traffic). $options uses Guzzle option names
     * (headers, json, body, form_params, query, auth).
     */
    protected function makeRequest(string $method, string $url, array $options = []): array
    {
        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withUserAgent('Laravel-Payments/2.x')
                ->send(strtoupper($method), $url, $options);
        } catch (\Throwable $e) {
            throw new PaymentException("HTTP request to {$this->gatewayName()} failed: {$e->getMessage()}", 0, $e);
        }

        $data = $response->json();

        if (!$response->successful()) {
            $message = is_array($data)
                ? ($data['message'] ?? $data['error_description'] ?? $data['error']['message'] ?? $data['error'] ?? null)
                : null;

            throw new PaymentException(
                is_string($message) ? $message : "{$this->gatewayName()} API returned HTTP {$response->status()}",
                $response->status()
            );
        }

        if (!is_array($data)) {
            throw new PaymentException("Invalid JSON response from {$this->gatewayName()}");
        }

        return $data;
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
     * Get configuration value for current mode.
     *
     * Looks in the mode block first (e.g. "sandbox.secret_key", as published in
     * config/payments.php) and falls back to a top-level key ("secret_key") so
     * gateway-wide settings such as webhook secrets work in either place.
     */
    protected function getModeConfig(string $key, mixed $default = null): mixed
    {
        $value = $this->getConfig("{$this->mode}.{$key}");

        if ($value === null || $value === '') {
            $value = $this->getConfig($key);
        }

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * Get a required mode config value or fail loudly.
     */
    protected function requireModeConfig(string $key): mixed
    {
        $value = $this->getModeConfig($key);

        if ($value === null || $value === '') {
            throw PaymentException::configuration(
                $this->gatewayName(),
                "Missing '{$key}' for {$this->mode} mode (payments.gateways.{$this->gatewayName()}.{$this->mode}.{$key})"
            );
        }

        return $value;
    }

    /**
     * Handle an incoming webhook/IPN request.
     *
     * Gateways that sign webhooks should override this and verify $rawBody
     * against the provider's signature header before trusting $payload.
     * Throw InvalidSignatureException when verification fails.
     *
     * @param array<string, string> $headers lower-cased header name => value
     */
    public function handleWebhook(array $payload, string $rawBody, array $headers): PaymentResponse
    {
        return $this->verify($payload);
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
     * Number of decimal places in the currency's minor unit (ISO 4217).
     */
    protected function currencyDecimals(string $currency): int
    {
        $currency = strtoupper($currency);

        if (in_array($currency, ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true)) {
            return 0;
        }

        if (in_array($currency, ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'], true)) {
            return 3;
        }

        return 2;
    }

    /**
     * Convert a major-unit amount (e.g. 10.50 USD) to integer minor units (1050).
     */
    protected function toMinorUnits(float $amount, string $currency): int
    {
        return (int) round($amount * (10 ** $this->currencyDecimals($currency)));
    }

    /**
     * Convert integer minor units back to a major-unit amount.
     */
    protected function fromMinorUnits(int|float|string|null $amount, string $currency): float
    {
        return ((float) $amount) / (10 ** $this->currencyDecimals($currency));
    }

    /**
     * Validate webhook signature
     */
    protected function validateWebhookSignature(array $payload, string $signature): bool
    {
        // Never trust a signature that was not actually checked
        return false;
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