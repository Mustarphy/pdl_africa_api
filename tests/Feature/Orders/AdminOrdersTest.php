<?php

namespace Tests\Feature\Orders;

use App\Mail\Orders\OrderStatusUpdated;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class AdminOrdersTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpCheckout();
        $this->admin = User::factory()->admin()->create(['name' => 'Store Owner']);
    }

    private function paidOrder(array $overrides = []): Order
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->placeOrder($product, $variant, $overrides);
        $this->paystackSays($order, 'success');
        $this->viewOrder($order);
        $this->flushSession();

        return $order->fresh();
    }

    private function setStatus(Order $order, array $data): TestResponse
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/admin/orders/{$order->reference}/status", $data);
    }

    public function test_only_administrators_can_reach_orders(): void
    {
        $order = $this->paidOrder();

        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
        $customer = User::factory()->create();
        $this->actingAs($customer)->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->actingAs($customer)->getJson("/api/v1/admin/orders/{$order->reference}")->assertForbidden();
        $this->actingAs($customer)->postJson("/api/v1/admin/orders/{$order->reference}/status", ['status' => 'processing'])->assertForbidden();
        $this->assertSame('confirmed', $order->fresh()->status->value);
    }

    public function test_orders_list_filters_searches_and_counts_real_records(): void
    {
        $paid = $this->paidOrder(['name' => 'Chidi Eze', 'email' => 'chidi@example.com']);
        [$product, $variant] = $this->productWithVariant();
        $unpaid = $this->placeOrder($product, $variant);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.new', 1)
            ->assertJsonPath('summary.awaiting_payment', 1);
        $this->assertSame([$unpaid->reference, $paid->reference], array_column($response->json('data'), 'reference'));
        $this->assertSame(2, $response->json('data.0.items_count'));

        $this->getJson('/api/v1/admin/orders?q=chidi')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.reference', $paid->reference);
        $this->getJson('/api/v1/admin/orders?q='.$unpaid->reference)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/orders?payment_status=paid')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/orders?status=pending_payment')->assertJsonPath('data.0.reference', $unpaid->reference);
        $this->getJson('/api/v1/admin/orders?from='.now()->addDay()->toDateString())->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/orders?status=shipped')->assertUnprocessable();

        // Reading the dashboard never changes an order.
        $this->getJson('/api/v1/admin/dashboard')->assertJsonPath('data.orders.new', 1);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_order_detail_shows_payments_and_history_without_secrets(): void
    {
        $order = $this->paidOrder();

        $response = $this->actingAs($this->admin)->getJson("/api/v1/admin/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.customer.email', 'amara@example.com')
            ->assertJsonPath('data.payments.0.status', 'success')
            ->assertJsonPath('data.history.1.to', 'confirmed')
            ->assertJsonPath('data.history.1.actor_type', 'system')
            ->assertJsonPath('data.allowed_transitions', ['processing', 'cancelled']);

        $json = $response->getContent();
        $this->assertStringNotContainsString('authorization_url', $json);
        $this->assertStringNotContainsString('checkout.paystack.com', $json);
        $this->assertStringNotContainsString(self::PAYSTACK_SECRET, $json);
    }

    public function test_fulfilment_follows_the_allowed_transitions_and_records_who(): void
    {
        $order = $this->paidOrder();

        $this->setStatus($order, ['status' => 'delivered'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->setStatus($order, ['status' => 'confirmed'])->assertUnprocessable();
        $this->setStatus($order, ['status' => 'processing', 'note' => 'Tailoring started'])->assertOk()->assertJsonPath('data.status', 'processing');
        $this->setStatus($order, ['status' => 'processing'])->assertUnprocessable();
        $this->setStatus($order, [
            'status' => 'dispatched', 'carrier' => 'GIG Logistics', 'tracking_number' => 'GIG123', 'tracking_url' => 'https://giglogistics.com/track/GIG123',
        ])->assertOk()
            ->assertJsonPath('data.tracking.number', 'GIG123')
            ->assertJsonPath('data.history.3.actor', 'Store Owner')
            ->assertJsonPath('data.history.2.note', 'Tailoring started');
        $this->setStatus($order, ['status' => 'processing'])->assertUnprocessable();
        $this->setStatus($order, ['status' => 'delivered'])->assertOk();
        $this->setStatus($order, ['status' => 'cancelled'])->assertUnprocessable();

        $order->refresh();
        $this->assertNotNull($order->dispatched_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(5, $order->statusHistory()->count());
        Mail::assertSent(OrderStatusUpdated::class, 2); // dispatched + delivered

        // The customer sees the progress and the courier details.
        $link = app(OrderAccess::class)->link($order, 60);
        $parts = parse_url($link);
        $this->withHeaders(self::STOREFRONT)->postJson('/api/v1'.$parts['path'].'/access?'.$parts['query'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.tracking.carrier', 'GIG Logistics')
            ->assertJsonCount(5, 'data.timeline')
            ->assertJsonMissingPath('data.timeline.2.note');
    }

    public function test_unpaid_orders_can_only_be_cancelled(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->placeOrder($product, $variant);

        foreach (['processing', 'ready_for_dispatch', 'dispatched', 'delivered'] as $status) {
            $this->setStatus($order, ['status' => $status])->assertUnprocessable();
        }
        $this->setStatus($order, ['status' => 'cancelled', 'note' => 'Customer changed their mind'])->assertOk();
        $this->assertFalse($order->fresh()->canBePaid());
    }

    public function test_tracking_details_are_validated_and_saved(): void
    {
        $order = $this->paidOrder();
        $tracking = fn (array $data) => $this->actingAs($this->admin)->putJson("/api/v1/admin/orders/{$order->reference}/tracking", $data);

        $tracking(['carrier' => 'DHL', 'tracking_number' => '1Z', 'tracking_url' => 'javascript:alert(1)'])->assertJsonValidationErrors('tracking_url');
        $tracking(['carrier' => 'DHL', 'tracking_number' => '1Z', 'tracking_url' => 'http://insecure.example'])->assertJsonValidationErrors('tracking_url');
        $tracking(['carrier' => 'DHL', 'tracking_number' => '1Z', 'tracking_url' => 'https://dhl.com/track?id=1Z'])->assertOk();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/orders/{$order->reference}/notes", ['fulfillment_notes' => 'Gift wrap'])->assertOk()
            ->assertJsonPath('data.fulfillment_notes', 'Gift wrap');

        $order->refresh();
        $this->assertSame(['DHL', '1Z', 'https://dhl.com/track?id=1Z'], [$order->carrier, $order->tracking_number, $order->tracking_url]);
    }

    public function test_admins_can_recheck_a_payment_with_paystack(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->placeOrder($product, $variant);
        $this->paystackSays($order, 'success');
        $this->flushSession();

        $this->actingAs($this->admin)->postJson("/api/v1/admin/orders/{$order->reference}/verify-payment")
            ->assertOk()->assertJsonPath('data.payment_status', 'paid');
    }
}
