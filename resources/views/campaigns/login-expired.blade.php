{{--
    410 Gone — the sign-in link is spent, expired, revoked, or was never real.

    ONE PAGE FOR EVERY REFUSAL, deliberately. The difference between "this link
    was already used" and "this link never existed" is an oracle; the page does
    not offer it, and neither does the status code.

    It is also the sign-out page for the hosted personal area, which is why the
    optional $signedOut swaps one line.

    TOKENS: .rc-campaign-page .rc-campaign-card .rc-campaign-card__title
            .rc-campaign-card__body .rc-campaign-card__hint

    Props: $dir, $signedOut (optional bool).
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('campaigns.login.expired_title') }}</title>
    <link rel="icon" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::MARK_PATH) }}">
    <link rel="stylesheet" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::THEME_ASSET_PATH) }}">
</head>
<body class="rc-campaign-page">
    <main class="rc-campaign-card">
        <x-rc.logo class="rc-campaign-card__logo" />

        @if (! empty($signedOut))
            <h1 class="rc-campaign-card__title">{{ __('campaigns.login.signed_out') }}</h1>
        @else
            <h1 class="rc-campaign-card__title">{{ __('campaigns.login.expired_heading') }}</h1>
            <p class="rc-campaign-card__body">{{ __('campaigns.login.expired_lead') }}</p>
        @endif
    </main>
</body>
</html>
