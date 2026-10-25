PDL AFRICA

Hello {{ $order['delivery']['name'] ?? '' }},

Thank you for your order. We have received your payment and order {{ $order['reference'] }} is confirmed. We will email you again when it is dispatched.

@include('emails.orders.text.summary')

View your order:
{!! $url !!}

Questions? Contact info@pdlafrica.com with your order reference.
