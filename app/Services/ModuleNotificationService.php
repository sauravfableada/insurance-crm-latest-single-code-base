<?php

namespace App\Services;

use App\Mail\GenericNotificationMail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ModuleNotificationService
{
    /**
     * Map of supported modules with their human labels, icon, and default template details.
     */
    public static array $modules = [
        'leads' => [
            'key' => 'leads',
            'name' => 'Leads',
            'icon' => 'fa-bullhorn',
            'color' => '#f59e0b',
            'email_subject' => 'New Lead Assigned: {Lead_Name}',
            'email_body' => "Hello {Staff_Name},\n\nA new lead has been assigned to you in {Firm_Name}.\n\nLead Details:\n- Name: {Lead_Name}\n- Phone: {Lead_Phone}\n- Email: {Lead_Email}\n- Source: {Lead_Source}\n\nPlease follow up with the lead at your earliest convenience.\n\nBest Regards,\n{Firm_Name}",
            'wa_message' => "Hello {Staff_Name},\nA new lead has been assigned to you:\nName: {Lead_Name}\nPhone: {Lead_Phone}\nEmail: {Lead_Email}\nSource: {Lead_Source}\n- {Firm_Name}",
        ],
        'policies' => [
            'key' => 'policies',
            'name' => 'Policies',
            'icon' => 'fa-shield-halved',
            'color' => '#3b82f6',
            'email_subject' => 'Policy Notification: {Policy_No} - {Client_Name}',
            'email_body' => "Hello {Staff_Name},\n\nA policy entry has been created/updated for {Client_Name}.\n\nPolicy Details:\n- Policy No: {Policy_No}\n- Client Name: {Client_Name}\n- Company: {Company_Name}\n- Premium Amount: ₹{Premium_Amount}\n\nBest Regards,\n{Firm_Name}",
            'wa_message' => "Hello {Staff_Name},\nPolicy details for {Client_Name}:\nPolicy No: {Policy_No}\nCompany: {Company_Name}\nPremium: ₹{Premium_Amount}\n- {Firm_Name}",
        ],
        'customers' => [
            'key' => 'customers',
            'name' => 'Customers / Clients',
            'icon' => 'fa-users',
            'color' => '#10b981',
            'email_subject' => 'Welcome to {Firm_Name}, {Client_Name}!',
            'email_body' => "Dear {Client_Name},\n\nWelcome to {Firm_Name}! Your client profile has been registered successfully.\n\nYour Registered Details:\n- Name: {Client_Name}\n- Phone: {Client_Phone}\n- Email: {Client_Email}\n\nIf you have any questions or require insurance support, feel free to contact us.\n\nWarm Regards,\n{Firm_Name}",
            'wa_message' => "Dear {Client_Name},\nWelcome to {Firm_Name}! Your client account has been registered successfully.\nPhone: {Client_Phone}\nEmail: {Client_Email}\nThank you for choosing us.\n- {Firm_Name}",
        ],
        'followups' => [
            'key' => 'followups',
            'name' => 'Follow-ups',
            'icon' => 'fa-user-tie',
            'color' => '#6366f1',
            'email_subject' => 'Follow-up Assigned: {Followup_Subject}',
            'email_body' => "Hello {Staff_Name},\n\nA follow-up task has been scheduled for you.\n\nDetails:\n- Subject: {Followup_Subject}\n- Client/Lead: {Client_Name}\n- Date & Time: {Followup_Date}\n- Remarks: {Remarks}\n\nBest Regards,\n{Firm_Name}",
            'wa_message' => "Hello {Staff_Name},\nFollow-up Reminder:\nSubject: {Followup_Subject}\nWith: {Client_Name}\nDate: {Followup_Date}\n- {Firm_Name}",
        ],
        'meetings' => [
            'key' => 'meetings',
            'name' => 'Meetings',
            'icon' => 'fa-handshake',
            'color' => '#06b6d4',
            'email_subject' => 'Meeting Scheduled: {Meeting_Title}',
            'email_body' => "Hello {Staff_Name},\n\nA meeting has been scheduled.\n\nMeeting Details:\n- Title: {Meeting_Title}\n- Client/Lead: {Client_Name}\n- Date & Time: {Meeting_Time}\n- Location: {Meeting_Location}\n\nBest Regards,\n{Firm_Name}",
            'wa_message' => "Hello {Staff_Name},\nMeeting Scheduled: {Meeting_Title}\nWith: {Client_Name}\nTime: {Meeting_Time}\nLocation: {Meeting_Location}\n- {Firm_Name}",
        ],
        'tasks' => [
            'key' => 'tasks',
            'name' => 'Tasks',
            'icon' => 'fa-tasks',
            'color' => '#8b5cf6',
            'email_subject' => 'New Task Assigned: {Task_Title}',
            'email_body' => "Hello {Staff_Name},\n\nA task has been assigned to you.\n\nTask Details:\n- Title: {Task_Title}\n- Priority: {Task_Priority}\n- Due Date: {Task_Due_Date}\n\nBest Regards,\n{Firm_Name}",
            'wa_message' => "Hello {Staff_Name},\nNew Task: {Task_Title}\nPriority: {Task_Priority}\nDue Date: {Task_Due_Date}\n- {Firm_Name}",
        ],
        'claim_trackers' => [
            'key' => 'claim_trackers',
            'name' => 'Claim Tracker',
            'icon' => 'fa-file-circle-check',
            'color' => '#ef4444',
            'email_subject' => 'Claim Update: {Claim_No}',
            'email_body' => "Hello {Staff_Name},\n\nA claim record has been updated.\n\nClaim Details:\n- Claim No: {Claim_No}\n- Policy No: {Policy_No}\n- Client: {Client_Name}\n- Status: {Claim_Status}\n\nBest Regards,\n{Firm_Name}",
            'wa_message' => "Hello {Staff_Name},\nClaim Alert: {Claim_No}\nPolicy: {Policy_No}\nStatus: {Claim_Status}\n- {Firm_Name}",
        ],
        'deals' => [
            'key' => 'deals',
            'name' => 'Deals',
            'icon' => 'fa-medal',
            'color' => '#ec4899',
            'email_subject' => 'Deal Assigned: {Deal_Name}',
            'email_body' => "Hello {Staff_Name},\n\nA deal has been assigned to you in {Firm_Name}.\n\nDeal Details:\n- Deal Name: {Deal_Name}\n- Value: ₹{Deal_Value}\n- Client: {Client_Name}\n\nBest Regards,\n{Firm_Name}",
            'wa_message' => "Hello {Staff_Name},\nNew Deal: {Deal_Name}\nValue: ₹{Deal_Value}\nClient: {Client_Name}\n- {Firm_Name}",
        ],
        'quotations' => [
            'key' => 'quotations',
            'name' => 'Quotations',
            'icon' => 'fa-file-invoice-dollar',
            'color' => '#14b8a6',
            'email_subject' => 'New Quotation: {Quote_No}',
            'email_body' => "Hello {Staff_Name},\n\nA quotation has been prepared.\n\nQuotation Details:\n- Quote No: {Quote_No}\n- Client: {Client_Name}\n- Amount: ₹{Quote_Amount}\n\nBest Regards,\n{Firm_Name}",
            'wa_message' => "Hello {Staff_Name},\nQuotation: {Quote_No}\nClient: {Client_Name}\nAmount: ₹{Quote_Amount}\n- {Firm_Name}",
        ],
    ];

    /**
     * Get list of modules permitted for current tenant.
     */
    public static function getPermittedModules(): array
    {
        $permitted = [];
        $tenantMap = \App\Services\FieldCustomizationService::$moduleTenantMap;

        foreach (self::$modules as $key => $info) {
            $requiredTenant = $tenantMap[$key] ?? null;
            if ($requiredTenant && function_exists('tenant_has_module')) {
                if (!tenant_has_module($requiredTenant)) {
                    continue;
                }
            }
            $permitted[$key] = $info;
        }

        return $permitted;
    }

    /**
     * Dispatch module notifications (both WhatsApp & Email if enabled).
     *
     * @param string $module Module key (e.g., 'leads', 'policies', 'customers')
     * @param array $placeholders Associative array of replacement placeholders
     * @param User|\App\Models\Customer|int|null $recipient Target recipient (User model, Customer model, user ID, or recipient info)
     * @param mixed $actorUser The user initiating the action
     */
    public static function dispatch(string $module, array $placeholders = [], $recipient = null, $actorUser = null): void
    {
        try {
            $email = null;
            $phone = null;
            $recipientName = null;

            if ($recipient instanceof \App\Models\Customer) {
                $email = $recipient->email;
                $phone = $recipient->whatsapp ?: $recipient->phone;
                $recipientName = $recipient->name ?: 'Valued Client';
                $placeholders['{Client_Name}'] = $recipientName;
                $placeholders['{Staff_Name}'] = $recipientName;
            } elseif ($recipient instanceof User) {
                $email = $recipient->email;
                $phone = $recipient->phone ?? $recipient->mobile ?? $recipient->contact_no ?? $recipient->whatsapp ?? '';
                $recipientName = $recipient->name ?: 'Team Member';
                $placeholders['{Staff_Name}'] = $recipientName;
            } elseif (is_numeric($recipient) && $recipient > 0) {
                $user = User::find($recipient);
                if ($user) {
                    $email = $user->email;
                    $phone = $user->phone ?? $user->mobile ?? $user->contact_no ?? $user->whatsapp ?? '';
                    $recipientName = $user->name ?: 'Team Member';
                    $placeholders['{Staff_Name}'] = $recipientName;
                }
            } elseif (is_array($recipient)) {
                $email = $recipient['email'] ?? null;
                $phone = $recipient['phone'] ?? null;
                $recipientName = $recipient['name'] ?? 'Recipient';
                $placeholders['{Staff_Name}'] = $recipientName;
            }

            if (!$email && !$phone) {
                return;
            }

            $firmName = (string) (Setting::where('key', 'company_name')->value('value') ?: config('app.name', 'Insurance CRM'));
            $placeholders['{Firm_Name}'] = $firmName;
            if (!isset($placeholders['{Staff_Name}'])) {
                $placeholders['{Staff_Name}'] = $recipientName ?: 'Team Member';
            }

            // 1. WhatsApp notification
            if ($phone) {
                self::sendWhatsApp($module, (string) $phone, $placeholders, $actorUser);
            }

            // 2. Email notification
            if ($email) {
                self::sendEmail($module, (string) $email, $placeholders);
            }
        } catch (\Throwable $e) {
            Log::warning("ModuleNotificationService::dispatch for '{$module}' failed: " . $e->getMessage());
        }
    }

    /**
     * Send WhatsApp notification if enabled for module.
     */
    protected static function sendWhatsApp(string $module, string $rawPhone, array $placeholders, $actorUser = null): bool
    {
        try {
            // Check if WhatsApp notification is enabled for this module
            $isEnabled = (Setting::where('key', "notif_wa_{$module}_enabled")->value('value') ?? '1') === '1';
            if (!$isEnabled) {
                return false;
            }

            $digits = preg_replace('/\D+/', '', $rawPhone);
            if ($digits === '') {
                return false;
            }
            if (strlen($digits) === 10) {
                $digits = '91' . $digits;
            }

            $templateDef = self::$modules[$module]['wa_message'] ?? '';
            // For leads, support legacy fallback whatsapp_personal_lead_message
            if ($module === 'leads') {
                $templateSetting = Setting::where('key', 'notif_wa_leads_template')->value('value')
                    ?: Setting::where('key', 'whatsapp_personal_lead_message')->value('value');
            } else {
                $templateSetting = Setting::where('key', "notif_wa_{$module}_template")->value('value');
            }

            $template = (string) ($templateSetting ?: $templateDef);
            $message = strtr($template, $placeholders);

            $sendMode = Setting::where('key', 'whatsapp_send_mode')->value('value') ?: 'business_api';

            if ($sendMode === 'personal_whatsapp') {
                $sessionId = 'user_' . (int) ($actorUser?->id ?: (auth()->id() ?: 1));
                $secret = (string) env('WHATSAPP_SESSION_SECRET', 'wa_session_secret_key_2026');
                $headers = ['x-session-secret' => $secret];
                $payload = [
                    'phone' => $digits,
                    'message' => $message,
                ];

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
                return true;
            }

            // WhatsApp Business API fallback
            if (class_exists(\App\Services\WhatsAppService::class)) {
                $waService = app(\App\Services\WhatsAppService::class);
                if ($waService->isConfigured()) {
                    $waService->sendForModule($module, $digits, array_values($placeholders));
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning("sendWhatsApp failed for module '{$module}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send Email notification if enabled for module.
     */
    protected static function sendEmail(string $module, string $email, array $placeholders): bool
    {
        try {
            $isEnabled = (Setting::where('key', "notif_email_{$module}_enabled")->value('value') ?? '1') === '1';
            if (!$isEnabled) {
                return false;
            }

            $email = trim($email);
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return false;
            }

            // Dynamically configure SMTP from database settings table
            self::configureSmtpFromDatabase();

            $defaultSubject = self::$modules[$module]['email_subject'] ?? 'Notification from {Firm_Name}';
            $defaultBody = self::$modules[$module]['email_body'] ?? "Hello {Staff_Name},\n\nYou have a new notification from {Firm_Name}.";

            $subjectTemplate = (string) (Setting::where('key', "notif_email_{$module}_subject")->value('value') ?: $defaultSubject);
            $bodyTemplate = (string) (Setting::where('key', "notif_email_{$module}_body")->value('value') ?: $defaultBody);

            $subject = strtr($subjectTemplate, $placeholders);
            $body = strtr($bodyTemplate, $placeholders);
            $firmName = $placeholders['{Firm_Name}'] ?? config('app.name');

            // Send email
            Mail::to($email)->send(new GenericNotificationMail($subject, $body, $firmName));
            Log::info("Module notification email sent successfully to {$email} for module '{$module}'");
            return true;
        } catch (\Throwable $e) {
            Log::warning("sendEmail failed for module '{$module}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Dynamically apply SMTP credentials from the settings database table.
     */
    public static function configureSmtpFromDatabase(): void
    {
        try {
            $settings = Setting::query()->where('key', 'like', 'mail_%')->pluck('value', 'key')->toArray();

            if (!empty($settings['mail_host'])) {
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.transport'  => 'smtp',
                    'mail.mailers.smtp.host'       => $settings['mail_host'],
                    'mail.mailers.smtp.port'       => (int) ($settings['mail_port'] ?? 587),
                    'mail.mailers.smtp.encryption' => !empty($settings['mail_encryption']) ? $settings['mail_encryption'] : 'tls',
                    'mail.mailers.smtp.username'   => $settings['mail_username'] ?? '',
                    'mail.mailers.smtp.password'   => $settings['mail_password'] ?? '',
                ]);

                $fromAddress = $settings['mail_from_address'] ?? ($settings['mail_username'] ?? config('mail.from.address'));
                $fromName = $settings['mail_from_name'] ?? (Setting::where('key', 'company_name')->value('value') ?: config('mail.from.name', 'Insurance CRM'));

                if (!empty($fromAddress)) {
                    config([
                        'mail.from.address' => $fromAddress,
                        'mail.from.name'    => $fromName,
                    ]);
                }

                // Purge resolved mailer instance to force Laravel to rebuild it with the new DB credentials
                app()->forgetInstance('mail.manager');
            }
        } catch (\Throwable $e) {
            Log::warning('configureSmtpFromDatabase failed: ' . $e->getMessage());
        }
    }
}
