<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class WhatsappWebBridge
{
    public function request(int $userId, string $action, array $data = []): array
    {
        $token = config('whatsapp_bridge.token');
        if (!$token) throw ValidationException::withMessages(['whatsapp'=>'WhatsApp bridge is not configured. Ask your administrator to complete the bridge setup.']);
        try {
            $response = Http::withToken($token)->acceptJson()
                ->withHeaders(['ngrok-skip-browser-warning' => 'insurance-crm-whatsapp-bridge'])
                ->connectTimeout(3)->timeout(40)
                ->post(rtrim(config('whatsapp_bridge.url'), '/').'/sessions/'.$userId.'/'.$action, $data);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw ValidationException::withMessages(['whatsapp'=>'WhatsApp bridge is unavailable. If you were sending, check WhatsApp before retrying; delivery may still be processing.']);
        }
        $body = $response->json();
        if (!$response->successful() || !is_array($body)) {
            throw ValidationException::withMessages(['whatsapp'=>$body['message'] ?? 'WhatsApp could not complete this request. Check the connection and try again.']);
        }
        return $body;
    }
}
