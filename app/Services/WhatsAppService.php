<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the WhatsApp Cloud API (Meta).
 *
 * Set in .env:
 *   WHATSAPP_PROVIDER=meta
 *   WHATSAPP_PHONE_NUMBER_ID=...
 *   WHATSAPP_ACCESS_TOKEN=...
 *   WHATSAPP_DEFAULT_COUNTRY_CODE=233    # Ghana, no leading +
 *
 * When unconfigured this service no-ops with a warning so the sale still
 * completes — callers don't have to wrap every send in a try/catch.
 */
class WhatsAppService
{
    public function isConfigured(): bool
    {
        return $this->provider() === 'meta'
            && !empty(env('WHATSAPP_PHONE_NUMBER_ID'))
            && !empty(env('WHATSAPP_ACCESS_TOKEN'));
    }

    /**
     * Send a plain-text WhatsApp message. Returns ['success' => bool, ...].
     */
    public function sendText(string $rawPhone, string $message): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'reason' => 'not_configured'];
        }

        $to = $this->normalisePhone($rawPhone);
        if (!$to) {
            return ['success' => false, 'reason' => 'invalid_phone'];
        }

        $phoneId = env('WHATSAPP_PHONE_NUMBER_ID');
        $token = env('WHATSAPP_ACCESS_TOKEN');
        $url = "https://graph.facebook.com/v18.0/{$phoneId}/messages";

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message,
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('WhatsApp send failed', [
                    'to' => $to,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return [
                    'success' => false,
                    'reason' => 'api_error',
                    'status' => $response->status(),
                    'body' => $response->json(),
                ];
            }

            return [
                'success' => true,
                'to' => $to,
                'response' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('WhatsApp send threw', ['error' => $e->getMessage()]);
            return ['success' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        }
    }

    /**
     * Build the public `wa.me` deep link so the cashier can fall back to a
     * tap-to-send link when the Cloud API isn't configured.
     */
    public function clickToChatUrl(string $rawPhone, string $message): ?string
    {
        $to = $this->normalisePhone($rawPhone);
        if (!$to) return null;
        return 'https://wa.me/' . $to . '?text=' . rawurlencode($message);
    }

    /**
     * Convert local phone numbers to E.164 digits (no +) the way Meta wants.
     * Strips spaces/hyphens, drops a leading 0 in favour of the default country
     * code, accepts numbers already prefixed with '+'.
     */
    public function normalisePhone(?string $raw): ?string
    {
        if (!$raw) return null;
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '' || $digits === null) return null;

        $cc = env('WHATSAPP_DEFAULT_COUNTRY_CODE', '233');
        $cc = preg_replace('/\D+/', '', (string) $cc);

        // Local format: 0XXXXXXXXX → CC + XXXXXXXXX
        if (str_starts_with($digits, '0') && $cc) {
            $digits = $cc . substr($digits, 1);
        }
        // Without a leading 0 and shorter than ~11 digits, prepend CC.
        if ($cc && strlen($digits) <= 10) {
            $digits = $cc . $digits;
        }
        return $digits;
    }

    private function provider(): string
    {
        return strtolower((string) env('WHATSAPP_PROVIDER', 'meta'));
    }
}
