<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Contact Form Enquiry</title>
</head>
<body style="margin:0;padding:0;background:#f6f6f6;font-family:sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6;padding:40px 0;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e6e6e6;max-width:600px;width:100%;">

          <!-- Header -->
          <tr>
            <td style="background:#0b0b0d;padding:24px 32px;">
              <p style="margin:0;color:#ffffff;font-size:18px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;">
                PDL Africa
              </p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:32px;">
              <p style="margin:0 0 24px;font-size:20px;font-weight:600;color:#0b0b0d;">
                New Contact Form Message
              </p>

              <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                <tr>
                  <td style="padding:10px 0;border-bottom:1px solid #e6e6e6;width:100px;font-size:13px;color:#5f5f63;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;">From</td>
                  <td style="padding:10px 0;border-bottom:1px solid #e6e6e6;font-size:14px;color:#0b0b0d;">
                    {{ $senderName }} &lt;{{ $senderEmail }}&gt;
                  </td>
                </tr>
                <tr>
                  <td style="padding:10px 0;border-bottom:1px solid #e6e6e6;font-size:13px;color:#5f5f63;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;">Subject</td>
                  <td style="padding:10px 0;border-bottom:1px solid #e6e6e6;font-size:14px;color:#0b0b0d;">
                    {{ $subject }}
                  </td>
                </tr>
              </table>

              <div style="margin-top:24px;background:#f6f6f6;padding:20px;border-left:3px solid #bc934b;">
                <p style="margin:0;font-size:14px;color:#0b0b0d;line-height:1.7;white-space:pre-wrap;">{{ $body }}</p>
              </div>

              <p style="margin:24px 0 0;font-size:13px;color:#5f5f63;">
                Reply directly to this email to respond to {{ $senderName }}.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f6f6f6;padding:16px 32px;border-top:1px solid #e6e6e6;">
              <p style="margin:0;font-size:11px;color:#5f5f63;">
                &copy; {{ date('Y') }} PDL Africa &mdash; This message was sent from the website contact form.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
