<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PersonalWhatsappService
{
    public static function isEnabled(): bool
    {
        $sendMode = Setting::where('key', 'whatsapp_send_mode')->value('value');
        $personalConnected = Setting::where('key', 'whatsapp_personal_connected')->value('value');
        return $sendMode === 'personal_whatsapp' || $personalConnected === '1';
    }

    public static function isModuleEnabled(string $settingKey): bool
    {
        if (! self::isEnabled()) {
            return false;
        }
        $val = Setting::where('key', $settingKey)->value('value');
        return $val === null || $val === '1';
    }

    public static function sendText(string $rawPhone, string $message, int $actorUserId = 1): void
    {
        try {
            if (! self::isEnabled()) {
                return;
            }

            $digits = preg_replace('/\D+/', '', $rawPhone);
            if ($digits === '') {
                return;
            }
            if (strlen($digits) === 10) {
                $digits = '91' . $digits;
            }

            $sessionId = 'user_' . ($actorUserId ?: 1);
            $secret = (string) env('WHATSAPP_SESSION_SECRET', 'wa_session_secret_key_2026');
            $headers = ['x-session-secret' => $secret];
            $payload = ['phone' => $digits, 'message' => $message];

            $socketPath = '/tmp/wa_session.sock';
            if (PHP_OS_FAMILY !== 'Windows' && file_exists($socketPath)) {
                Http::timeout(10)
                    ->withHeaders($headers)
                    ->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $socketPath]])
                    ->post("http://localhost/sessions/{$sessionId}/send-message", $payload);
            } else {
                $baseUrl = rtrim((string) env('WHATSAPP_SESSION_URL', 'http://127.0.0.1:3001'), '/');
                Http::timeout(10)
                    ->withHeaders($headers)
                    ->post("{$baseUrl}/sessions/{$sessionId}/send-message", $payload);
            }
        } catch (\Throwable $e) {
            Log::warning('PersonalWhatsappService sendText failed: ' . $e->getMessage());
        }
    }
}
