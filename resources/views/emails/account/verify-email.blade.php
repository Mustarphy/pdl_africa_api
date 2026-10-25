@extends('emails.account.layout')
@section('title', 'Verify your email')
@section('preheader', 'Confirm your email address to finish setting up your PDL Africa account.')
@section('content')
  <h1 style="margin:0 0 16px;font-size:22px;font-weight:600;">{{ $resend ? 'Here is your new verification link' : 'Welcome to PDL Africa' }}</h1>
  <p style="margin:0 0 16px;">Hello {{ $name }},</p>
  <p style="margin:0 0 16px;">{{ $resend ? 'As requested, here is a fresh link to verify your email address.' : 'Thank you for creating an account. Please confirm that this is your email address to finish setting it up.' }}</p>
  @include('emails.account.button', ['url' => $url, 'label' => 'Verify email address'])
  <p style="margin:0 0 16px;font-size:13px;color:#5f5f63;">This link expires in {{ $expiresInMinutes }} minutes. If you did not create a PDL Africa account, you can ignore this email.</p>
@endsection
