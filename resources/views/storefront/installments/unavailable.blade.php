{{--
  Deposit calculator — UNAVAILABLE state. Rendered when the variant cannot be
  priced server-side (not in our synced catalog cache). A standalone storefront
  iframe page, so it carries its own minimal self-contained CSS (this is NOT the
  admin panel; the no-inline-CSS rule applies to admin/app UI).
--}}
<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}" dir="{{ $dir ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('storefront.installments.unavailable_title') }}</title>
    <style>
        :root { --lets-fg: #1a1a1a; --lets-muted: #6b7280; --lets-bg: #ffffff; }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 24px; background: var(--lets-bg); color: var(--lets-fg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.5;
        }
        .lets-card { max-width: 420px; margin: 0 auto; text-align: center; }
        .lets-title { font-size: 18px; font-weight: 600; margin: 0 0 8px; }
        .lets-body { font-size: 14px; color: var(--lets-muted); margin: 0 0 20px; }
        .lets-close {
            appearance: none; border: 1px solid #d1d5db; background: #f9fafb;
            border-radius: 8px; padding: 10px 18px; font-size: 14px; cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="lets-card">
        <h1 class="lets-title">{{ __('storefront.installments.unavailable_title') }}</h1>
        <p class="lets-body">{{ __('storefront.installments.unavailable_body') }}</p>
        <button type="button" class="lets-close" onclick="window.parent.postMessage({ source: 'lets', type: 'lets:close' }, '*')">
            {{ __('storefront.installments.close') }}
        </button>
    </div>
</body>
</html>
