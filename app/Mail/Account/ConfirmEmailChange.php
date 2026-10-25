<?php

namespace App\Mail\Account;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent to a requested new address; the change applies only once confirmed. */
class ConfirmEmailChange extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $newEmail,
        public readonly string $url,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your new email for PDL Africa');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account.confirm-email-change',
            text: 'emails.account.text.confirm-email-change',
        );
    }
}
