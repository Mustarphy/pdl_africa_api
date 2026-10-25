<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\Orders\OrderConfirmed;
use App\Models\PaymentEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpCheckout();
    }

    private function verifyCalls(): int
    {
        return count(Http::recorded(fn ($request) => str_contains($request->url(), '/transaction/verify/')));
    }

    public function test_returning_from_paystack_confirms_a_verified_payment_once(): void
    {
        [$product, $variant] = $this->productWithVariant(stock: 5);
        $order = $this->placeOrder($product, $variant);
        $this->paystackSays($order, 'success');

        $this->viewOrder($order)->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.can_pay', false)
            ->assertJsonPath('data.timeline.1.status', 'confirmed');

        $order->refresh();
        $this->assertNotNull($order->paid_at);
        $this->assertSame(3, $variant->fresh()->stock_quantity);
        $this->assertFalse($order->stock_issue);
        Mail::assertSent(OrderConfirmed::class, fn (OrderConfirmed $mail) => $mail->hasTo('amara@example.com')
            && str_starts_with($mail->url, 'http://localhost:5173/orders/'.$order->reference.'?'));

        // Refreshes, callbacks and webhooks later change nothing.
        $this->travel(1)->minutes();
        $this->viewOrder($order)->assertOk();
        $this->webhook($this->chargeSuccess($order->paymentAttempts()->sole()->reference))->assertNoContent();
        Mail::assertSent(OrderConfirmed::class, 1);
        $this->assertSame(3, $variant->fresh()->stock_quantity);
        $this->assertSame(2, $order->statusHistory()->count());
    }

    public function test_reaching_the_return_page_alone_never_marks_an_order_paid(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->placeOrder($product, $variant);

        // Paystack has no record yet, then reports the payment as ongoing.
        $this->viewOrder($order)->assertJsonPath('data.payment_status', 'pending');
        $this->paystackSays($order, 'ongoing');
        $this->travel(11)->seconds();
        $this->viewOrder($order)->assertJsonPath('data.payment_status', 'pending')->assertJsonPath('data.can_pay', true);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_failed_payments_leave_the_order_payable(): void
    {
        [$product, $variant] = $this->productWithVariant(stock: 5);
        $order = $this->placeOrder($product, $variant);
        $this->paystackSays($order, 'failed');

        $this->viewOrder($order)
            ->assertJsonPath('data.payment_status', 'failed')
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.can_pay', true);
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        Mail::assertNothingSent();
    }

    public function test_wrong_amount_or_currency_is_never_accepted(): void
    {
        [$product, $variant] = $this->productWithVariant();

        foreach ([['amount' => 100], ['currency' => 'USD'], ['reference' => 'SOMETHING-ELSE']] as $mismatch) {
            $order = $this->placeOrder($product, $variant);
            $this->paystackSays($order, 'success', $mismatch);

            $this->viewOrder($order)->assertJsonPath('data.payment_status', 'pending');
            $attempt = $order->paymentAttempts()->sole();
            $this->assertSame('failed', $attempt->status->value);
            $this->assertSame('Amount or currency did not match the order', $attempt->gateway_response);
        }
        Mail::assertNothingSent();
    }

    public function test_status_checks_are_throttled(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->placeOrder($product, $variant);
        $this->paystackSays($order, 'ongoing');

        $this->viewOrder($order);
        $this->viewOrder($order);
        $this->viewOrder($order);

        $this->assertSame(1, $this->verifyCalls());
    }

    public function test_webhooks_must_be_signed(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->placeOrder($product, $variant);
        $this->paystackSays($order, 'success');
        $reference = $order->paymentAttempts()->sole()->reference;

        $this->webhook($this->chargeSuccess($reference), secret: 'sk_test_someone_else')->assertUnauthorized();
        $this->webhook($this->chargeSuccess($reference), secret: null)->assertUnauthorized();

        $this->assertFalse($order->fresh()->isPaid());
        $this->assertSame(0, $this->verifyCalls());
        $this->assertDatabaseCount('payment_events', 0);
    }

    public function test_a_signed_webhook_confirms_the_payment_after_verifying_it(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->placeOrder($product, $variant);
        $reference = $this->paystackSays($order, 'success');

        // The payload claims a different amount: only Paystack's API counts.
        $this->webhook($this->chargeSuccess($reference))->assertNoContent();

        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame(1, $this->verifyCalls());
        $this->assertSame('applied', PaymentEvent::sole()->outcome);

        // Paystack delivers the same event again.
        $this->webhook($this->chargeSuccess($reference))->assertNoContent();
        $this->assertSame(1, $this->verifyCalls());
        Mail::assertSent(OrderConfirmed::class, 1);
    }

    public function test_out_of_order_results_end_with_the_successful_payment(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->placeOrder($product, $variant);

        $reference = $this->paystackSays($order, 'abandoned');
        $this->viewOrder($order)->assertJsonPath('data.payment_status', 'pending');

        $this->paystackSays($order, 'success');
        $this->webhook($this->chargeSuccess($reference))->assertNoContent();
        $this->assertTrue($order->fresh()->isPaid());

        // A late "failed" answer cannot undo a verified success.
        $this->paystackSays($order, 'failed');
        $this->travel(1)->minutes();
        $this->viewOrder($order)->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_webhooks_are_retried_when_paystack_cannot_be_reached(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->placeOrder($product, $variant);
        $reference = $this->paystackSays($order, 'success');

        $this->paystackDown = true;
        $this->webhook($this->chargeSuccess($reference))->assertStatus(503);
        $this->assertFalse($order->fresh()->isPaid());

        $this->paystackDown = false;
        $this->webhook($this->chargeSuccess($reference))->assertNoContent();
        $this->assertTrue($order->fresh()->isPaid());
    }

    public function test_unknown_and_unhandled_events_are_acknowledged(): void
    {
        $this->webhook($this->chargeSuccess('NOT-OURS', 1))->assertNoContent();
        $this->webhook(['event' => 'transfer.success', 'data' => ['id' => 2, 'reference' => 'x']])->assertNoContent();

        $this->assertEqualsCanonicalizing(['unknown_reference', 'ignored'], PaymentEvent::pluck('outcome')->all());
    }

    public function test_retrying_reuses_the_open_payment_and_never_charges_a_paid_order(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->placeOrder($product, $variant);
        $firstUrl = $order->paymentAttempts()->sole()->authorization_url;
        $pay = fn () => $this->withHeaders(self::STOREFRONT)->postJson("/api/v1/orders/{$order->reference}/pay");

        // Still open: the same Paystack page.
        $pay()->assertOk()->assertJsonPath('data.authorization_url', $firstUrl);
        $this->assertSame(1, $this->paystackInitializeCalls);

        // After a failure, a fresh attempt with a new reference.
        $this->paystackSays($order, 'failed');
        $newUrl = $pay()->assertOk()->json('data.authorization_url');
        $this->assertNotSame($firstUrl, $newUrl);
        $this->assertSame(2, $order->paymentAttempts()->count());
        $this->assertSame(2, $order->paymentAttempts()->distinct()->count('reference'));

        // Paid in the meantime: no new charge is started.
        $this->paystackSays($order, 'success');
        $pay()->assertStatus(409)->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame(2, $this->paystackInitializeCalls);
    }

    public function test_a_second_successful_payment_is_flagged_not_reapplied(): void
    {
        [$product, $variant] = $this->productWithVariant(stock: 5);
        $order = $this->placeOrder($product, $variant);
        $first = $this->paystackSays($order, 'failed');
        $this->viewOrder($order);
        $this->withHeaders(self::STOREFRONT)->postJson("/api/v1/orders/{$order->reference}/pay")->assertOk();
        $second = $this->paystackSays($order, 'success');
        $this->webhook($this->chargeSuccess($second, 2))->assertNoContent();

        // The "failed" first attempt turns out to have been paid as well.
        $this->paystackVerify[$first] = ['status' => 'success'] + $this->paystackVerify[$second];
        $this->paystackVerify[$first]['reference'] = $first;
        $this->webhook($this->chargeSuccess($first, 1))->assertNoContent();

        $this->assertSame(3, $variant->fresh()->stock_quantity);
        Mail::assertSent(OrderConfirmed::class, 1);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson("/api/v1/admin/orders/{$order->reference}")->assertJsonPath('data.duplicate_payment', true);
    }

    public function test_the_last_item_cannot_be_sold_twice(): void
    {
        [$product, $variant] = $this->productWithVariant(stock: 1);
        $first = $this->placeOrder($product, $variant, ['items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]], 'expected_total' => 15_300_000]);
        $second = $this->placeOrder($product, $variant, ['items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]], 'expected_total' => 15_300_000]);

        $this->paystackSays($first, 'success');
        $this->paystackSays($second, 'success');
        $this->viewOrder($first);
        $this->viewOrder($second);

        $this->assertSame(0, $variant->fresh()->stock_quantity);
        $this->assertFalse($first->fresh()->stock_issue);
        $this->assertTrue($second->fresh()->stock_issue);
        $this->assertTrue($second->fresh()->isPaid());
    }

    public function test_reconcile_command_confirms_missed_payments(): void
    {
        [$product, $variant] = $this->productWithVariant();
        $order = $this->placeOrder($product, $variant);
        $this->paystackSays($order, 'success');

        $this->artisan('orders:reconcile-payments')->expectsOutputToContain('1 newly confirmed')->assertSuccessful();

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }
}
