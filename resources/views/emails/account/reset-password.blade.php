@extends('emails.account.layout')
@section('title', 'Reset your password')
@section('preheader', 'Use this link to choose a new password for your PDL Africa account.')
@section('content')
  <h1 style="margin:0 0 16px;font-size:22px;font-weight:600;">Reset your password</h1>
  <p style="margin:0 0 16px;">Hello {{ $name }},</p>
  <p style="margin:0 0 16px;">We received a request to reset the password for your PDL Africa account. Choose a new password using the button below.</p>
  @include('emails.account.button', ['url' => $url, 'label' => 'Choose a new password'])
  <p style="margin:0 0 16px;font-size:13px;color:#5f5f63;">This link can be used once and expires in {{ $expiresInMinutes }} minutes. If you did not ask for a reset, you can ignore this email and your password will stay the same.</p>
@endsection
