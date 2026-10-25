<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutQuoteRequest;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Services\Orders\CartPricer;
use App\Services\Orders\OrderAccess;
use App\Services\Orders\OrderPlacer;
use App\Services\Orders\PaymentProcessor;
use App\Services\Orders\ShippingCalculator;
use App\Services\Payments\PaystackRejected;
use App\Services\Payments\PaystackUnavailable;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Checkout: a server-side quote for the cart, then a pending order and a
 * Paystack transaction for exactly that quote. The browser only ever sends
 * product ids, variant ids and quantities.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartPricer $pricer,
        private readonly ShippingCalculator $shipping,
        private readonly OrderPlacer $placer,
        private readonly OrderAccess $access,
        private readonly PaymentProcessor $payments,
        private readonly PaystackService $paystack,
    ) {}

    public function quote(CheckoutQuoteRequest $request): JsonResponse
    {
        $cart = $this->pricer->price($request->cartItems());
        $shipping = $this->shipping->quote($request->validated('country_code'), $request->validated('state'));

        return response()->json(['data' => $this->present($cart, $shipping)]);
    }

    public function store(CheckoutRequest $request): JsonResponse
    {
        // Prices are stored in catalog.currency; never charge them in another
        // currency (no conversion is done).
        if (! $this->paystack->isConfigured()
            || strtoupper((string) config('services.paystack.currency')) !== config('catalog.currency')) {
            return response()->json(['message' => 'Online payment is not available right now. Please order by WhatsApp or try again later.'], 503);
        }

        $key = $request->validated('idempotency_key');
        if ($existing = Order::where('idempotency_key', $key)->first()) {
            return $this->resume($request, $existing);
        }

        $cart = $this->pricer->price($request->cartItems());
        $shipping = $this->shipping->quote($request->validated('country_code'), $request->validated('state'));
        $quote = $this->present($cart, $shipping);
        if ($quote['issues'] !== []) {
            return response()->json([
                'message' => 'Some items in your bag have changed. Please review them before paying.',
                'code' => 'cart_changed',
                'quote' => $quote,
            ], 422);
        }
        if ($quote['shipping'] === null) {
            return response()->json([
                'message' => 'We cannot take online orders for delivery to this address yet. Please contact us to arrange delivery.',
                'errors' => ['country_code' => ['Online delivery is not available for this location yet.']],
                'quote' => $quote,
            ], 422);
        }
        if ($quote['total'] !== (int) $request->validated('expected_total')) {
            return response()->json([
                'message' => 'Prices or delivery have changed since you started checkout. Please check the new total.',
                'code' => 'price_changed',
                'quote' => $quote,
            ], 409);
        }

        $order = $this->placer->place(
            $request->safe()->only(['name', 'email', 'phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code', 'delivery_notes']),
            $cart,
            $shipping,
            $request->user(),
            $key,
        );
        $this->access->grant($request, $order);

        return $this->paymentResponse($order, 201);
    }

    /** A repeated submission (double click, refresh, retry) of one checkout. */
    private function resume(CheckoutRequest $request, Order $order): JsonResponse
    {
        if (! $this->access->allows($request, $order)) {
            return response()->json(['message' => 'This checkout has already been used. Please refresh the page.'], 409);
        }
        if (! $order->canBePaid()) {
            return response()->json(['data' => ['reference' => $order->reference, 'authorization_url' => null, 'paid' => $order->isPaid()]]);
        }

        return $this->paymentResponse($order, 200);
    }

    private function paymentResponse(Order $order, int $status): JsonResponse
    {
        try {
            // One payment start per order at a time (concurrent submissions).
            $url = Cache::lock('order-payment:'.$order->id, 30)->block(15, fn () => $this->payments->checkoutUrl($order));
        } catch (PaystackRejected $e) {
            report($e);

            return response()->json([
                'message' => 'Paystack could not accept these payment details. Please check your email address and try again.',
                'code' => 'payment_rejected',
                'data' => ['reference' => $order->reference],
            ], 422);
        } catch (PaystackUnavailable $e) {
            report($e);

            return response()->json([
                'message' => 'We could not connect to Paystack. Your order is saved; please try paying again in a moment.',
                'code' => 'payment_unavailable',
                'data' => ['reference' => $order->reference],
            ], 503);
        }

        return response()->json(['data' => ['reference' => $order->reference, 'authorization_url' => $url, 'paid' => false]], $status);
    }

    /** @return array<string, mixed> */
    private function present(array $cart, ?array $shipping): array
    {
        return [
            'currency' => $cart['currency'],
            'lines' => array_map(fn (array $line) => collect($line)->except('stock')->all(), $cart['lines']),
            'issues' => $cart['issues'],
            'subtotal' => $cart['subtotal'],
            'shipping' => $shipping,
            'shipping_configured' => $this->shipping->isConfigured(),
            'total' => $shipping ? $cart['subtotal'] + $shipping['fee'] : null,
        ];
    }
}
