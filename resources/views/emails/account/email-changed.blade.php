@extends('emails.account.layout')
@section('title', 'Your email was changed')
@section('preheader', 'The email address on your PDL Africa account was changed.')
@section('content')
  <h1 style="margin:0 0 16px;font-size:22px;font-weight:600;">Your email address was changed</h1>
  <p style="margin:0 0 16px;">Hello {{ $name }},</p>
  <p style="margin:0 0 16px;">Your PDL Africa account now uses <strong>{{ $newEmail }}</strong>. Future account emails will go to that address.</p>
  <div style="margin:24px 0;background:#f6f6f6;padding:16px 20px;border-left:3px solid #bc934b;font-size:14px;">
    If you did not make this change, please contact PDL Africa straight away at info@pdlafrica.com.
  </div>
@endsection
