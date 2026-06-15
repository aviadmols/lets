{{--
  Storefront upsell widget layout. Standalone (rendered in a Shopify thank-you
  page / iframe), so it links its own self-contained, tokenised stylesheet. NO
  inline CSS (the email exemption does not apply here). RTL-aware via the html
  dir attribute, driven by the active locale.
--}}
@php($isRtl = in_array(app()->getLocale(), ['he', 'ar'], true))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/upsell-widget.css') }}">
</head>
<body>
    <div class="ppu {{ $rootModifier ?? '' }}">
        @yield('widget')
    </div>
</body>
</html>
