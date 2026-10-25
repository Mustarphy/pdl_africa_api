<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpCheckout();
    }

    public function test_quote_prices_the_cart_from_the_database(): void
    {
        [$product, $variant] = $this->productWithVariant(price: 12_000_000);

        $this->withHeaders(self::STOREFRONT)->postJson('/api/v1/checkout/quote', [
            'items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 3, 'unit_price' => 1]],
            'country_code' => 'ng',
            'state' => 'lagos',
        ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.unit_price', 12_000_000)
            ->assertJsonPath('data.lines.0.line_total', 36_000_000)
            ->assertJsonPath('data.lines.0.size', 'L')
            ->assertJsonPath('data.subtotal', 36_000_000)
            ->assertJsonPath('data.shipping.fee', 300_000)
            ->assertJsonPath('data.shipping.zone', 'lagos')
            ->assertJsonPath('data.total', 36_300_000)
            ->assertJsonMissingPath('data.lines.0.stock');

        // Another state uses the nationwide fee; abroad is not configured.
        $this->postJson('/api/v1/checkout/quote', ['items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]], 'country_code' => 'NG', 'state' => 'Kano'])
            ->assertJsonPath('data.shipping.fee', 500_000);
        $this->postJson('/api/v1/checkout/quote', ['items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]], 'country_code' => 'GB', 'state' => 'London'])
            ->assertJsonPath('data.shipping', null)
            ->assertJsonPath('data.total', null);
    }

    public function test_checkout_creates_a_pending_order_from_server_prices(): void
    {
        [$product, $variant] = $this->productWithVariant(price: 15_000_000);
        $payload = $this->checkoutPayload($product, $variant);
        // Anything price-like from the browser is ignored.
        $payload['items'][0]['unit_price'] = 100;
        $payload['total'] = 100;
        $payload['status'] = 'confirmed';
        $payload['payment_status'] = 'paid';

        $response = $this->checkout($payload)->assertCreated();
        $this->assertStringStartsWith('https://checkout.paystack.com/', $response->json('data.authorization_url'));

        $order = Order::with(['lines', 'paymentAttempts', 'statusHistory'])->sole();
        $this->assertMatchesRegularExpression('/^PDL-[A-Z2-9]{8}$/', $order->reference);
        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(30_000_000, $order->subtotal);
        $this->assertSame(300_000, $order->shipping_fee);
        $this->assertSame(30_300_000, $order->amount);
        $this->assertSame('NGN', $order->currency);
        $this->assertSame('Lagos delivery', $order->shipping_label);
        $this->assertSame('Lekki', $order->city);

        $line = $order->lines->sole();
        $this->assertSame(['Royal Agbada', $variant->sku, 'L', 'Indigo', 2, 15_000_000, 30_000_000],
            [$line->name, $line->sku, $line->size, $line->color, $line->quantity, $line->unit_price, $line->line_total]);
        $this->assertSame('pending_payment', $order->statusHistory->sole()->to_status->value);

        $attempt = $order->paymentAttempts->sole();
        $this->assertStringStartsWith($order->reference.'-', $attempt->reference);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transaction/initialize')
            && $request['amount'] === 30_300_000
            && $request['currency'] === 'NGN'
            && $request['email'] === 'amara@example.com'
            && $request['reference'] === $attempt->reference
            && $request['callback_url'] === 'http://localhost:5173/checkout/complete?order='.$order->reference
            && $request->hasHeader('Authorization', 'Bearer '.self::PAYSTACK_SECRET));

        // The browser that placed the order may view it.
        $this->viewOrder($order)->assertOk()->assertJsonPath('data.total', 30_300_000)->assertJsonPath('data.can_pay', true);
    }

    public function test_delivery_can_be_paid_to_the_rider_instead_of_online(): void
    {
        config([
            'shipping.zones.lagos.fee' => null,
            'shipping.zones.lagos.collect_on_delivery' => true,
            'shipping.zones.nigeria.fee' => null,
            'shipping.zones.nigeria.collect_on_delivery' => false,
        ]);
        [$product, $variant] = $this->productWithVariant(price: 15_000_000);

        $this->postJson('/api/v1/checkout/quote', ['items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]], 'country_code' => 'NG', 'state' => 'Lagos'])
            ->assertJsonPath('data.shipping.pay_on_delivery', true)
            ->assertJsonPath('data.shipping.fee', 0)
            ->assertJsonPath('data.total', 30_000_000);
        // Zones without a fee or `collect` stay off.
        $this->postJson('/api/v1/checkout/quote', ['items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]], 'country_code' => 'NG', 'state' => 'Kano'])
            ->assertJsonPath('data.shipping', null);

        $order = $this->placeOrder($product, $variant, ['expected_total' => 30_000_000]);

        $this->assertTrue($order->shipping_on_delivery);
        $this->assertSame(0, $order->shipping_fee);
        $this->assertSame(30_000_000, $order->amount);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transaction/initialize') && $request['amount'] === 30_000_000);
        $this->viewOrder($order)->assertJsonPath('data.shipping_on_delivery', true);
    }

    public function test_the_collect_setting_is_read_from_the_environment(): void
    {
        putenv('SHIPPING_FEE_LAGOS=collect');
        putenv('SHIPPING_FEE_NIGERIA=4500');
        try {
            $zones = (require base_path('config/shipping.php'))['zones'];
        } finally {
            putenv('SHIPPING_FEE_LAGOS');
            putenv('SHIPPING_FEE_NIGERIA');
        }

        $this->assertTrue($zones['lagos']['collect_on_delivery']);
        $this->assertNull($zones['lagos']['fee']);
        $this->assertFalse($zones['nigeria']['collect_on_delivery']);
        $this->assertSame(450_000, $zones['nigeria']['fee']);
    }

    public function test_later_catalogue_edits_do_not_change_the_order(): void
    {
        [$product, $variant] = $this->productWithVariant(price: 15_000_000);
        $order = $this->placeOrder($product, $variant);

        $product->forceFill(['name' => 'Renamed Agbada', 'price' => 99_000_000])->save();

        $this->viewOrder($order)->assertJsonPath('data.items.0.name', 'Royal Agbada')->assertJsonPath('data.items.0.unit_price', 15_000_000);
    }

    public function test_a_changed_total_must_be_confirmed_again(): void
    {
        [$product, $variant] = $this->productWithVariant(price: 15_000_000);

        $this->checkout($this->checkoutPayload($product, $variant, ['expected_total' => 1_000]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')
            ->assertJsonPath('quote.total', 30_300_000);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $this->paystackInitializeCalls);
    }

    public function test_unavailable_items_block_checkout(): void
    {
        [$product, $variant] = $this->productWithVariant(stock: 1);
        $draft = Product::factory()->create(); // not published
        [$noStock, $soldOut] = $this->productWithVariant(stock: 0);
        [$withSizes] = $this->productWithVariant();

        $cases = [
            [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2], 'insufficient_stock'],
            [['product_id' => $draft->id, 'variant_id' => null, 'quantity' => 1], 'unavailable'],
            [['product_id' => $noStock->id, 'variant_id' => $soldOut->id, 'quantity' => 1], 'out_of_stock'],
            [['product_id' => $withSizes->id, 'variant_id' => null, 'quantity' => 1], 'variant_required'],
            [['product_id' => $product->id, 'variant_id' => $soldOut->id, 'quantity' => 1], 'unavailable'],
        ];
        foreach ($cases as [$item, $code]) {
            $this->checkout($this->checkoutPayload($product, $variant, ['items' => [$item]]))
                ->assertUnprocessable()
                ->assertJsonPath('code', 'cart_changed')
                ->assertJsonPath('quote.issues.0.code', $code);
        }

        // Inactive variants and price-on-request products too.
        $variant->forceFill(['is_active' => false])->save();
        $this->checkout($this->checkoutPayload($product, $variant, ['items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]]]))
            ->assertJsonPath('quote.issues.0.code', 'unavailable');
        $onRequest = $this->publishedProduct(['price' => null]);
        $this->checkout($this->checkoutPayload($onRequest, null, ['items' => [['product_id' => $onRequest->id, 'variant_id' => null, 'quantity' => 1]]]))
            ->assertJsonPath('quote.issues.0.code', 'price_on_request');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_delivery_details_are_validated(): void
    {
        [$product, $variant] = $this->productWithVariant();

        $this->checkout($this->checkoutPayload($product, $variant, [
            'name' => '', 'email' => 'nope', 'phone' => 'call me', 'address_line1' => '', 'city' => '', 'state' => '', 'country_code' => 'Nigeria',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'phone', 'address_line1', 'city', 'state', 'country_code']);

        $this->checkout($this->checkoutPayload($product, $variant, ['items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 11]]]))
            ->assertJsonValidationErrors('items.0.quantity');

        // A destination without a configured delivery fee.
        $this->checkout($this->checkoutPayload($product, $variant, ['country_code' => 'GB', 'state' => 'London']))
            ->assertUnprocessable()->assertJsonValidationErrors('country_code');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_repeated_submissions_return_the_same_order(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $payload = $this->checkoutPayload($product, $variant);

        $first = $this->checkout($payload)->assertCreated()->json('data');
        $second = $this->checkout($payload)->assertOk()->json('data');

        $this->assertSame($first['reference'], $second['reference']);
        $this->assertSame($first['authorization_url'], $second['authorization_url']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(1, $this->paystackInitializeCalls);
    }

    public function test_another_browser_cannot_reuse_a_checkout_key(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $payload = $this->checkoutPayload($product, $variant);
        $this->checkout($payload)->assertCreated();

        $this->flushSession();
        $this->checkout($payload)->assertStatus(409)->assertJsonMissingPath('data');
    }

    public function test_paystack_outages_keep_the_order_for_a_retry(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $payload = $this->checkoutPayload($product, $variant);

        $this->paystackDown = true;
        $this->checkout($payload)->assertStatus(503)->assertJsonPath('code', 'payment_unavailable');
        $order = Order::sole();
        $this->assertSame('failed', $order->paymentAttempts()->sole()->status->value);

        $this->paystackDown = false;
        $this->checkout($payload)->assertOk()->assertJsonPath('data.reference', $order->reference);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 2);
    }

    public function test_details_paystack_refuses_are_reported_as_such(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $this->paystackRejects = true;

        $this->checkout($this->checkoutPayload($product, $variant))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'payment_rejected');
        $this->assertSame('failed', Order::sole()->paymentAttempts()->sole()->status->value);
    }

    public function test_checkout_is_unavailable_without_paystack_keys(): void
    {
        config(['services.paystack.secret_key' => null]);
        [$product, $variant] = $this->productWithVariant();

        $this->checkout($this->checkoutPayload($product, $variant))->assertStatus(503);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_signed_in_customers_own_their_orders(): void
    {
        $customer = User::factory()->create();
        [$product, $variant] = $this->productWithVariant();

        $this->actingAs($customer);
        $order = $this->placeOrder($product, $variant);

        $this->assertSame($customer->id, $order->user_id);
    }
}
