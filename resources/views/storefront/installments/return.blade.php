{{--
  PayPlus return landing — the standalone page the shopper lands on after the hosted
  deposit page (refURL_success/failure/cancel). A standalone storefront page, so it
  carries its own minimal self-contained CSS (the no-inline-CSS rule applies to the
  admin/app UI, not these storefront pages). $state is one of success|failure|cancel.
--}}
<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}" dir="{{ $dir ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('storefront.installments.return_'.$state.'_title') }}</title>
    <style>
        :root { --lets-fg: #1a1a1a; --lets-muted: #6b7280; --lets-bg: #ffffff; --lets-ok: #047857; --lets-err: #b91c1c; }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 24px; background: var(--lets-bg); color: var(--lets-fg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.5;
        }
        .lets-card { max-width: 420px; margin: 48px auto; text-align: center; }
        .lets-title { font-size: 20px; font-weight: 600; margin: 0 0 8px; }
        .lets-title.is-ok { color: var(--lets-ok); }
        .lets-title.is-err { color: var(--lets-err); }
        .lets-body { font-size: 14px; color: var(--lets-muted); margin: 0 0 20px; }
        .lets-back {
            appearance: none; border: 1px solid #d1d5db; background: #f9fafb;
            border-radius: 8px; padding: 10px 18px; font-size: 14px; cursor: pointer;
            color: inherit; text-decoration: none; display: inline-block;
        }
    </style>
</head>
<body>
    <div class="lets-card">
        <h1 class="lets-title {{ $state === 'success' ? 'is-ok' : 'is-err' }}">
            {{ __('storefront.installments.return_'.$state.'_title') }}
        </h1>
        <p class="lets-body">{{ __('storefront.installments.return_'.$state.'_body') }}</p>
        @if (! empty($backUrl))
            <a class="lets-back" href="{{ $backUrl }}">{{ __('storefront.installments.return_back') }}</a>
        @endif
    </div>
</body>
</html>
