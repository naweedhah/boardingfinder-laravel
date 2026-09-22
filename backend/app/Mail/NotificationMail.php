<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $recipientName = null,
        public array $details = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[BoardingFinder] {$this->title}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notification',
            text: 'mail.notification-text',
        );
    }
}
