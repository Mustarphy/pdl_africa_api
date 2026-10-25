<?php

namespace Tests\Feature\Account;

use App\Mail\Account\ConfirmEmailChange;
use App\Mail\Account\EmailAddressChanged;
use App\Mail\Account\PasswordChanged;
use App\Mail\Account\ResetPasswordLink;
use App\Mail\Account\VerifyEmailAddress;
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountEmailTemplatesTest extends TestCase
{
    /** @return array<string, array{Mailable, string}> */
    public static function mailables(): array
    {
        $link = 'https://shop.pdl-africa.test/account/verify-email?id=1&hash=abc&expires=1&signature=xyz';

        return [
            'verification' => [new VerifyEmailAddress('Amara', $link, 60), 'Verify your email for PDL Africa'],
            'verification resend' => [new VerifyEmailAddress('Amara', $link, 60, resend: true), 'Your new PDL Africa verification link'],
            'reset' => [new ResetPasswordLink('Amara', $link, 60), 'Reset your PDL Africa password'],
            'password changed' => [new PasswordChanged('Amara', viaReset: false, resetUrl: $link), 'Your PDL Africa password was changed'],
            'confirm email change' => [new ConfirmEmailChange('Amara', 'new@example.com', $link, 60), 'Confirm your new email for PDL Africa'],
            'email changed' => [new EmailAddressChanged('Amara', 'new@example.com'), 'The email on your PDL Africa account was changed'],
        ];
    }

    #[DataProvider('mailables')]
    public function test_account_emails_render_branded_html_and_plain_text(Mailable $mail, string $subject): void
    {
        $mail->assertHasSubject($subject);
        $mail->assertSeeInHtml('Hello Amara');
        $mail->assertSeeInHtml('PDL');
        $mail->assertSeeInText('Hello Amara');
        $mail->assertDontSeeInText('<table', false);

        if (! $mail instanceof EmailAddressChanged) {
            // The full link is present in both versions (& escaped in HTML).
            $mail->assertSeeInHtml('https://shop.pdl-africa.test/account/verify-email?id=1&amp;hash=abc', false);
            $mail->assertSeeInText('https://shop.pdl-africa.test/account/verify-email?id=1&hash=abc&expires=1&signature=xyz', false);
        }
    }
}
