<?php

namespace Tests\Feature\Orders;

use App\Mail\Orders\OrderAccessLink;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class OrderAccessTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpCheckout();
    }

    private function guestOrder(): Order
    {
        [$product, $variant] = $this->productWithVariant();

        return $this->placeOrder($product, $variant);
    }

    private function apiPath(string $link): string
    {
        $parts = parse_url($link);

        return '/api/v1'.$parts['path'].'/access?'.$parts['query'];
    }

    public function test_an_order_reference_alone_does_not_open_an_order(): void
    {
        $order = $this->guestOrder();
        $this->flushSession();

        $this->viewOrder($order)->assertNotFound();
        $this->withHeaders(self::STOREFRONT)->postJson("/api/v1/orders/{$order->reference}/pay")->assertNotFound();
        // Indistinguishable from an order that does not exist.
        $this->withHeaders(self::STOREFRONT)->getJson('/api/v1/orders/PDL-NOTREAL1')->assertNotFound();
    }

    public function test_customers_see_only_their_own_orders(): void
    {
        [$owner, $other] = User::factory()->count(2)->create();
        [$product, $variant] = $this->productWithVariant();
        $this->actingAs($owner);
        $order = $this->placeOrder($product, $variant);
        $this->flushSession();

        $this->actingAs($owner)->getJson('/api/v1/orders/'.$order->reference)->assertOk();
        $this->actingAs($owner)->getJson('/api/v1/account/orders')
            ->assertOk()->assertJsonPath('data.0.reference', $order->reference)->assertJsonPath('data.0.items_count', 2);

        $this->app['auth']->forgetGuards();
        $this->actingAs($other)->getJson('/api/v1/orders/'.$order->reference)->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/account/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/account/orders')->assertOk(); // still $other
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/account/orders')->assertUnauthorized();
    }

    public function test_a_signed_email_link_grants_access(): void
    {
        $order = $this->guestOrder();
        $link = app(OrderAccess::class)->link($order, 60);
        $this->flushSession();

        $this->assertStringStartsWith('http://localhost:5173/orders/'.$order->reference.'?', $link);
        $this->withHeaders(self::STOREFRONT)->postJson($this->apiPath($link))
            ->assertOk()->assertJsonPath('data.reference', $order->reference);
        $this->viewOrder($order)->assertOk();
    }

    public function test_tampered_or_expired_links_are_rejected(): void
    {
        $order = $this->guestOrder();
        $other = $this->guestOrder();
        $link = app(OrderAccess::class)->link($order, 60);
        $this->flushSession();

        $this->withHeaders(self::STOREFRONT)->postJson(str_replace($order->reference, $other->reference, $this->apiPath($link)))->assertForbidden();
        $this->withHeaders(self::STOREFRONT)->postJson($this->apiPath($link).'0')->assertForbidden();
        $this->travel(61)->minutes();
        $this->withHeaders(self::STOREFRONT)->postJson($this->apiPath($link))->assertStatus(410);

        $this->viewOrder($order)->assertNotFound();
        $this->viewOrder($other)->assertNotFound();
    }

    public function test_track_order_emails_a_link_only_to_the_order_address(): void
    {
        $order = $this->guestOrder();
        $lookup = fn (array $data) => $this->postJson('/api/v1/orders/lookup', $data)->assertOk()->json('message');

        $wrongEmail = $lookup(['reference' => $order->reference, 'email' => 'someone@example.com']);
        $unknown = $lookup(['reference' => 'PDL-AAAAAAAA', 'email' => 'amara@example.com']);
        Mail::assertNothingSent();

        $match = $lookup(['reference' => strtolower($order->reference), 'email' => 'AMARA@example.com']);
        $this->assertSame($wrongEmail, $match);
        $this->assertSame($unknown, $match);
        Mail::assertSent(OrderAccessLink::class, fn (OrderAccessLink $mail) => $mail->hasTo('amara@example.com')
            && str_contains($mail->url, '/orders/'.$order->reference.'?expires='));
    }

    public function test_track_order_is_rate_limited_per_order(): void
    {
        $order = $this->guestOrder();
        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/orders/lookup', ['reference' => $order->reference, 'email' => 'x@example.com'])->assertOk();
        }
        $this->postJson('/api/v1/orders/lookup', ['reference' => $order->reference, 'email' => 'x@example.com'])->assertTooManyRequests();
    }
}
