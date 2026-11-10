<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OshelNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $intro
     * @param  array<string, string|null>  $details
     * @param  array<int, array{name: string, quantity: string, amount: string}>  $items
     * @param  array<int, array{label: string, value: string, emphasis?: bool}>  $totals
     * @param  array{label: string, url: string}|null  $action
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly ?string $preheader = null,
        public readonly ?string $recipientName = null,
        public readonly array $intro = [],
        public readonly array $details = [],
        public readonly array $items = [],
        public readonly array $totals = [],
        public readonly ?array $action = null,
        public readonly ?string $footnote = null,
        public readonly string $clinicName = 'Oshel Skin Care & Beauty Clinic',
        public readonly ?string $clinicEmail = null,
        public readonly ?string $clinicPhone = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->clinicEmail
                ? [new Address($this->clinicEmail, $this->clinicName)]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            text: 'emails.notification-text',
            with: [
                'subjectLine' => $this->subjectLine,
                'heading' => $this->heading,
                'preheader' => $this->preheader,
                'recipientName' => $this->recipientName,
                'intro' => $this->intro,
                'details' => $this->details,
                'items' => $this->items,
                'totals' => $this->totals,
                'action' => $this->action,
                'footnote' => $this->footnote,
                'clinicName' => $this->clinicName,
                'clinicEmail' => $this->clinicEmail,
                'clinicPhone' => $this->clinicPhone,
            ],
        );
    }
}
