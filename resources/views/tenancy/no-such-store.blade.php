{{--
    404 — `<handle>.app.lets.co.il` names no store (never was one, or its old
    address expired). A standalone page, NOT the Filament shell and deliberately
    NOT the login form: a login on a host that is nobody's store would invite a
    merchant to type a password into a place that cannot be theirs.

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
    <title>{{ __('tenancy.no_such_store.title') }} · LETS</title>
    <link rel="icon" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::MARK_PATH) }}">
    <link rel="stylesheet" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::THEME_ASSET_PATH) }}">
</head>
<body class="rc-embed-notice">
    <main class="rc-embed-notice__card">
        <x-rc.logo class="rc-embed-notice__logo" />
        <h1 class="rc-embed-notice__title">{{ __('tenancy.no_such_store.title') }}</h1>
        <p class="rc-embed-notice__body">{{ __('tenancy.no_such_store.body', ['host' => $host]) }}</p>
        <p class="rc-embed-notice__hint">
            <a href="{{ $rootUrl }}">{{ __('tenancy.no_such_store.hint') }}</a>
        </p>
    </main>
</body>
</html>
