<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** The signed-in customer's own orders (newest first). */
class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->withSum('lines as lines_quantity', 'quantity')
            ->with(['lines' => fn ($query) => $query->limit(1)])
            ->latest('id')
            ->paginate(10);

        return JsonResource::collection($orders->through(fn (Order $order) => [
            'reference' => $order->reference,
            'created_at' => $order->created_at?->toIso8601String(),
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'payment_status' => $order->payment_status->value,
            'can_pay' => $order->canBePaid(),
            'total' => $order->amount,
            'currency' => $order->currency,
            'items_count' => (int) $order->lines_quantity,
            'first_item' => $order->lines->first()?->only(['name', 'image_url']),
        ]));
    }
}
