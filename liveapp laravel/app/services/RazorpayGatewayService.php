<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class RazorpayGatewayService
{
    public function configured(): bool
    {
        return $this->keyId() !== '' && $this->keySecret() !== '';
    }

    public function keyId(): string
    {
        return trim((string) config('services.razorpay.key_id', ''));
    }

    public function currency(): string
    {
        return strtoupper(trim((string) config('services.razorpay.currency', 'INR')));
    }

    public function webhookSecret(): string
    {
        return trim((string) config('services.razorpay.webhook_secret', ''));
    }

    public function createOrder(array $payload): array
    {
        return $this->request('post', '/orders', $payload);
    }

    public function fetchOrder(string $orderId): array
    {
        return $this->request('get', '/orders/' . $orderId);
    }

    public function fetchPayment(string $paymentId): array
    {
        return $this->request('get', '/payments/' . $paymentId);
    }

    public function capturePayment(string $paymentId, int $amountSubunits, string $currency): array
    {
        return $this->request('post', '/payments/' . $paymentId . '/capture', [
            'amount' => $amountSubunits,
            'currency' => strtoupper(trim($currency)),
        ]);
    }

    public function verifySignature(
        string $gatewayOrderId,
        string $paymentId,
        string $signature,
    ): bool {
        if (!$this->configured()) {
            throw new InvalidArgumentException('Razorpay is not configured.');
        }

        $generated = hash_hmac(
            'sha256',
            $gatewayOrderId . '|' . $paymentId,
            $this->keySecret(),
        );

        return hash_equals($generated, trim($signature));
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = $this->webhookSecret();
        if ($secret === '') {
            throw new InvalidArgumentException('Razorpay webhook secret is not configured.');
        }

        $generated = hash_hmac('sha256', $payload, $secret);

        return hash_equals($generated, trim($signature));
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        if (!$this->configured()) {
            throw new InvalidArgumentException('Razorpay is not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->withBasicAuth($this->keyId(), $this->keySecret())
            ->timeout(20)
            ->send(strtoupper($method), rtrim($this->baseUrl(), '/') . $path, [
                'json' => $payload,
            ]);

        if ($response->failed()) {
            throw new InvalidArgumentException($this->errorMessageFor($response));
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new InvalidArgumentException('Unexpected Razorpay response.');
        }

        return $data;
    }

    private function baseUrl(): string
    {
        return (string) config('services.razorpay.base_url', 'https://api.razorpay.com/v1');
    }

    private function keySecret(): string
    {
        return trim((string) config('services.razorpay.key_secret', ''));
    }

    private function errorMessageFor(Response $response): string
    {
        $body = $response->json();
        if (is_array($body)) {
            $error = $body['error'] ?? null;
            if (is_array($error) && !empty($error['description'])) {
                return (string) $error['description'];
            }
            if (!empty($body['description'])) {
                return (string) $body['description'];
            }
        }

        return 'Razorpay request failed with status ' . $response->status() . '.';
    }
}
