@extends('emails.account.layout')
@section('title', $order['status_label'])
@section('preheader', 'Update on your PDL Africa order '.$order['reference'].'.')
@section('footer_note', 'You are receiving this email because you placed an order with PDL Africa.')
@section('content')
  @php($status = $order['status'])
  <h1 style="margin:0 0 16px;font-size:22px;font-weight:600;">
    @if ($status === 'dispatched') Your order is on its way
    @elseif ($status === 'delivered') Your order has been delivered
    @else Your order has been cancelled
    @endif
  </h1>
  <p style="margin:0 0 16px;">Hello {{ $order['delivery']['name'] ?? '' }},</p>
  @if ($status === 'dispatched')
    <p style="margin:0 0 16px;">Good news: order <strong>{{ $order['reference'] }}</strong> has been dispatched.</p>
    @if ($order['tracking']['carrier'] || $order['tracking']['number'])
      <div style="margin:0 0 16px;background:#f6f6f6;padding:16px 20px;border-left:3px solid #bc934b;font-size:14px;">
        @if ($order['tracking']['carrier'])Courier: <strong>{{ $order['tracking']['carrier'] }}</strong><br>@endif
        @if ($order['tracking']['number'])Tracking number: <strong>{{ $order['tracking']['number'] }}</strong>@endif
        @if ($order['tracking']['url'])<br><a href="{{ $order['tracking']['url'] }}" style="color:#8a6a33;">Track with the courier</a>@endif
      </div>
    @endif
  @elseif ($status === 'delivered')
    <p style="margin:0 0 16px;">Order <strong>{{ $order['reference'] }}</strong> has been marked as delivered. We hope you enjoy wearing your PDL Africa pieces.</p>
  @else
    <p style="margin:0 0 16px;">Order <strong>{{ $order['reference'] }}</strong> has been cancelled.@if ($order['payment_status'] === 'paid') If you have paid, our team will contact you about your refund.@endif</p>
  @endif
  @include('emails.orders.summary')
  @include('emails.account.button', ['url' => $url, 'label' => 'View your order'])
@endsection
