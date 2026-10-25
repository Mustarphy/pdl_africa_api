<?php

namespace Tests\Feature\Account;

use App\Mail\Account\ConfirmEmailChange;
use App\Mail\Account\EmailAddressChanged;
use App\Mail\Account\PasswordChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\UsesCustomerAccounts;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase, UsesCustomerAccounts;

    private const NEW_PASSWORD = 'Kente-Gold-Thread-7?';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(['password' => self::STRONG_PASSWORD, ...$attributes]);
    }

    public function test_customers_see_only_their_own_account(): void
    {
        $user = $this->customer(['name' => 'Amara Okafor']);
        $this->customer(['name' => 'Someone Else']);

        $this->actingAs($user)->getJson('/api/v1/account/me')
            ->assertOk()
            ->assertExactJsonStructure(['data' => ['name', 'email', 'email_verified', 'email_verified_at', 'pending_email', 'created_at']])
            ->assertJsonPath('data.name', 'Amara Okafor');
    }

    public function test_customers_can_update_their_name(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => '  Amara N. Okafor '])
            ->assertOk()->assertJsonPath('data.name', 'Amara N. Okafor');
        $this->assertSame('Amara N. Okafor', $user->fresh()->name);

        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => ''])->assertJsonValidationErrors('name');
        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => str_repeat('a', 121)])->assertJsonValidationErrors('name');
    }

    public function test_customers_cannot_change_other_accounts_or_protected_fields(): void
    {
        $user = $this->customer(['email' => 'amara@example.com']);
        $other = $this->customer(['name' => 'Other Customer', 'email' => 'other@example.com']);

        $this->actingAs($user)->patchJson('/api/v1/account/profile', [
            'id' => $other->id,
            'name' => 'Renamed',
            'email' => 'hijack@example.com',
            'role' => 'admin',
            'email_verified_at' => null,
        ])->assertOk();

        $this->assertSame('Other Customer', $other->fresh()->name);
        $user->refresh();
        $this->assertSame('Renamed', $user->name);
        $this->assertSame('amara@example.com', $user->email);
        $this->assertTrue($user->isCustomer());
        $this->assertTrue($user->hasVerifiedEmail());
        // There is no route that takes another customer's id.
        $this->actingAs($user)->getJson("/api/v1/account/{$other->id}")->assertNotFound();
    }

    public function test_changing_the_password_requires_the_current_one_and_strong_rules(): void
    {
        $user = $this->customer();
        $change = fn (array $fields) => $this->actingAs($user)->putJson('/api/v1/account/password', [
            'current_password' => self::STRONG_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            ...$fields,
        ]);

        $change(['current_password' => 'Wrong-Password-1!'])->assertJsonPath('errors.current_password.0', 'Your current password is incorrect.');
        $change(['password' => 'weakpass', 'password_confirmation' => 'weakpass'])->assertJsonValidationErrors('password');
        $change(['password_confirmation' => 'Mismatch-Pass-1!'])->assertJsonValidationErrors('password');
        $change(['password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD])->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $user->fresh()->password));
        Mail::assertNothingSent();
    }

    public function test_changing_the_password_signs_out_other_devices_only(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->customer(['email' => 'amara@example.com']);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->account()->postJson('/api/v1/account/login', ['email' => 'amara@example.com', 'password' => self::STRONG_PASSWORD])->assertOk();
        $this->account()->putJson('/api/v1/account/password', [
            'current_password' => self::STRONG_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->account()->getJson('/api/v1/account/me')->assertOk();
        Mail::assertSent(PasswordChanged::class, fn (PasswordChanged $mail) => ! $mail->viaReset && $mail->hasTo('amara@example.com'));
    }

    public function test_an_email_change_applies_only_after_the_new_address_confirms_it(): void
    {
        $user = $this->customer(['email' => 'amara@example.com']);

        $this->actingAs($user)->postJson('/api/v1/account/email', ['email' => 'New@Example.com', 'current_password' => self::STRONG_PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.email', 'amara@example.com')
            ->assertJsonPath('data.pending_email', 'new@example.com')
            ->assertJsonPath('meta.confirmation_email_sent', true);
        $path = $this->apiPathFromLink($this->sentEmailChangeLink('new@example.com'), 'email/confirm');

        // Until then nothing changes: the old address still signs in.
        $this->assertSame('amara@example.com', $user->fresh()->email);

        $this->postJson($path)->assertOk()->assertJsonPath('data.status', 'changed');
        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertTrue($user->hasVerifiedEmail());
        Mail::assertSent(EmailAddressChanged::class, fn (EmailAddressChanged $mail) => $mail->hasTo('amara@example.com'));

        $this->postJson($path)->assertForbidden()->assertJsonPath('reason', 'invalid');
    }

    public function test_email_change_requests_are_validated(): void
    {
        $user = $this->customer(['email' => 'amara@example.com']);
        $this->customer(['email' => 'taken@example.com']);
        $request = fn (array $fields) => $this->actingAs($user)->postJson('/api/v1/account/email', [
            'email' => 'new@example.com', 'current_password' => self::STRONG_PASSWORD, ...$fields,
        ]);

        $request(['current_password' => 'Wrong-Password-1!'])->assertJsonValidationErrors('current_password');
        $request(['email' => 'taken@example.com'])->assertJsonValidationErrors('email');
        $request(['email' => 'AMARA@example.com'])->assertJsonValidationErrors('email');
        $request(['email' => 'nope'])->assertJsonValidationErrors('email');

        $this->assertNull($user->fresh()->pending_email);
        Mail::assertNothingSent();
    }

    public function test_an_address_taken_before_confirmation_is_not_transferred(): void
    {
        $user = $this->customer(['email' => 'amara@example.com']);
        $this->actingAs($user)->postJson('/api/v1/account/email', ['email' => 'new@example.com', 'current_password' => self::STRONG_PASSWORD]);
        $path = $this->apiPathFromLink($this->sentEmailChangeLink('new@example.com'), 'email/confirm');
        $this->customer(['email' => 'new@example.com']);

        $this->postJson($path)->assertStatus(409);
        $this->assertSame('amara@example.com', $user->fresh()->email);
    }

    public function test_cancelled_and_expired_email_changes_cannot_be_confirmed(): void
    {
        $user = $this->customer(['email' => 'amara@example.com']);
        $this->actingAs($user)->postJson('/api/v1/account/email', ['email' => 'new@example.com', 'current_password' => self::STRONG_PASSWORD]);
        $path = $this->apiPathFromLink($this->sentEmailChangeLink('new@example.com'), 'email/confirm');

        $this->travel(61)->minutes();
        $this->postJson($path)->assertStatus(410);

        $this->actingAs($user)->deleteJson('/api/v1/account/email')->assertOk()->assertJsonPath('data.pending_email', null);
        $this->postJson($path)->assertForbidden();
        $this->assertSame('amara@example.com', $user->fresh()->email);
        Mail::assertNotSent(ConfirmEmailChange::class, fn ($mail) => $mail->hasTo('amara@example.com'));
    }
}
