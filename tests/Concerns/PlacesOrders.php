<?php

namespace Tests\Concerns;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Checkout helpers. Paystack is always faked (Http::fake): no test talks to
 * the real API or charges anything.
 */
trait PlacesOrders
{
    use BuildsCatalog;

    protected const STOREFRONT = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/checkout'];

    protected const PAYSTACK_SECRET = 'sk_test_fake_secret_for_tests';

    /** What the fake Paystack verify endpoint answers, per reference. */
    protected array $paystackVerify = [];

    protected int $paystackInitializeCalls = 0;

    protected bool $paystackDown = false;

    /** Paystack refuses to start transactions (HTTP 400). */
    protected bool $paystackRejects = false;

    protected function setUpCheckout(): void
    {
        config([
            'services.paystack.secret_key' => self::PAYSTACK_SECRET,
            'services.paystack.url' => 'https://api.paystack.co',
            'shipping.zones.lagos.fee' => 300_000,
            'shipping.zones.lagos.estimate' => '2–3 working days',
            'shipping.zones.nigeria.fee' => 500_000,
            'shipping.zones.international.fee' => null,
        ]);

        Http::fake(function (HttpRequest $request) {
            if ($this->paystackDown) {
                return Http::response(['status' => false, 'message' => 'Service unavailable'], 503);
            }
            if (str_ends_with($request->url(), '/transaction/initialize')) {
                $this->paystackInitializeCalls++;
                if ($this->paystackRejects) {
                    return Http::response(['status' => false, 'message' => '"email" must be a valid email'], 400);
                }

                return Http::response(['status' => true, 'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/'.Str::lower(Str::random(10)),
                    'access_code' => Str::random(10),
                    'reference' => $request['reference'],
                ]]);
            }
            if (preg_match('#/transaction/verify/(.+)$#', $request->url(), $m)) {
                $reference = rawurldecode($m[1]);
                $data = $this->paystackVerify[$reference] ?? null;

                return $data
                    ? Http::response(['status' => true, 'data' => $data])
                    : Http::response(['status' => false, 'message' => 'Transaction reference not found'], 400);
            }

            return Http::response([], 404);
        });
    }

    /** A published product with one tracked variant (Lagos-deliverable). */
    protected function productWithVariant(int $price = 15_000_000, ?int $stock = 5, array $variant = []): array
    {
        $product = $this->publishedProduct(['name' => 'Royal Agbada', 'price' => $price], variants: [
            ['sku' => 'AG-'.Str::upper(Str::random(5)), 'size' => 'L', 'color' => 'Indigo', 'price' => null, 'stock_quantity' => $stock] + $variant,
        ]);

        return [$product, $product->variants->first()];
    }

    protected function checkoutPayload(Product $product, ?ProductVariant $variant, array $overrides = []): array
    {
        return array_replace([
            'items' => [['product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => 2]],
            'name' => 'Amara Okafor',
            'email' => 'amara@example.com',
            'phone' => '+234 801 234 5678',
            'address_line1' => '12 Admiralty Way',
            'address_line2' => null,
            'city' => 'Lekki',
            'state' => 'Lagos',
            'postal_code' => null,
            'country_code' => 'NG',
            'delivery_notes' => 'Call on arrival',
            'expected_total' => 2 * ($variant?->price ?? $product->price) + 300_000,
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides);
    }

    protected function checkout(array $payload): TestResponse
    {
        return $this->withHeaders(self::STOREFRONT)->postJson('/api/v1/checkout', $payload);
    }

    /** Places an order through the API and returns it. */
    protected function placeOrder(Product $product, ?ProductVariant $variant, array $overrides = []): Order
    {
        $reference = $this->checkout($this->checkoutPayload($product, $variant, $overrides))
            ->assertSuccessful()->json('data.reference');

        return Order::where('reference', $reference)->firstOrFail();
    }

    /** Makes the fake Paystack report the order's latest attempt. */
    protected function paystackSays(Order $order, string $status, array $overrides = []): string
    {
        $attempt = PaymentAttempt::where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->paystackVerify[$attempt->reference] = array_replace([
            'id' => random_int(1_000_000, 9_999_999),
            'status' => $status,
            'reference' => $attempt->reference,
            'amount' => $attempt->amount,
            'currency' => $attempt->currency,
            'gateway_response' => $status === 'success' ? 'Approved' : 'Declined',
            'channel' => 'card',
            'paid_at' => now()->toIso8601String(),
        ], $overrides);

        return $attempt->reference;
    }

    protected function viewOrder(Order $order): TestResponse
    {
        return $this->withHeaders(self::STOREFRONT)->getJson('/api/v1/orders/'.$order->reference);
    }

    protected function webhook(array $payload, ?string $secret = self::PAYSTACK_SECRET): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/payments/paystack/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $secret ? hash_hmac('sha512', $body, $secret) : 'nope',
        ], content: $body);
    }

    protected function chargeSuccess(string $reference, int $transactionId = 4242): array
    {
        return ['event' => 'charge.success', 'data' => [
            'id' => $transactionId, 'reference' => $reference, 'status' => 'success', 'amount' => 1, 'currency' => 'NGN',
        ]];
    }
}
