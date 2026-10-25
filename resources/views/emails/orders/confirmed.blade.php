@extends('emails.account.layout')
@section('title', 'Order confirmed')
@section('preheader', 'We have received your payment for order '.$order['reference'].'.')
@section('footer_note', 'You are receiving this email because you placed an order with PDL Africa.')
@section('content')
  <h1 style="margin:0 0 16px;font-size:22px;font-weight:600;">Thank you for your order</h1>
  <p style="margin:0 0 16px;">Hello {{ $order['delivery']['name'] ?? '' }},</p>
  <p style="margin:0 0 8px;">We have received your payment and your order is confirmed. We will email you again when it is dispatched.</p>
  <p style="margin:0 0 16px;font-size:14px;color:#5f5f63;">Order <strong style="color:#0b0b0d;">{{ $order['reference'] }}</strong>@if ($order['delivery_estimate']) · Estimated delivery: {{ $order['delivery_estimate'] }}@endif</p>
  @include('emails.orders.summary')
  @include('emails.account.button', ['url' => $url, 'label' => 'View your order'])
  <p style="margin:0 0 16px;font-size:13px;color:#5f5f63;">Questions about your order? Reply to this email or contact info@pdlafrica.com with your order reference.</p>
@endsection
