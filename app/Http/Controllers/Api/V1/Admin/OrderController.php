<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminOrderListResource;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Models\Order;
use App\Services\Orders\OrderFulfillment;
use App\Services\Orders\PaymentProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Order management for administrators (routes require manage-catalog). */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderFulfillment $fulfillment,
        private readonly PaymentProcessor $payments,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $orders = Order::query()
            ->withSum('lines as lines_quantity', 'quantity')
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                $query->where(fn ($w) => $w->where('reference', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_email', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['payment_status'] ?? null, fn ($query, $status) => $query->where('payment_status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', now()->parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', now()->parse($to)->endOfDay()))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return AdminOrderListResource::collection($orders)->additional(['summary' => $this->summary()]);
    }

    public function show(Order $order): AdminOrderResource
    {
        return $this->present($order);
    }

    public function status(Request $request, Order $order): AdminOrderResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->trackingRules(),
        ]);

        $tracking = array_intersect_key($data, array_flip(['carrier', 'tracking_number', 'tracking_url']));
        $order = $this->fulfillment->transition($order, OrderStatus::from($data['status']), $request->user(), $data['note'] ?? null, $tracking);

        return $this->present($order);
    }

    public function tracking(Request $request, Order $order): AdminOrderResource
    {
        $data = $request->validate($this->trackingRules(required: true));

        return $this->present($this->fulfillment->updateTracking($order, $data));
    }

    public function notes(Request $request, Order $order): AdminOrderResource
    {
        $data = $request->validate(['fulfillment_notes' => ['present', 'nullable', 'string', 'max:5000']]);

        return $this->present($this->fulfillment->updateNotes($order, $data['fulfillment_notes']));
    }

    /** Re-checks unfinished payments with Paystack (e.g. a missed webhook). */
    public function verifyPayment(Order $order): JsonResponse
    {
        $order = $this->payments->reconcile($order, force: true);

        return $this->present($order)->response();
    }

    private function trackingRules(bool $required = false): array
    {
        $presence = $required ? 'present' : 'sometimes';

        return [
            'carrier' => [$presence, 'nullable', 'string', 'max:120'],
            'tracking_number' => [$presence, 'nullable', 'string', 'max:120'],
            'tracking_url' => [$presence, 'nullable', 'string', 'max:500', 'url:https'],
        ];
    }

    /** Counts for the orders overview, from the database. */
    private function summary(): array
    {
        $byStatus = Order::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $count = fn (OrderStatus ...$statuses) => (int) collect($statuses)->sum(fn ($s) => $byStatus[$s->value] ?? 0);

        return [
            'total' => (int) $byStatus->sum(),
            'awaiting_payment' => $count(OrderStatus::PendingPayment),
            'payment_failed' => Order::where('status', OrderStatus::PendingPayment)->where('payment_status', PaymentStatus::Failed)->count(),
            'new' => $count(OrderStatus::Confirmed),
            'in_progress' => $count(OrderStatus::Processing, OrderStatus::ReadyForDispatch),
            'dispatched' => $count(OrderStatus::Dispatched),
            'delivered' => $count(OrderStatus::Delivered),
            'cancelled' => $count(OrderStatus::Cancelled),
            'needs_attention' => Order::where('stock_issue', true)->whereNotIn('status', [OrderStatus::Delivered, OrderStatus::Cancelled])->count(),
        ];
    }

    private function present(Order $order): AdminOrderResource
    {
        return new AdminOrderResource($order->load(['lines', 'paymentAttempts', 'statusHistory.actor']));
    }
}
