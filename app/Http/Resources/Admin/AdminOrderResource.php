<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Http\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full order for administrators. Payment attempts show references and the
 * Paystack verdict only (no authorization URLs, card or bank details).
 *
 * @mixin Order
 */
class AdminOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $successfulPayments = $this->paymentAttempts->where('status', PaymentAttemptStatus::Success)->count();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'allowed_transitions' => array_map(fn (OrderStatus $s) => $s->value, $this->status->allowedTransitions()),
            'payment_status' => $this->payment_status->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'shipping_fee' => $this->shipping_fee,
            // Delivery is paid to the rider/courier, not online.
            'shipping_on_delivery' => (bool) $this->shipping_on_delivery,
            'discount' => $this->discount,
            'total' => $this->amount,
            'customer' => [
                'name' => $this->customer_name,
                'email' => $this->customer_email,
                'phone' => $this->customer_phone,
                'has_account' => $this->user_id !== null,
            ],
            'delivery' => OrderResource::delivery($this->resource),
            'shipping_zone' => $this->shipping_zone,
            'shipping_label' => $this->shipping_label,
            'delivery_estimate' => $this->delivery_estimate,
            'items' => OrderResource::items($this->resource),
            'tracking' => [
                'carrier' => $this->carrier,
                'number' => $this->tracking_number,
                'url' => $this->tracking_url,
            ],
            'fulfillment_notes' => $this->fulfillment_notes,
            'stock_issue' => $this->stock_issue,
            // More than one verified payment: the customer was charged twice.
            'duplicate_payment' => $successfulPayments > 1,
            'payments' => $this->paymentAttempts->map(fn (PaymentAttempt $attempt) => [
                'reference' => $attempt->reference,
                'amount' => $attempt->amount,
                'currency' => $attempt->currency,
                'status' => $attempt->status->value,
                'provider_status' => $attempt->provider_status,
                'gateway_response' => $attempt->gateway_response,
                'channel' => $attempt->channel,
                'paid_at' => $attempt->paid_at?->toIso8601String(),
                'verified_at' => $attempt->verified_at?->toIso8601String(),
                'created_at' => $attempt->created_at?->toIso8601String(),
            ])->values(),
            'history' => $this->statusHistory->map(fn (OrderStatusHistory $entry) => [
                'from' => $entry->from_status?->value,
                'to' => $entry->to_status->value,
                'to_label' => $entry->to_status->label(),
                'actor_type' => $entry->actor_type,
                'actor' => $entry->actor?->name,
                'note' => $entry->note,
                'at' => $entry->created_at?->toIso8601String(),
            ])->values(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
