<?php

namespace Tests\Feature\Account;

use App\Mail\Account\PasswordChanged;
use App\Mail\Account\ResetPasswordLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesCustomerAccounts;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase, UsesCustomerAccounts;

    private const NEW_PASSWORD = 'Kente-Gold-Thread-7?';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function requestLink(string $email): TestResponse
    {
        return $this->postJson('/api/v1/account/forgot-password', ['email' => $email]);
    }

    private function reset(array $fields): TestResponse
    {
        return $this->postJson('/api/v1/account/reset-password', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            ...$fields,
        ]);
    }

    public function test_the_response_never_reveals_whether_an_account_exists(): void
    {
        User::factory()->create(['email' => 'amara@example.com']);
        User::factory()->admin()->create(['email' => 'admin@example.com']);

        $known = $this->requestLink('Amara@example.com')->assertOk()->json();
        $unknown = $this->requestLink('nobody@example.com')->assertOk()->json();
        $admin = $this->requestLink('admin@example.com')->assertOk()->json();
        // A second request inside the broker's throttle window looks the same.
        $throttled = $this->requestLink('amara@example.com')->assertOk()->json();

        $this->assertSame($known, $unknown);
        $this->assertSame($known, $admin);
        $this->assertSame($known, $throttled);
        Mail::assertSent(ResetPasswordLink::class, 1);
        Mail::assertSent(ResetPasswordLink::class, fn (ResetPasswordLink $mail) => $mail->hasTo('amara@example.com')
            && str_starts_with($mail->url, 'http://localhost:5173/account/reset-password#'));
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        foreach (range(1, 3) as $_) {
            $this->requestLink('amara@example.com')->assertOk();
        }
        $this->requestLink('amara@example.com')->assertTooManyRequests();
    }

    public function test_a_customer_can_reset_their_password_and_sign_in_with_it(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'amara@example.com', 'password' => self::STRONG_PASSWORD]);
        DB::table('sessions')->insert(['id' => 'stolen-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $rememberToken = $user->remember_token;

        $this->requestLink('amara@example.com');
        $this->reset($this->sentResetFragment('amara@example.com'))->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'stolen-session']);
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);
        Mail::assertSent(PasswordChanged::class, fn (PasswordChanged $mail) => $mail->viaReset && $mail->hasTo('amara@example.com'));

        $this->account()->postJson('/api/v1/account/login', ['email' => 'amara@example.com', 'password' => self::STRONG_PASSWORD])
            ->assertUnprocessable();
        $this->account()->postJson('/api/v1/account/login', ['email' => 'amara@example.com', 'password' => self::NEW_PASSWORD])
            ->assertOk();
    }

    public function test_reset_tokens_are_single_use(): void
    {
        User::factory()->create(['email' => 'amara@example.com']);
        $this->requestLink('amara@example.com');
        $fields = $this->sentResetFragment('amara@example.com');

        $this->reset($fields)->assertOk();
        $this->reset([...$fields, 'password' => 'Another-Pass-99!', 'password_confirmation' => 'Another-Pass-99!'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_invalid_and_expired_tokens_are_rejected(): void
    {
        $user = User::factory()->create(['email' => 'amara@example.com']);
        $password = $user->password;
        $this->requestLink('amara@example.com');
        $fields = $this->sentResetFragment('amara@example.com');

        $this->reset([...$fields, 'token' => 'not-the-token'])->assertJsonValidationErrors('token');
        // A valid token only works for the address it was issued to.
        $other = User::factory()->create(['email' => 'other@example.com']);
        $this->reset([...$fields, 'email' => 'other@example.com'])->assertJsonValidationErrors('token');

        $this->travel(61)->minutes();
        $this->reset($fields)->assertJsonValidationErrors('token');

        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame($other->password, $other->fresh()->password);
    }

    public function test_the_new_password_must_meet_the_rules(): void
    {
        User::factory()->create(['email' => 'amara@example.com']);
        $this->requestLink('amara@example.com');
        $fields = $this->sentResetFragment('amara@example.com');

        $this->reset([...$fields, 'password' => 'weak', 'password_confirmation' => 'weak'])->assertJsonValidationErrors('password');
        $this->reset([...$fields, 'password_confirmation' => 'Mismatch-Pass-1!'])->assertJsonValidationErrors('password');
        // The token survives failed validation.
        $this->reset($fields)->assertOk();
    }
}
