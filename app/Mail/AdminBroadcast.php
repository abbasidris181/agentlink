<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AdminBroadcast extends Mailable
{
    public function __construct(public string $notificationSubject, public string $notificationMessage) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notificationSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.admin-broadcast');
    }
}
