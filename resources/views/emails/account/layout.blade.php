@php($site = config('account.frontend_url'))
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title>@yield('title')</title>
</head>
<body style="margin:0;padding:0;background:#f6f6f6;font-family:Montserrat,Helvetica,Arial,sans-serif;color:#0b0b0d;-webkit-text-size-adjust:100%;">
  {{-- Inbox preview line --}}
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">@yield('preheader')</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e6e6e6;max-width:600px;width:100%;">

          <tr>
            <td align="center" style="background:#0b0b0d;padding:28px 32px 24px;">
              <img src="{{ $site }}/apple-touch-icon.png" width="44" height="44" alt="" style="display:block;border:0;margin:0 auto 12px;">
              <p style="margin:0;color:#ffffff;font-size:18px;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;">
                PDL <span style="color:#bc934b;">|</span> Africa
              </p>
            </td>
          </tr>
          <tr>
            <td style="height:2px;line-height:2px;font-size:0;background:#bc934b;">&nbsp;</td>
          </tr>

          <tr>
            <td style="padding:36px 32px 32px;font-size:15px;line-height:1.7;">
              @yield('content')
            </td>
          </tr>

          <tr>
            <td style="background:#f6f6f6;padding:20px 32px;border-top:1px solid #e6e6e6;font-size:12px;line-height:1.6;color:#5f5f63;">
              <p style="margin:0 0 4px;">@yield('footer_note', 'You are receiving this email because of activity on your PDL Africa account.')</p>
              <p style="margin:0;">&copy; {{ date('Y') }} PDL Africa &middot; <a href="{{ $site }}" style="color:#5f5f63;">{{ preg_replace('#^https?://#', '', $site) }}</a></p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
