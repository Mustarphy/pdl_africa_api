@extends('emails.account.layout')
@section('title', 'View your order')
@section('preheader', 'Your secure link to order '.$order['reference'].'.')
@section('footer_note', 'You are receiving this email because someone asked to track this order on the PDL Africa website.')
@section('content')
  <h1 style="margin:0 0 16px;font-size:22px;font-weight:600;">Track your order</h1>
  <p style="margin:0 0 16px;">Hello {{ $order['delivery']['name'] ?? '' }},</p>
  <p style="margin:0 0 16px;">Use the button below to view order <strong>{{ $order['reference'] }}</strong>, its progress and any tracking details.</p>
  @include('emails.account.button', ['url' => $url, 'label' => 'View order '.$order['reference']])
  <p style="margin:0 0 16px;font-size:13px;color:#5f5f63;">This link works for {{ $expiresInHours }} hours. If you did not ask to track this order, you can ignore this email.</p>
@endsection
