<?php

namespace App\Mail\Account;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Password reset link (single use, time limited by the password broker). */
class ResetPasswordLink extends Mailable
{
    public function __construct(
        public readonly string $name,
        #[\SensitiveParameter] public readonly string $url,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your PDL Africa password');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account.reset-password',
            text: 'emails.account.text.reset-password',
        );
    }
}
