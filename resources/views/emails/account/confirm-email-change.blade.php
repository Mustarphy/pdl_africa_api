@extends('emails.account.layout')
@section('title', 'Confirm your new email')
@section('preheader', 'Confirm this address to use it for your PDL Africa account.')
@section('content')
  <h1 style="margin:0 0 16px;font-size:22px;font-weight:600;">Confirm your new email</h1>
  <p style="margin:0 0 16px;">Hello {{ $name }},</p>
  <p style="margin:0 0 16px;">You asked to use <strong>{{ $newEmail }}</strong> for your PDL Africa account. Your account keeps its current address until you confirm this one.</p>
  @include('emails.account.button', ['url' => $url, 'label' => 'Confirm new email'])
  <p style="margin:0 0 16px;font-size:13px;color:#5f5f63;">This link expires in {{ $expiresInMinutes }} minutes. If you did not request this change, you can ignore this email.</p>
@endsection
