<?php

namespace App\Mail\Account;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Security notice to the previous address after an email change. */
class EmailAddressChanged extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $newEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'The email on your PDL Africa account was changed');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account.email-changed',
            text: 'emails.account.text.email-changed',
        );
    }
}
