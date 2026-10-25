<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administrator changes to an order's fulfilment. Transitions follow
 * OrderStatus::allowedTransitions() (enforced here, not by the UI), each is
 * recorded with who made it, and a repeated request is rejected rather than
 * recorded (or emailed) twice.
 */
class OrderFulfillment
{
    /** Customers are emailed when their order reaches these statuses. */
    private const NOTIFY = [OrderStatus::Dispatched, OrderStatus::Delivered, OrderStatus::Cancelled];

    public function __construct(private readonly OrderMailer $mailer) {}

    /** @param  array{carrier?: ?string, tracking_number?: ?string, tracking_url?: ?string}  $tracking */
    public function transition(Order $order, OrderStatus $to, User $admin, ?string $note = null, array $tracking = []): Order
    {
        $order = DB::transaction(function () use ($order, $to, $admin, $note, $tracking) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $order->status;

            if ($from === $to) {
                throw ValidationException::withMessages(['status' => "The order is already {$to->label()}."]);
            }
            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "An order that is {$from->label()} cannot be changed to {$to->label()}.",
                ]);
            }

            $changes = ['status' => $to];
            match ($to) {
                OrderStatus::Dispatched => $changes['dispatched_at'] = now(),
                OrderStatus::Delivered => $changes['delivered_at'] = now(),
                OrderStatus::Cancelled => $changes['cancelled_at'] = now(),
                default => null,
            };
            foreach (['carrier', 'tracking_number', 'tracking_url'] as $field) {
                if (array_key_exists($field, $tracking)) {
                    $changes[$field] = $tracking[$field];
                }
            }
            $order->forceFill($changes)->save();

            $history = new OrderStatusHistory;
            $history->forceFill([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => 'admin',
                'actor_id' => $admin->id,
                'note' => $note,
            ])->save();

            return $order;
        });

        if (in_array($to, self::NOTIFY, true)) {
            $this->mailer->statusChanged($order);
        }

        return $order;
    }

    /** @param  array{carrier: ?string, tracking_number: ?string, tracking_url: ?string}  $tracking */
    public function updateTracking(Order $order, array $tracking): Order
    {
        $order->forceFill($tracking)->save();

        return $order;
    }

    public function updateNotes(Order $order, ?string $notes): Order
    {
        $order->forceFill(['fulfillment_notes' => $notes])->save();

        return $order;
    }
}
