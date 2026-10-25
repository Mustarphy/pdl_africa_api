<?php

namespace Tests\Feature\Orders;

use App\Mail\Orders\OrderAccessLink;
use App\Mail\Orders\OrderConfirmed;
use App\Mail\Orders\OrderStatusUpdated;
use Tests\TestCase;

class OrderEmailTemplatesTest extends TestCase
{
    private function order(array $overrides = []): array
    {
        return array_replace([
            'reference' => 'PDL-7K3M9Q2X',
            'status' => 'confirmed',
            'status_label' => 'Confirmed',
            'payment_status' => 'paid',
            'currency' => 'NGN',
            'subtotal' => 30_000_000,
            'shipping_fee' => 300_000,
            'discount' => 0,
            'total' => 30_300_000,
            'items' => [[
                'name' => 'Royal Agbada', 'sku' => 'AG-1', 'size' => 'L', 'color' => 'Indigo', 'image_url' => null,
                'quantity' => 2, 'unit_price' => 15_000_000, 'line_total' => 30_000_000, 'product_slug' => 'royal-agbada',
            ]],
            'delivery' => [
                'name' => 'Amara Okafor', 'line1' => '12 Admiralty Way', 'line2' => null, 'city' => 'Lekki', 'state' => 'Lagos',
                'postal_code' => null, 'country_code' => 'NG', 'country_name' => 'Nigeria', 'notes' => null,
            ],
            'shipping_label' => 'Lagos delivery',
            'delivery_estimate' => '2–3 working days',
            'tracking' => ['carrier' => 'GIG Logistics', 'number' => 'GIG123', 'url' => 'https://giglogistics.com/track/GIG123'],
        ], $overrides);
    }

    public function test_confirmation_email_lists_the_order(): void
    {
        $mail = new OrderConfirmed($this->order(), 'https://shop.test/orders/PDL-7K3M9Q2X?expires=1&signature=a');

        $mail->assertHasSubject('Order PDL-7K3M9Q2X confirmed — PDL Africa');
        foreach (['Hello Amara Okafor', 'Royal Agbada', 'Size L · Indigo', '₦150,000.00', '₦3,000.00', '₦303,000.00', '12 Admiralty Way', 'Nigeria', '2–3 working days'] as $text) {
            $mail->assertSeeInHtml($text, false);
        }
        $mail->assertSeeInText('Total: ₦303,000.00', false);
        $mail->assertSeeInText('https://shop.test/orders/PDL-7K3M9Q2X?expires=1&signature=a', false);
    }

    public function test_dispatch_email_includes_courier_details(): void
    {
        $mail = new OrderStatusUpdated($this->order(['status' => 'dispatched', 'status_label' => 'Dispatched']), 'https://shop.test/o');

        $mail->assertHasSubject('Order PDL-7K3M9Q2X: Dispatched');
        $mail->assertSeeInHtml('Your order is on its way');
        $mail->assertSeeInHtml('GIG123');
        $mail->assertSeeInText('Track with the courier: https://giglogistics.com/track/GIG123', false);
    }

    public function test_cancellation_and_access_link_emails_render(): void
    {
        (new OrderStatusUpdated($this->order(['status' => 'cancelled', 'status_label' => 'Cancelled']), 'https://shop.test/o'))
            ->assertSeeInHtml('Your order has been cancelled')
            ->assertSeeInHtml('contact you about your refund');

        (new OrderAccessLink($this->order(), 'https://shop.test/orders/PDL-7K3M9Q2X?expires=1&signature=a'))
            ->assertHasSubject('Your PDL Africa order PDL-7K3M9Q2X')
            ->assertSeeInHtml('This link works for 24 hours')
            ->assertSeeInText('https://shop.test/orders/PDL-7K3M9Q2X?expires=1&signature=a', false);
    }
}
