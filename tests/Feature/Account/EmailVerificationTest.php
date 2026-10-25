<?php

namespace Tests\Feature\Account;

use App\Mail\Account\VerifyEmailAddress;
use App\Models\User;
use App\Services\Account\AccountLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\UsesCustomerAccounts;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase, UsesCustomerAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function linkFor(User $user): string
    {
        return $this->apiPathFromLink(app(AccountLinks::class)->verification($user), 'email/verify');
    }

    public function test_the_emailed_link_verifies_the_account_even_when_signed_out(): void
    {
        $this->account()->postJson('/api/v1/account/register', [
            'name' => 'Amara Okafor',
            'email' => 'amara@example.com',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertCreated();
        $path = $this->apiPathFromLink($this->sentVerificationLink('amara@example.com'), 'email/verify');

        $this->account()->postJson('/api/v1/account/logout')->assertNoContent();

        $this->account()->postJson($path)->assertOk()->assertJsonPath('data.status', 'verified');
        $this->assertTrue(User::where('email', 'amara@example.com')->firstOrFail()->hasVerifiedEmail());

        // Opening it again is harmless.
        $this->account()->postJson($path)->assertOk()->assertJsonPath('data.status', 'already_verified');
    }

    public function test_tampered_links_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $path = $this->linkFor($user);

        $this->postJson($path.'x')->assertForbidden()->assertJsonPath('reason', 'invalid');
        // Same signature, another account's id.
        $this->postJson(str_replace("/verify/{$user->id}/", "/verify/{$other->id}/", $path))->assertForbidden();
        $this->postJson("/api/v1/account/email/verify/{$user->id}/".sha1($user->email))->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_expired_links_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $path = $this->linkFor($user);

        $this->travel(61)->minutes();

        $this->postJson($path)->assertStatus(410)->assertJsonPath('reason', 'expired');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_links_stop_working_when_the_address_changes(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'old@example.com']);
        $path = $this->linkFor($user);
        $user->forceFill(['email' => 'new@example.com'])->save();

        $this->postJson($path)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_administrator_accounts_cannot_be_verified_through_customer_links(): void
    {
        $admin = User::factory()->admin()->unverified()->create();

        $this->postJson($this->linkFor($admin))->assertForbidden();
    }

    public function test_customers_can_request_a_new_link_with_rate_limiting(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->postJson('/api/v1/account/email/verification-notification')
            ->assertOk()->assertJsonPath('data.status', 'sent');
        Mail::assertSent(VerifyEmailAddress::class, fn (VerifyEmailAddress $mail) => $mail->resend && $mail->hasTo($user->email));

        $this->actingAs($user)->postJson('/api/v1/account/email/verification-notification')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/account/email/verification-notification')->assertTooManyRequests();
        Mail::assertSentCount(2);
    }

    public function test_resending_reports_delivery_failures(): void
    {
        $user = User::factory()->unverified()->create();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unavailable'));

        $this->actingAs($user)->postJson('/api/v1/account/email/verification-notification')->assertStatus(503);
    }

    public function test_resending_requires_a_signed_in_customer(): void
    {
        $this->postJson('/api/v1/account/email/verification-notification')->assertUnauthorized();

        $verified = User::factory()->create();
        $this->actingAs($verified)->postJson('/api/v1/account/email/verification-notification')
            ->assertOk()->assertJsonPath('data.status', 'already_verified');
        Mail::assertNothingSent();
    }
}
