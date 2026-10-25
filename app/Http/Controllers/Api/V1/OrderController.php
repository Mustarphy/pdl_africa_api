<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Account\AccountLinks;
use App\Services\Orders\OrderAccess;
use App\Services\Orders\OrderMailer;
use App\Services\Orders\PaymentProcessor;
use App\Services\Payments\PaystackUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Viewing and paying for an order. Every action first checks OrderAccess
 * (owner, or a session granted by checkout or a signed email link); an
 * order the caller may not see answers 404, exactly like a missing one.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderAccess $access,
        private readonly PaymentProcessor $payments,
        private readonly OrderMailer $mailer,
    ) {}

    public function show(Request $request, string $reference): OrderResource
    {
        $order = $this->authorizedOrder($request, $reference);
        // Returning from Paystack, or a payment still settling: ask Paystack.
        if ($order->canBePaid()) {
            $order = $this->payments->reconcile($order);
        }

        return $this->present($order);
    }

    /** Pay (or retry paying) for an unpaid order: returns Paystack's URL. */
    public function pay(Request $request, string $reference): JsonResponse
    {
        $order = $this->authorizedOrder($request, $reference);
        $order = $this->payments->reconcile($order, force: true);
        if (! $order->canBePaid()) {
            return response()->json([
                'message' => $order->isPaid() ? 'This order has already been paid.' : 'This order can no longer be paid.',
                'data' => $this->present($order),
            ], 409);
        }

        try {
            $url = Cache::lock('order-payment:'.$order->id, 30)->block(15, fn () => $this->payments->checkoutUrl($order));
        } catch (PaystackUnavailable $e) {
            report($e);

            return response()->json(['message' => 'We could not connect to Paystack. Please try again in a moment.'], 503);
        }

        return response()->json(['data' => ['authorization_url' => $url]]);
    }

    /** Opened from a signed link in an order email. */
    public function access(Request $request, string $reference): JsonResponse
    {
        $check = $this->access->checkLink($request);
        $order = Order::where('reference', $reference)->first();
        if ($check === AccountLinks::INVALID || ! $order) {
            return response()->json(['message' => 'This order link is not valid.', 'reason' => 'invalid'], 403);
        }
        if ($check === AccountLinks::EXPIRED) {
            return response()->json(['message' => 'This order link has expired.', 'reason' => 'expired'], 410);
        }

        $this->access->grant($request, $order);

        return $this->present($order)->response();
    }

    /**
     * Track Order for guests: emails a secure link to the address on the
     * order. The answer is the same whether or not anything matched.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:40'],
            'email' => ['required', 'string', 'email', 'max:150'],
        ]);

        $order = Order::where('reference', strtoupper(trim($data['reference'])))->first();
        if ($order && hash_equals(mb_strtolower($order->customer_email), mb_strtolower(trim($data['email'])))) {
            $this->mailer->accessLink($order);
        }

        return response()->json([
            'message' => 'If the details match an order, we have emailed a secure link to view it. Please check your inbox.',
        ]);
    }

    private function authorizedOrder(Request $request, string $reference): Order
    {
        $order = Order::where('reference', $reference)->first();
        abort_unless($order && $this->access->allows($request, $order), 404, 'Order not found.');

        return $order;
    }

    private function present(Order $order): OrderResource
    {
        return new OrderResource($order->load(['lines', 'statusHistory']));
    }
}
