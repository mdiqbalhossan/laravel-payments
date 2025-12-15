<?php

namespace Mdiqbal\LaravelPayments\Core;

use Mdiqbal\LaravelPayments\Exceptions\InvalidSignatureException;

class SignatureVerifier
{
    /**
     * Verify HMAC signature
     */
    public static function verifyHmac(string $payload, string $signature, string $secret, string $algorithm = 'sha256'): bool
    {
        $expectedSignature = hash_hmac($algorithm, $payload, $secret);

        return hash_equals($expectedSignature, $signature) ||
               hash_equals($expectedSignature, base64_decode($signature));
    }

    /**
     * Verify SHA256 signature
     */
    public static function verifySha256(string $payload, string $signature, string $secret): bool
    {
        return self::verifyHmac($payload, $signature, $secret, 'sha256');
    }

    /**
     * Verify SHA512 signature
     */
    public static function verifySha512(string $payload, string $signature, string $secret): bool
    {
        return self::verifyHmac($payload, $signature, $secret, 'sha512');
    }

    /**
     * Verify MD5 signature
     */
    public static function verifyMd5(string $payload, string $signature, string $secret): bool
    {
        return self::verifyHmac($payload, $signature, $secret, 'md5');
    }

    /**
     * Verify webhook signature with fallback methods
     */
    public static function verifyWebhook(string $payload, string $signature, string $secret, array $algorithms = ['sha256', 'sha512', 'md5']): bool
    {
        foreach ($algorithms as $algorithm) {
            if (self::verifyHmac($payload, $signature, $secret, $algorithm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate signature
     */
    public static function generateSignature(string $payload, string $secret, string $algorithm = 'sha256'): string
    {
        return hash_hmac($algorithm, $payload, $secret);
    }

    /**
     * Verify with exception
     */
    public static function verifyOrFail(string $payload, string $signature, string $secret, string $algorithm = 'sha256'): void
    {
        if (!self::verifyHmac($payload, $signature, $secret, $algorithm)) {
            throw new InvalidSignatureException('Invalid signature');
        }
    }

    /**
     * Extract signature from header
     */
    public static function extractSignatureFromHeader(string $header, string $prefix = 'sha256='): ?string
    {
        if (str_starts_with($header, $prefix)) {
            return substr($header, strlen($prefix));
        }

        return $header;
    }

    /**
     * Verify Razorpay signature
     */
    public static function verifyRazorpay(string $orderId, string $paymentId, string $signature, string $secret): bool
    {
        $payload = $orderId . '|' . $paymentId;
        return self::verifySha256($payload, $signature, $secret);
    }

    /**
     * Verify Stripe signature
     */
    public static function verifyStripe(string $payload, string $signature, string $secret): bool
    {
        $elements = explode(',', $signature);

        foreach ($elements as $element) {
            $element = trim($element);
            [$key, $value] = explode('=', $element, 2);

            if ($key === 'v1') {
                $expectedSignature = hash_hmac('sha256', $payload, $secret);
                return hash_equals($expectedSignature, $value);
            }
        }

        return false;
    }

    /**
     * Verify Paystack signature
     */
    public static function verifyPaystack(string $payload, string $signature, string $secret): bool
    {
        return self::verifySha512($payload, $signature, $secret);
    }

    /**
     * Verify Flutterwave signature
     */
    public static function verifyFlutterwave(string $payload, string $signature, string $secret): bool
    {
        return self::verifySha256($payload, $signature, $secret);
    }

    /**
     * Verify PhonePe signature
     */
    public static function verifyPhonePe(string $payload, string $saltIndex, string $saltKey): bool
    {
        $finalString = $payload . '/pg/v1/verify' . $saltKey;
        $hash = hash('sha256', $finalString);
        $expectedSignature = $hash . '###' . $saltIndex;

        return hash_equals($expectedSignature, $signature);
    }
}