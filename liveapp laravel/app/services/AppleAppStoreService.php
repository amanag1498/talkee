<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class AppleAppStoreService
{
    private const PRODUCTION_URL = 'https://api.storekit.apple.com';

    private const SANDBOX_URL = 'https://api.storekit-sandbox.apple.com';

    public function configured(): bool
    {
        return (bool) config('services.apple_iap.enabled', false)
            && $this->issuerId() !== ''
            && $this->keyId() !== ''
            && $this->privateKey() !== ''
            && $this->bundleId() !== '';
    }

    public function bundleId(): string
    {
        return trim((string) config('services.apple_iap.bundle_id', 'com.techybugs.talkee'));
    }

    /**
     * Retrieve a transaction directly from Apple. Client receipt data is never
     * trusted as proof of purchase.
     *
     * @return array<string, mixed>
     */
    public function transaction(string $transactionId, bool $allowRevoked = false): array
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            throw new InvalidArgumentException('Apple transaction ID is required.');
        }
        if (! $this->configured()) {
            throw new InvalidArgumentException('Apple In-App Purchase verification is not configured.');
        }

        $preferred = strtolower((string) config('services.apple_iap.environment', 'production'));
        $hosts = $preferred === 'sandbox'
            ? [self::SANDBOX_URL, self::PRODUCTION_URL]
            : [self::PRODUCTION_URL, self::SANDBOX_URL];

        $lastResponse = null;
        foreach ($hosts as $host) {
            $response = $this->requestTransaction($host, $transactionId);
            $lastResponse = $response;
            if ($response->successful()) {
                $signedTransaction = trim((string) $response->json('signedTransactionInfo', ''));
                if ($signedTransaction === '') {
                    throw new RuntimeException('Apple returned an incomplete transaction response.');
                }

                $payload = $this->decodeJwsPayload($signedTransaction);
                $this->validateTransactionPayload($payload, $transactionId, $allowRevoked);

                return $payload;
            }

            if (! in_array($response->status(), [404, 400], true)) {
                break;
            }
        }

        $message = $lastResponse?->json('errorMessage')
            ?? $lastResponse?->json('message')
            ?? 'Apple could not verify this purchase.';
        throw new InvalidArgumentException((string) $message);
    }

    /**
     * Extract the transaction identifier from a notification, then retrieve
     * the current authoritative transaction state directly from Apple.
     *
     * @return array<string, mixed>
     */
    public function notification(string $signedPayload): array
    {
        $notification = $this->decodeJwsPayload(trim($signedPayload));
        $signedTransaction = trim((string) data_get(
            $notification,
            'data.signedTransactionInfo',
            '',
        ));
        if ($signedTransaction === '') {
            throw new InvalidArgumentException('Apple notification does not contain a transaction.');
        }

        $notificationTransaction = $this->decodeJwsPayload($signedTransaction);
        $transactionId = trim((string) ($notificationTransaction['transactionId'] ?? ''));
        if ($transactionId === '') {
            throw new InvalidArgumentException('Apple notification transaction ID is missing.');
        }

        return [
            'notification_uuid' => (string) ($notification['notificationUUID'] ?? ''),
            'notification_type' => (string) ($notification['notificationType'] ?? ''),
            'subtype' => (string) ($notification['subtype'] ?? ''),
            'transaction' => $this->transaction($transactionId, true),
        ];
    }

    private function requestTransaction(string $host, string $transactionId): Response
    {
        return Http::acceptJson()
            ->withToken($this->authorizationToken())
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->get($host.'/inApps/v1/transactions/'.rawurlencode($transactionId));
    }

    private function authorizationToken(): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $this->issuerId(),
            'iat' => $now,
            'exp' => $now + 1200,
            'aud' => 'appstoreconnect-v1',
            'bid' => $this->bundleId(),
        ], $this->privateKey(), 'ES256', $this->keyId());
    }

    /**
     * The signed transaction is obtained over an authenticated TLS request
     * directly from Apple's App Store Server API. We decode it only after that
     * request succeeds, then enforce all entitlement-defining claims.
     *
     * @return array<string, mixed>
     */
    private function decodeJwsPayload(string $jws): array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw new RuntimeException('Apple returned malformed signed transaction data.');
        }

        $payload = $this->base64UrlDecode($parts[1]);
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Apple returned unreadable transaction data.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateTransactionPayload(
        array $payload,
        string $transactionId,
        bool $allowRevoked,
    ): void {
        if ((string) ($payload['transactionId'] ?? '') !== $transactionId) {
            throw new InvalidArgumentException('Apple transaction ID does not match.');
        }
        if ((string) ($payload['bundleId'] ?? '') !== $this->bundleId()) {
            throw new InvalidArgumentException('Apple purchase belongs to another app.');
        }
        if (strtoupper((string) ($payload['type'] ?? 'CONSUMABLE')) !== 'CONSUMABLE') {
            throw new InvalidArgumentException('Apple product is not a consumable coin pack.');
        }
        if (! $allowRevoked && ! empty($payload['revocationDate'])) {
            throw new InvalidArgumentException('Apple purchase has been revoked or refunded.');
        }
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new RuntimeException('Unable to decode Apple transaction data.');
        }

        return $decoded;
    }

    private function issuerId(): string
    {
        return trim((string) config('services.apple_iap.issuer_id', ''));
    }

    private function keyId(): string
    {
        return trim((string) config('services.apple_iap.key_id', ''));
    }

    private function privateKey(): string
    {
        $inline = trim((string) config('services.apple_iap.private_key', ''));
        if ($inline !== '') {
            if (! str_contains($inline, 'BEGIN PRIVATE KEY')) {
                $decoded = base64_decode($inline, true);
                if ($decoded !== false) {
                    $inline = $decoded;
                }
            }

            return str_replace('\n', "\n", $inline);
        }

        $path = trim((string) config('services.apple_iap.private_key_path', ''));
        if ($path === '') {
            return '';
        }
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
