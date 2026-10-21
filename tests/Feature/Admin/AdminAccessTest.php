<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private const STATEFUL = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/admin'];

    public function test_guests_cannot_reach_any_admin_endpoint(): void
    {
        $product = Product::factory()->create();

        foreach ([
            ['GET', '/api/v1/admin/dashboard'],
            ['GET', '/api/v1/admin/products'],
            ['POST', '/api/v1/admin/products'],
            ['PUT', "/api/v1/admin/products/{$product->id}"],
            ['POST', "/api/v1/admin/products/{$product->id}/status"],
            ['DELETE', "/api/v1/admin/products/{$product->id}"],
            ['POST', '/api/v1/admin/collections'],
            ['POST', '/api/v1/admin/categories'],
        ] as [$method, $uri]) {
            $this->json($method, $uri, ['name' => 'x'])->assertUnauthorized();
        }
        $this->assertSame('draft', $product->fresh()->status->value);
    }

    public function test_guests_without_a_json_accept_header_get_a_json_401(): void
    {
        // Bots and scanners rarely send Accept: application/json; there is no
        // login page to redirect them to, so this must not become a 500.
        $this->get('/api/v1/admin/dashboard')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->actingAs($customer)->postJson('/api/v1/admin/categories', ['name' => 'Agbada'])->assertForbidden();
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_admin_can_log_in_and_out_with_a_session(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@pdl.test', 'password' => 'Correct-Horse-9!']);

        $this->withHeaders(self::STATEFUL)
            ->postJson('/api/v1/admin/login', ['email' => 'admin@pdl.test', 'password' => 'Correct-Horse-9!'])
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@pdl.test')
            ->assertJsonMissingPath('data.password');
        $this->assertAuthenticatedAs($admin, 'web');

        $this->withHeaders(self::STATEFUL)->getJson('/api/v1/admin/me')->assertOk();
        $this->withHeaders(self::STATEFUL)->postJson('/api/v1/admin/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_login_fails_identically_for_wrong_password_and_non_admins(): void
    {
        User::factory()->admin()->create(['email' => 'admin@pdl.test', 'password' => 'Correct-Horse-9!']);
        User::factory()->create(['email' => 'customer@pdl.test', 'password' => 'Correct-Horse-9!']);

        $wrong = $this->withHeaders(self::STATEFUL)->postJson('/api/v1/admin/login', ['email' => 'admin@pdl.test', 'password' => 'nope'])
            ->assertUnprocessable()->json('errors.email.0');
        $customer = $this->withHeaders(self::STATEFUL)->postJson('/api/v1/admin/login', ['email' => 'customer@pdl.test', 'password' => 'Correct-Horse-9!'])
            ->assertUnprocessable()->json('errors.email.0');

        $this->assertSame($wrong, $customer);
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/admin/login', ['email' => 'admin@pdl.test', 'password' => 'guess'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/admin/login', ['email' => 'admin@pdl.test', 'password' => 'guess'])->assertTooManyRequests();
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        // Strict mode turns a silently discarded privileged field into an error.
        $this->expectException(MassAssignmentException::class);
        new User(['name' => 'X', 'email' => 'x@pdl.test', 'password' => 'secret', 'role' => 'admin']);
    }

    public function test_create_admin_command_prompts_for_a_strong_password(): void
    {
        $this->artisan('pdl:create-admin', ['email' => 'Owner@PDL.test'])
            ->expectsQuestion('Name', 'Owner')
            ->expectsQuestion('Password (min 12 characters, mixed case, a number and a symbol)', 'Very-Strong-Pass-1!')
            ->expectsQuestion('Confirm password', 'Very-Strong-Pass-1!')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'owner@pdl.test')->firstOrFail()->isAdmin());
    }
}
