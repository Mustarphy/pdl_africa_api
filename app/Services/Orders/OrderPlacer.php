<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a pending order from a server-side quote (CartPricer +
 * ShippingCalculator) in one transaction. Nothing price-related comes from
 * the request.
 */
class OrderPlacer
{
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * @param  array<string, mixed>  $customer  validated contact + address fields
     * @param  array{lines: list<array<string, mixed>>, subtotal: int, currency: string}  $cart
     * @param  array{zone: string, label: string, fee: int, pay_on_delivery: bool, estimate: ?string}  $shipping
     */
    public function place(array $customer, array $cart, array $shipping, ?User $user, string $idempotencyKey): Order
    {
        for ($try = 0; ; $try++) {
            try {
                return DB::transaction(fn () => $this->create($customer, $cart, $shipping, $user, $idempotencyKey));
            } catch (UniqueConstraintViolationException $e) {
                // The same checkout submitted twice at once: return the first.
                if ($existing = Order::where('idempotency_key', $idempotencyKey)->first()) {
                    return $existing;
                }
                // Reference collision (1 in ~800 billion): retry with a new one.
                if ($try >= 2) {
                    throw $e;
                }
            }
        }
    }

    private function create(array $customer, array $cart, array $shipping, ?User $user, string $idempotencyKey): Order
    {
        $order = new Order;
        $order->forceFill([
            'reference' => $this->newReference(),
            'user_id' => $user?->isCustomer() ? $user->id : null,
            'idempotency_key' => $idempotencyKey,
            'customer_name' => $customer['name'],
            'customer_email' => $customer['email'],
            'customer_phone' => $customer['phone'],
            'address_line1' => $customer['address_line1'],
            'address_line2' => $customer['address_line2'] ?? null,
            'city' => $customer['city'],
            'state' => $customer['state'],
            'postal_code' => $customer['postal_code'] ?? null,
            'country_code' => $customer['country_code'],
            'delivery_notes' => $customer['delivery_notes'] ?? null,
            'shipping_zone' => $shipping['zone'],
            'shipping_label' => $shipping['label'],
            'delivery_estimate' => $shipping['estimate'],
            'currency' => $cart['currency'],
            'subtotal' => $cart['subtotal'],
            'shipping_fee' => $shipping['fee'],
            'shipping_on_delivery' => $shipping['pay_on_delivery'],
            'discount' => 0,
            'amount' => $cart['subtotal'] + $shipping['fee'],
            'status' => OrderStatus::PendingPayment,
        ])->save();

        foreach ($cart['lines'] as $line) {
            $item = new OrderItem;
            $item->forceFill([
                'order_id' => $order->id,
                'product_id' => $line['product_id'],
                'product_variant_id' => $line['variant_id'],
                'product_slug' => $line['slug'],
                'name' => $line['name'],
                'sku' => $line['sku'],
                'size' => $line['size'],
                'color' => $line['color'],
                'image_url' => $line['image_url'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
                'currency' => $cart['currency'],
            ])->save();
        }

        $history = new OrderStatusHistory;
        $history->forceFill([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => OrderStatus::PendingPayment,
            'actor_type' => 'customer',
            'actor_id' => $user?->isCustomer() ? $user->id : null,
        ])->save();

        return $order;
    }

    /** e.g. PDL-7K3M9Q2X: readable, no 0/O or 1/I/L confusion, not sequential. */
    private function newReference(): string
    {
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
        }

        return 'PDL-'.$chars;
    }
}
