<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PersonalEmailService
{
    public static function isModuleEnabled(string $settingKey): bool
    {
        $val = Setting::where('key', $settingKey)->value('value');
        return $val === null || $val === '1';
    }

    public static function applyAdminSmtpConfig(): void
    {
        try {
            $tenantApiUrl = rtrim((string) env('TENANT_API_URL', ''), '/');
            if ($tenantApiUrl === '') {
                return;
            }
            $baseApi = preg_replace('#/tenant$#', '', $tenantApiUrl);
            $resp = Http::timeout(5)
                ->withHeaders(['X-Tenant-Secret' => (string) env('TENANT_API_SECRET', '')])
                ->get("{$baseApi}/settings/smtp");

            if ($resp->successful()) {
                $smtp = $resp->json('data') ?? $resp->json() ?? [];
                if (!empty($smtp['mail_host'])) {
                    config([
                        'mail.default' => $smtp['mail_mailer'] ?? 'smtp',
                        'mail.mailers.smtp.host' => $smtp['mail_host'],
                        'mail.mailers.smtp.port' => (int) ($smtp['mail_port'] ?? 587),
                        'mail.mailers.smtp.username' => $smtp['mail_username'] ?? null,
                        'mail.mailers.smtp.password' => $smtp['mail_password'] ?? null,
                        'mail.mailers.smtp.encryption' => strtolower((string) ($smtp['mail_encryption'] ?? 'tls')),
                        'mail.from.address' => $smtp['mail_from_address'] ?? config('mail.from.address'),
                        'mail.from.name' => $smtp['mail_from_name'] ?? config('mail.from.name'),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PersonalEmailService applyAdminSmtpConfig failed: ' . $e->getMessage());
        }
    }

    public static function sendText(string $toEmail, string $subject, string $message): void
    {
        try {
            if (! filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            self::applyAdminSmtpConfig();

            $firmName = (string) (Setting::where('key', 'company_name')->value('value') ?: config('app.name', 'Insurance CRM'));
            Mail::send('emails.crm-notification', [
                'subjectLine' => $subject,
                'bodyText' => $message,
                'firmName' => $firmName,
            ], function ($mail) use ($toEmail, $subject) {
                $mail->to($toEmail)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('PersonalEmailService sendText failed: ' . $e->getMessage());
        }
    }
}
