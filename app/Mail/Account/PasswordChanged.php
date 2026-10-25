<?php

namespace App\Mail\Account;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Security notice after a password change or reset. */
class PasswordChanged extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly bool $viaReset,
        public readonly string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your PDL Africa password was changed');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account.password-changed',
            text: 'emails.account.text.password-changed',
        );
    }
}
