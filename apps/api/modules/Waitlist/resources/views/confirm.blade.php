{{-- Waitlist double opt-in email. Tables and inline styles only: email clients ignore most CSS. --}}
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $copy['dir'] }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $copy['subject'] }}</title>
</head>
<body style="margin:0;padding:0;background:#F7F6F2;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $copy['preheader'] }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F6F2;">
  <tr>
    <td align="center" style="padding:32px 16px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:16px;overflow:hidden;border:1px solid #E5E7EB;">
        <tr>
          <td dir="ltr" style="background:#14121F;padding:24px 32px;font-family:Arial,Helvetica,sans-serif;">
            <span style="font-size:22px;font-weight:700;letter-spacing:-1px;color:#3F3C52;">&#10003;</span><span style="font-size:22px;font-weight:700;color:#7D7998;">&#10003;</span><span style="font-size:22px;font-weight:700;color:#B8F25B;">&#10003;</span>
            <span style="font-size:24px;font-weight:700;letter-spacing:-1px;color:#FFFFFF;padding-left:8px;">trik</span>
          </td>
        </tr>
        <tr>
          <td dir="{{ $copy['dir'] }}" style="padding:32px;font-family:Tahoma,'Segoe UI',Arial,Helvetica,sans-serif;color:#14121F;font-size:16px;line-height:1.7;text-align:{{ $copy['dir'] === 'rtl' ? 'right' : 'left' }};">
            <p style="margin:0 0 16px;font-size:18px;font-weight:700;">{{ $copy['greeting'] }}</p>
            <p style="margin:0 0 28px;">{{ $copy['intro'] }}</p>
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
              <tr>
                <td style="border-radius:8px;background:#4F3BF0;">
                  <a href="{{ $confirmUrl }}" style="display:inline-block;padding:14px 28px;font-weight:700;font-size:16px;color:#FFFFFF;text-decoration:none;border-radius:8px;">{{ $copy['button'] }}</a>
                </td>
              </tr>
            </table>
            <p style="margin:0 0 12px;color:#5F6673;">{{ $copy['next'] }}</p>
            <p style="margin:0 0 12px;color:#5F6673;">{{ $copy['expiry'] }} {{ $copy['notYou'] }}</p>
            <p style="margin:24px 0 4px;font-size:13px;color:#5F6673;">{{ $copy['fallback'] }}</p>
            <p dir="ltr" style="margin:0;font-size:12px;word-break:break-all;color:#4F3BF0;text-align:left;">{{ $confirmUrl }}</p>
          </td>
        </tr>
        <tr>
          <td dir="{{ $copy['dir'] }}" style="padding:20px 32px;border-top:1px solid #E5E7EB;font-family:Tahoma,'Segoe UI',Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#5F6673;text-align:{{ $copy['dir'] === 'rtl' ? 'right' : 'left' }};">
            {{ $copy['footer'] }}
            <a href="{{ $unsubscribeUrl }}" style="color:#5F6673;">{{ $copy['unsubscribe'] }}</a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
