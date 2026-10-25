<?php

namespace App\Mail\Orders;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Dispatch, delivery or cancellation of an order. */
class OrderStatusUpdated extends Mailable
{
    /** @param  array<string, mixed>  $order  OrderResource data */
    public function __construct(
        public readonly array $order,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Order '.$this->order['reference'].': '.$this->order['status_label']);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.status-updated',
            text: 'emails.orders.text.status-updated',
        );
    }
}
