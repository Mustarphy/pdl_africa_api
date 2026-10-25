PDL AFRICA

Hello {{ $order['delivery']['name'] ?? '' }},

@if ($order['status'] === 'dispatched')
Order {{ $order['reference'] }} has been dispatched.
@if ($order['tracking']['carrier'])Courier: {{ $order['tracking']['carrier'] }}
@endif
@if ($order['tracking']['number'])Tracking number: {{ $order['tracking']['number'] }}
@endif
@if ($order['tracking']['url'])Track with the courier: {!! $order['tracking']['url'] !!}
@endif
@elseif ($order['status'] === 'delivered')
Order {{ $order['reference'] }} has been marked as delivered.
@else
Order {{ $order['reference'] }} has been cancelled.@if ($order['payment_status'] === 'paid') If you have paid, our team will contact you about your refund.@endif

@endif

@include('emails.orders.text.summary')

View your order:
{!! $url !!}
