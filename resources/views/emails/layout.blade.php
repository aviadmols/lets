{{--
  Email layout shell. INLINE CSS IS THE ALLOWED EXCEPTION here (email clients
  strip <style>) — this is the only place in the app where style="" is permitted
  (CLAUDE.md). RTL-first. Yields the per-template body via @yield('body').

  Trusted platform template (Blade is safe). Merchant-edited HTML never renders
  through this layout — it goes through user-template-wrapper.blade.php, which
  only echoes an already-strtr-substituted string.
--}}
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>{{ $businessName ?? config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
                    <tr>
                        <td style="padding:0 12px;">
                            @yield('body')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 12px;text-align:center;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#9ca3af;">
                            {{ $businessName ?? config('app.name') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
