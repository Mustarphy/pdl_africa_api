<?php

namespace Tests\Feature\Account;

use App\Enums\UserRole;
use App\Mail\Account\VerifyEmailAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesCustomerAccounts;
use Tests\TestCase;

class RegistrationAndLoginTest extends TestCase
{
    use RefreshDatabase, UsesCustomerAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function register(array $overrides = []): TestResponse
    {
        return $this->account()->postJson('/api/v1/account/register', [
            'name' => 'Amara Okafor',
            'email' => 'Amara@Example.com',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            ...$overrides,
        ]);
    }

    public function test_a_customer_can_register_and_is_signed_in_unverified(): void
    {
        $this->register(['role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'amara@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('meta.verification_email_sent', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.role');

        $user = User::where('email', 'amara@example.com')->firstOrFail();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $user->password));
        $this->assertAuthenticatedAs($user, 'web');

        Mail::assertSent(VerifyEmailAddress::class, fn (VerifyEmailAddress $mail) => $mail->hasTo('amara@example.com')
            && str_starts_with($mail->url, 'http://localhost:5173/account/verify-email?'));
    }

    public function test_registration_validates_input(): void
    {
        $this->register(['name' => '', 'email' => 'not-an-email', 'password' => 'short', 'password_confirmation' => 'other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        // Long enough but missing a symbol, and a mismatched confirmation.
        $this->register(['password' => 'Abcdefghijk1', 'password_confirmation' => 'Abcdefghijk1'])
            ->assertJsonValidationErrors('password');
        $this->register(['password_confirmation' => 'Different-Pass-1!'])
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingSent();
    }

    public function test_duplicate_emails_are_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'amara@example.com']);

        $this->register(['email' => 'AMARA@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'An account with this email already exists. Sign in or reset your password.');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_reports_an_undelivered_verification_email(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unavailable'));

        $this->register()->assertCreated()->assertJsonPath('meta.verification_email_sent', false);
        $this->assertDatabaseHas('users', ['email' => 'amara@example.com']);
    }

    public function test_registration_is_rate_limited(): void
    {
        foreach (range(1, 10) as $i) {
            $this->register(['email' => "bad{$i}"])->assertUnprocessable();
        }
        $this->register()->assertTooManyRequests();
    }

    public function test_signed_in_visitors_cannot_register_again(): void
    {
        $this->actingAs(User::factory()->create())->register()->assertStatus(409);
    }

    public function test_a_customer_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'amara@example.com', 'password' => self::STRONG_PASSWORD]);

        $this->account()->postJson('/api/v1/account/login', ['email' => ' Amara@Example.com ', 'password' => self::STRONG_PASSWORD, 'remember' => true])
            ->assertOk()
            ->assertJsonPath('data.email', 'amara@example.com')
            ->assertJsonPath('data.email_verified', true);
        $this->assertAuthenticatedAs($user, 'web');
        $this->account()->getJson('/api/v1/account/me')->assertOk()->assertJsonPath('data.name', $user->name);

        $rememberToken = $user->fresh()->remember_token;
        $this->account()->postJson('/api/v1/account/logout')->assertNoContent();
        $this->assertGuest('web');
        // "Keep me signed in" cookies stop working too.
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);

        // A real request starts with fresh guards (tests reuse them).
        $this->app['auth']->forgetGuards();
        $this->account()->getJson('/api/v1/account/me')->assertUnauthorized();
        $this->account()->patchJson('/api/v1/account/profile', ['name' => 'Changed'])->assertUnauthorized();
    }

    public function test_login_failures_do_not_reveal_which_part_was_wrong(): void
    {
        User::factory()->create(['email' => 'amara@example.com', 'password' => self::STRONG_PASSWORD]);
        User::factory()->admin()->create(['email' => 'admin@example.com', 'password' => self::STRONG_PASSWORD]);

        $messages = collect([
            ['amara@example.com', 'Wrong-Password-1!'],
            ['nobody@example.com', self::STRONG_PASSWORD],
            // Administrators sign in at /admin, never through the storefront.
            ['admin@example.com', self::STRONG_PASSWORD],
        ])->map(fn ($pair) => $this->account()
            ->postJson('/api/v1/account/login', ['email' => $pair[0], 'password' => $pair[1]])
            ->assertUnprocessable()
            ->json('errors.email.0'));

        $this->assertCount(1, $messages->unique());
        $this->assertSame('The email or password is incorrect.', $messages->first());
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/account/login', ['email' => 'amara@example.com', 'password' => 'guess'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/account/login', ['email' => 'amara@example.com', 'password' => 'guess'])->assertTooManyRequests();
    }

    public function test_unverified_customers_can_view_but_not_change_their_account(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->getJson('/api/v1/account/me')->assertOk()->assertJsonPath('data.email_verified', false);
        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => 'Changed'])->assertForbidden();
        $this->actingAs($user)->putJson('/api/v1/account/password', [])->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/account/email', [])->assertForbidden();
        $this->assertNotSame('Changed', $user->fresh()->name);
    }

    public function test_administrators_cannot_use_the_customer_account_area(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/v1/account/me')->assertForbidden();
        $this->actingAs($admin)->patchJson('/api/v1/account/profile', ['name' => 'Changed'])->assertForbidden();
    }

    public function test_account_responses_are_never_cached(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/account/me');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
