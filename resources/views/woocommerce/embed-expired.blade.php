{{--
    410 Gone — the one-shot wp-admin sign-in link was already used, or its minute
    ran out. Rendered INSIDE the merchant's WordPress iframe, so it is a standalone
    page (not the Filament shell) and deliberately offers NO login form: a merchant
    provisioned through the plugin may have no password, and a form they cannot
    complete is a dead end. The one instruction that works is the only one here.

    TOKENS (via public/css/rc-admin.css → components/embedded.css):
      .rc-embed-notice .rc-embed-notice__card .rc-embed-notice__title
      .rc-embed-notice__body .rc-embed-notice__hint
    Zero inline CSS, as everywhere outside the email templates.
--}}
@php
    $locale = app()->getLocale();
    $isRtl = $locale === 'he';
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ __('embedded.expired.title') }} · LETS</title>
    <link rel="icon" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::MARK_PATH) }}">
    <link rel="stylesheet" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::THEME_ASSET_PATH) }}">
</head>
<body class="rc-embed-notice">
    <main class="rc-embed-notice__card">
        <x-rc.logo class="rc-embed-notice__logo" />
        <h1 class="rc-embed-notice__title">{{ __('embedded.expired.title') }}</h1>
        <p class="rc-embed-notice__body">{{ __('embedded.expired.body') }}</p>
        <p class="rc-embed-notice__hint">{{ __('embedded.expired.hint') }}</p>
    </main>
</body>
</html>
