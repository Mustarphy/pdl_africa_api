@extends('emails.account.layout')
@section('title', 'Your password was changed')
@section('preheader', 'The password for your PDL Africa account was just changed.')
@section('content')
  <h1 style="margin:0 0 16px;font-size:22px;font-weight:600;">Your password was changed</h1>
  <p style="margin:0 0 16px;">Hello {{ $name }},</p>
  <p style="margin:0 0 16px;">The password for your PDL Africa account was {{ $viaReset ? 'reset using a password reset link' : 'changed from your account settings' }}. For your security, your other devices have been signed out.</p>
  <div style="margin:24px 0;background:#f6f6f6;padding:16px 20px;border-left:3px solid #bc934b;font-size:14px;">
    If this was not you, reset your password now and contact us at info@pdlafrica.com.
  </div>
  @include('emails.account.button', ['url' => $resetUrl, 'label' => 'Reset password'])
@endsection
