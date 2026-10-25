<?php

namespace App\Http\Resources\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as its customer sees it: no internal ids, notes, staff names or
 * payment-provider details.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'created_at' => $this->created_at?->toIso8601String(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status->value,
            'can_pay' => $this->canBePaid(),
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'shipping_fee' => $this->shipping_fee,
            // Delivery is paid to the rider/courier, not online.
            'shipping_on_delivery' => (bool) $this->shipping_on_delivery,
            'discount' => $this->discount,
            'total' => $this->amount,
            'items' => self::items($this->resource),
            'contact' => ['email' => $this->customer_email, 'phone' => $this->customer_phone],
            'delivery' => self::delivery($this->resource),
            'shipping_label' => $this->shipping_label,
            'delivery_estimate' => $this->delivery_estimate,
            'tracking' => [
                'carrier' => $this->carrier,
                'number' => $this->tracking_number,
                'url' => $this->tracking_url,
            ],
            'timeline' => $this->statusHistory
                ->map(fn (OrderStatusHistory $entry) => [
                    'status' => $entry->to_status->value,
                    'label' => $entry->to_status->label(),
                    'at' => $entry->created_at?->toIso8601String(),
                ])->values(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }

    /** Line snapshots; first-generation orders kept their items as JSON. */
    public static function items(Order $order): array
    {
        if ($order->lines->isEmpty() && is_array($order->items)) {
            return array_map(fn (array $item) => [
                'name' => (string) ($item['name'] ?? ''),
                'sku' => null,
                'size' => $item['size'] ?? null,
                'color' => $item['color'] ?? null,
                'image_url' => null,
                'quantity' => (int) ($item['quantity'] ?? 0),
                'unit_price' => (int) ($item['unit_price'] ?? 0),
                'line_total' => (int) ($item['unit_price'] ?? 0) * (int) ($item['quantity'] ?? 0),
                'product_slug' => $item['slug'] ?? null,
            ], $order->items);
        }

        return $order->lines->map(fn (OrderItem $line) => [
            'name' => $line->name,
            'sku' => $line->sku,
            'size' => $line->size,
            'color' => $line->color,
            'image_url' => $line->image_url,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'line_total' => $line->line_total,
            'product_slug' => $line->product_slug,
        ])->all();
    }

    public static function delivery(Order $order): ?array
    {
        if (! $order->address_line1 && ! $order->shipping_address) {
            return null;
        }

        return [
            'name' => $order->customer_name,
            'line1' => $order->address_line1 ?? $order->shipping_address,
            'line2' => $order->address_line2,
            'city' => $order->city,
            'state' => $order->state,
            'postal_code' => $order->postal_code,
            'country_code' => $order->country_code,
            'country_name' => self::countryName($order->country_code),
            'notes' => $order->delivery_notes,
        ];
    }

    public static function countryName(?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-'.$code, 'en');
            if ($name && $name !== $code) {
                return $name;
            }
        }

        return $code === 'NG' ? 'Nigeria' : $code;
    }
}
