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
        if ($signature === '' || $secret === '') {
            return false;
        }

        $expectedHex = hash_hmac($algorithm, $payload, $secret);
        $expectedBase64 = base64_encode(hash_hmac($algorithm, $payload, $secret, true));

        return hash_equals($expectedHex, strtolower($signature)) ||
               hash_equals($expectedBase64, $signature);
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
    public static function verifyStripe(string $payload, string $signature, string $secret, int $tolerance = 300): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signature) as $element) {
            $parts = explode('=', trim($element), 2);
            if (count($parts) !== 2) {
                continue;
            }
            if ($parts[0] === 't') {
                $timestamp = (int) $parts[1];
            } elseif ($parts[0] === 'v1') {
                $signatures[] = $parts[1];
            }
        }

        if ($timestamp === null || $signatures === [] || ($tolerance > 0 && abs(time() - $timestamp) > $tolerance)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
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
     * Verify Flutterwave webhook: the "verif-hash" header must equal the secret
     * hash configured in the Flutterwave dashboard (it is not an HMAC).
     */
    public static function verifyFlutterwave(string $verifHash, string $secretHash): bool
    {
        return $verifHash !== '' && $secretHash !== '' && hash_equals($secretHash, $verifHash);
    }

    /**
     * Verify PhonePe callback X-VERIFY: sha256(base64Response + saltKey) . '###' . saltIndex
     */
    public static function verifyPhonePe(string $base64Response, string $xVerify, string $saltKey, string $saltIndex): bool
    {
        $expected = hash('sha256', $base64Response . $saltKey) . '###' . $saltIndex;

        return $xVerify !== '' && hash_equals($expected, $xVerify);
    }
}