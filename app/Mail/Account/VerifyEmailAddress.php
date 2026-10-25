<?php

namespace App\Mail\Account;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Welcome email with the signed link that verifies a customer's address. */
class VerifyEmailAddress extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly int $expiresInMinutes,
        public readonly bool $resend = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->resend ? 'Your new PDL Africa verification link' : 'Verify your email for PDL Africa');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account.verify-email',
            text: 'emails.account.text.verify-email',
        );
    }
}
