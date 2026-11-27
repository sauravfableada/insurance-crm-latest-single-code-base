<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PersonalEmailService
{
    public static function isModuleEnabled(string $settingKey): bool
    {
        $val = Setting::where('key', $settingKey)->value('value');
        return $val === null || $val === '1';
    }

    public static function sendText(string $toEmail, string $subject, string $message): void
    {
        try {
            if (! filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                return;
            }
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
