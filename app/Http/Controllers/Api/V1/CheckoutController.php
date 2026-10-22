<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(private PaystackService $paystack) {}

    /**
     * Create an order and initialize a Paystack transaction.
     *
     * Expected JSON body:
     * {
     *   "customer_name": "...",
     *   "customer_email": "...",
     *   "customer_phone": "...",       // optional
     *   "shipping_address": "...",     // optional
     *   "items": [
     *     { "slug": "...", "name": "...", "size": "...", "color": "...",
     *       "quantity": 1, "unit_price": 50000, "currency": "NGN" }
     *   ],
     *   "callback_url": "http://localhost:5173/checkout/callback"
     * }
     */
    public function initialize(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name'          => ['required', 'string', 'max:150'],
            'customer_email'         => ['required', 'email', 'max:150'],
            'customer_phone'         => ['nullable', 'string', 'max:30'],
            'shipping_address'       => ['nullable', 'string', 'max:500'],
            'callback_url'           => ['required', 'url'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.name'           => ['required', 'string'],
            'items.*.slug'           => ['required', 'string'],
            'items.*.quantity'       => ['required', 'integer', 'min:1'],
            'items.*.unit_price'     => ['required', 'integer', 'min:0'],
            'items.*.currency'       => ['required', 'string', 'size:3'],
            'items.*.size'           => ['nullable', 'string'],
            'items.*.color'          => ['nullable', 'string'],
        ]);

        // Derive totals from line items (all items must share one currency).
        $currency = $validated['items'][0]['currency'];
        $total    = array_reduce(
            $validated['items'],
            fn (int $carry, array $item) => $carry + ($item['unit_price'] * $item['quantity']),
            0,
        );

        $reference = 'PDL-' . strtoupper(Str::random(12));

        $order = Order::create([
            'reference'       => $reference,
            'customer_name'   => $validated['customer_name'],
            'customer_email'  => $validated['customer_email'],
            'customer_phone'  => $validated['customer_phone'] ?? null,
            'shipping_address'=> $validated['shipping_address'] ?? null,
            'amount'          => $total,
            'currency'        => $currency,
            'status'          => 'pending',
            'items'           => $validated['items'],
        ]);

        try {
            $paystackData = $this->paystack->initialize([
                'email'        => $order->customer_email,
                'amount'       => $order->amount,
                'currency'     => $order->currency,
                'reference'    => $order->reference,
                'callback_url' => $validated['callback_url'],
                'metadata'     => [
                    'order_id'      => $order->id,
                    'customer_name' => $order->customer_name,
                    'custom_fields' => [
                        ['display_name' => 'Customer Name', 'variable_name' => 'customer_name', 'value' => $order->customer_name],
                        ['display_name' => 'Phone',         'variable_name' => 'phone',         'value' => $order->customer_phone ?? ''],
                    ],
                ],
            ]);
        } catch (RuntimeException $e) {
            $order->delete();
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'authorization_url' => $paystackData['authorization_url'],
            'reference'         => $order->reference,
        ]);
    }

    /**
     * Verify a completed Paystack transaction and mark the order as paid.
     */
    public function verify(string $reference): JsonResponse
    {
        $order = Order::where('reference', $reference)->firstOrFail();

        if ($order->status === 'paid') {
            return response()->json(['status' => 'paid', 'order_id' => $order->id]);
        }

        try {
            $data = $this->paystack->verify($reference);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $paystackStatus = $data['status'] ?? 'failed';

        $order->update([
            'status'        => $paystackStatus === 'success' ? 'paid' : 'failed',
            'paystack_data' => $data,
        ]);

        if ($paystackStatus !== 'success') {
            return response()->json(['message' => 'Payment was not successful.', 'status' => 'failed'], 402);
        }

        return response()->json([
            'status'   => 'paid',
            'order_id' => $order->id,
            'amount'   => $order->amount,
            'currency' => $order->currency,
            'name'     => $order->customer_name,
            'email'    => $order->customer_email,
        ]);
    }
}
