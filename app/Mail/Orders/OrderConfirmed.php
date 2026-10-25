<?php

namespace App\Mail\Orders;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent once, after the payment for an order has been verified with Paystack. */
class OrderConfirmed extends Mailable
{
    /** @param  array<string, mixed>  $order  OrderResource data */
    public function __construct(
        public readonly array $order,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Order '.$this->order['reference'].' confirmed — PDL Africa');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.confirmed',
            text: 'emails.orders.text.confirmed',
        );
    }
}
