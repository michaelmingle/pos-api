<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackService
{
    private const BASE_URL = 'https://api.paystack.co';

    private string $secretKey;

    public function __construct(?string $secretKey = null)
    {
        $this->secretKey = $secretKey ?? (string) config('services.paystack.secret');
    }

    public function isConfigured(): bool
    {
        return !empty($this->secretKey);
    }

    /**
     * Initialize a transaction. Returns Paystack's payload including
     * authorization_url, access_code, and reference.
     */
    public function initializeTransaction(array $payload): array
    {
        $response = $this->client()
            ->post(self::BASE_URL . '/transaction/initialize', $payload);

        $body = $response->json();
        if (!$response->successful() || !($body['status'] ?? false)) {
            $msg = $body['message'] ?? 'Paystack initialize failed';
            Log::error('Paystack initialize failed', ['body' => $body]);
            throw new \RuntimeException($msg);
        }

        return $body['data'] ?? [];
    }

    /**
     * Verify a transaction by reference. Returns Paystack's transaction data.
     */
    public function verifyTransaction(string $reference): array
    {
        $response = $this->client()
            ->get(self::BASE_URL . '/transaction/verify/' . urlencode($reference));

        $body = $response->json();
        if (!$response->successful() || !($body['status'] ?? false)) {
            $msg = $body['message'] ?? 'Paystack verify failed';
            Log::error('Paystack verify failed', ['reference' => $reference, 'body' => $body]);
            throw new \RuntimeException($msg);
        }

        return $body['data'] ?? [];
    }

    /**
     * Verify the X-Paystack-Signature header on a webhook payload.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if (!$this->isConfigured() || empty($signature)) {
            return false;
        }
        $expected = hash_hmac('sha512', $rawBody, $this->secretKey);
        return hash_equals($expected, $signature);
    }

    private function client()
    {
        return Http::withToken($this->secretKey)
            ->acceptJson()
            ->timeout(20);
    }
}
