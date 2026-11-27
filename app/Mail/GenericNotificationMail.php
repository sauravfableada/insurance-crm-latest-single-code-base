<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $emailSubject;
    public string $bodyContent;
    public ?string $firmName;
    public ?string $logoDataUri;

    /**
     * Create a new message instance.
     */
    public function __construct(string $subject, string $bodyContent, ?string $firmName = null)
    {
        $this->emailSubject = $subject;
        $this->bodyContent = $bodyContent;
        $this->firmName = $firmName ?: config('app.name', 'Insurance CRM');

        // Resolve logo as embedded data URI so email clients render it reliably
        $this->logoDataUri = $this->resolveLogoDataUri();
    }

    /**
     * Convert company logo or default fablead logo into a Base64 data URI.
     */
    protected function resolveLogoDataUri(): ?string
    {
        try {
            $logoSetting = \App\Models\Setting::where('key', 'company_logo_path')->value('value');
            if ($logoSetting && Storage::disk('public')->exists($logoSetting)) {
                $content = Storage::disk('public')->get($logoSetting);
                $mime = Storage::disk('public')->mimeType($logoSetting) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode($content);
            }

            // Fallback to project default logos in public/logo
            $defaultLogos = [
                public_path('logo/fableadLogo.jpg') => 'image/jpeg',
                public_path('logo/fableadcrmLogoold.png') => 'image/png',
                public_path('logo/riskon_logo.png') => 'image/png',
            ];

            foreach ($defaultLogos as $path => $mime) {
                if (file_exists($path)) {
                    $content = file_get_contents($path);
                    return 'data:' . $mime . ';base64,' . base64_encode($content);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('resolveLogoDataUri failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.generic-notification',
            with: [
                'emailSubject' => $this->emailSubject,
                'bodyContent'  => $this->bodyContent,
                'firmName'     => $this->firmName,
                'logoDataUri'  => $this->logoDataUri,
            ],
        );
    }
}
