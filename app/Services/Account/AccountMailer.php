<?php

namespace App\Services\Account;

use App\Mail\Account\ConfirmEmailChange;
use App\Mail\Account\EmailAddressChanged;
use App\Mail\Account\PasswordChanged;
use App\Mail\Account\ResetPasswordLink;
use App\Mail\Account\VerifyEmailAddress;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends account emails synchronously (no queue worker runs in production).
 * A delivery failure is reported to the log, never to the customer, and the
 * caller decides what to tell them. Mail credentials come from MAIL_* env.
 */
class AccountMailer
{
    public function __construct(private readonly AccountLinks $links) {}

    public function verification(User $user, bool $resend = false): bool
    {
        return $this->send($user->email, new VerifyEmailAddress(
            name: $user->name,
            url: $this->links->verification($user),
            expiresInMinutes: config('account.link_expire_minutes'),
            resend: $resend,
        ));
    }

    public function passwordReset(User $user, #[\SensitiveParameter] string $token): bool
    {
        return $this->send($user->email, new ResetPasswordLink(
            name: $user->name,
            url: $this->links->passwordReset($user, $token),
            expiresInMinutes: config('auth.passwords.users.expire'),
        ));
    }

    public function passwordChanged(User $user, bool $viaReset): bool
    {
        return $this->send($user->email, new PasswordChanged(
            name: $user->name,
            viaReset: $viaReset,
            resetUrl: $this->links->frontend('/account/forgot-password'),
        ));
    }

    public function emailChange(User $user): bool
    {
        return $this->send((string) $user->pending_email, new ConfirmEmailChange(
            name: $user->name,
            newEmail: (string) $user->pending_email,
            url: $this->links->emailChange($user),
            expiresInMinutes: config('account.link_expire_minutes'),
        ));
    }

    public function emailChanged(User $user, string $previousEmail): bool
    {
        return $this->send($previousEmail, new EmailAddressChanged(
            name: $user->name,
            newEmail: $user->email,
        ));
    }

    private function send(string $to, Mailable $mail): bool
    {
        try {
            Mail::to($to)->send($mail);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
