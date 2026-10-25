<?php

namespace App\Services\Orders;

use App\Http\Resources\Orders\OrderResource;
use App\Mail\Orders\OrderAccessLink;
use App\Mail\Orders\OrderConfirmed;
use App\Mail\Orders\OrderStatusUpdated;
use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Order emails, sent synchronously (no queue worker in production). A
 * delivery failure is logged and never undoes the order change that caused
 * it. The confirmation is claimed atomically so it goes out once.
 */
class OrderMailer
{
    /** Links in order emails stay valid this long. */
    private const ORDER_LINK_MINUTES = 60 * 24 * 30;

    private const LOOKUP_LINK_HOURS = 24;

    public function __construct(private readonly OrderAccess $access) {}

    /** Sends the confirmation at most once per order (safe to call repeatedly). */
    public function confirmation(Order $order): void
    {
        $claimed = Order::whereKey($order->id)->whereNull('confirmation_email_sent_at')
            ->update(['confirmation_email_sent_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $sent = $this->send($order, new OrderConfirmed(
            $this->present($order),
            $this->access->link($order, self::ORDER_LINK_MINUTES),
        ));
        if (! $sent) {
            // Release the claim so a later verification can try again.
            Order::whereKey($order->id)->update(['confirmation_email_sent_at' => null]);
        }
    }

    public function statusChanged(Order $order): void
    {
        $this->send($order, new OrderStatusUpdated(
            $this->present($order),
            $this->access->link($order, self::ORDER_LINK_MINUTES),
        ));
    }

    public function accessLink(Order $order): void
    {
        $this->send($order, new OrderAccessLink(
            $this->present($order),
            $this->access->link($order, self::LOOKUP_LINK_HOURS * 60),
            self::LOOKUP_LINK_HOURS,
        ));
    }

    private function present(Order $order): array
    {
        $order->loadMissing(['lines', 'statusHistory']);

        return (new OrderResource($order))->resolve();
    }

    private function send(Order $order, Mailable $mail): bool
    {
        try {
            Mail::to($order->customer_email, $order->customer_name)->send($mail);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
