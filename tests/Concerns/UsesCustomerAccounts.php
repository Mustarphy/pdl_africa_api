<?php

namespace Tests\Concerns;

use App\Mail\Account\ConfirmEmailChange;
use App\Mail\Account\ResetPasswordLink;
use App\Mail\Account\VerifyEmailAddress;
use Illuminate\Support\Facades\Mail;

trait UsesCustomerAccounts
{
    /** Requests from the storefront origin get Sanctum's cookie session. */
    protected const STATEFUL = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/account'];

    protected const STRONG_PASSWORD = 'Adire-Indigo-2026!';

    protected function account(): static
    {
        return $this->withHeaders(self::STATEFUL);
    }

    /** API path for a signed storefront link from an email. */
    protected function apiPathFromLink(string $link, string $route): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $signature = http_build_query(['expires' => $query['expires'], 'signature' => $query['signature']]);

        return "/api/v1/account/{$route}/{$query['id']}/{$query['hash']}?{$signature}";
    }

    protected function sentVerificationLink(string $email): string
    {
        $link = null;
        Mail::assertSent(VerifyEmailAddress::class, function (VerifyEmailAddress $mail) use ($email, &$link) {
            $link = $mail->url;

            return $mail->hasTo($email);
        });

        return $link;
    }

    protected function sentEmailChangeLink(string $email): string
    {
        $link = null;
        Mail::assertSent(ConfirmEmailChange::class, function (ConfirmEmailChange $mail) use ($email, &$link) {
            $link = $mail->url;

            return $mail->hasTo($email);
        });

        return $link;
    }

    /** @return array{token: string, email: string} */
    protected function sentResetFragment(string $email): array
    {
        $link = null;
        Mail::assertSent(ResetPasswordLink::class, function (ResetPasswordLink $mail) use ($email, &$link) {
            $link = $mail->url;

            return $mail->hasTo($email);
        });
        parse_str((string) parse_url($link, PHP_URL_FRAGMENT), $fragment);

        return $fragment;
    }
}
