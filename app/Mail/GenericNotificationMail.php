<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $emailSubject;
    public string $bodyContent;
    public ?string $firmName;

    /**
     * Create a new message instance.
     */
    public function __construct(string $subject, string $bodyContent, ?string $firmName = null)
    {
        $this->emailSubject = $subject;
        $this->bodyContent = $bodyContent;
        $this->firmName = $firmName;
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
                'bodyContent' => $this->bodyContent,
                'firmName' => $this->firmName,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
