<?php

namespace App\Mail\Orders;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Secure link to view an order, requested from the Track Order page. */
class OrderAccessLink extends Mailable
{
    /** @param  array<string, mixed>  $order  OrderResource data */
    public function __construct(
        public readonly array $order,
        public readonly string $url,
        public readonly int $expiresInHours = 24,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your PDL Africa order '.$this->order['reference']);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.access-link',
            text: 'emails.orders.text.access-link',
        );
    }
}
