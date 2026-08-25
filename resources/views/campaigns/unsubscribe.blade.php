{{--
    The unsubscribe confirmation page.

    A GET never unsubscribes anybody: scanners and link prefetchers would then
    remove customers who never asked. The POST button below is the request, and
    the URL signature is its authorisation — no session, no login.

    TOKENS: .rc-campaign-page .rc-campaign-card .rc-campaign-card__title
            .rc-campaign-card__body .rc-campaign-card__actions .rc-campaign-btn

    Props: $shopName, $maskedEmail, $confirmUrl, $dir.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('campaigns.unsubscribe.title') }}</title>
    <link rel="icon" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::MARK_PATH) }}">
    <link rel="stylesheet" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::THEME_ASSET_PATH) }}">
</head>
<body class="rc-campaign-page">
    <main class="rc-campaign-card">
        <x-rc.logo class="rc-campaign-card__logo" />

        <h1 class="rc-campaign-card__title">{{ __('campaigns.unsubscribe.heading') }}</h1>

        <p class="rc-campaign-card__body">
            {{ __('campaigns.unsubscribe.lead', ['shop' => $shopName, 'email' => $maskedEmail]) }}
        </p>

        {{-- The signature travels in the action URL; no CSRF token is required
             (the route is CSRF-exempt so mailbox providers can one-click it). --}}
        <form class="rc-campaign-card__actions" method="POST" action="{{ $confirmUrl }}">
            <button type="submit" class="rc-campaign-btn">{{ __('campaigns.unsubscribe.confirm') }}</button>
        </form>
    </main>
</body>
</html>
