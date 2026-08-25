{{--
    The landing page behind a campaign email's sign-in link.

    IT SPENDS NOTHING. Getting here consumes no token — mail-security scanners
    follow every link in an email before the person does, and a token they burnt
    is a customer told "already used". The one POST button below is the click
    that spends it.

    It shows the shop's name and a MASKED address: enough for the person to
    recognise themselves, not enough for anyone else to harvest.

    TOKENS (via public/css/rc-admin.css → components/campaigns.css):
      .rc-campaign-page .rc-campaign-card .rc-campaign-card__title
      .rc-campaign-card__body .rc-campaign-card__hint
      .rc-campaign-card__actions .rc-campaign-btn
    Zero inline CSS, as everywhere outside the email templates.

    Props: $shopName, $maskedEmail, $platform, $continueUrl, $dir.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    {{-- Belt and braces beside the Referrer-Policy header: the URL is a
         credential until it is spent, and must not travel onward. --}}
    <meta name="referrer" content="no-referrer">
    <title>{{ __('campaigns.login.title') }}</title>
    <link rel="icon" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::MARK_PATH) }}">
    <link rel="stylesheet" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::THEME_ASSET_PATH) }}">
</head>
<body class="rc-campaign-page">
    <main class="rc-campaign-card">
        <x-rc.logo class="rc-campaign-card__logo" />

        <h1 class="rc-campaign-card__title">{{ __('campaigns.login.heading') }}</h1>

        <p class="rc-campaign-card__body">
            {{ __('campaigns.login.lead', ['shop' => $shopName, 'email' => $maskedEmail]) }}
        </p>

        <form class="rc-campaign-card__actions" method="POST" action="{{ $continueUrl }}">
            @csrf
            <button type="submit" class="rc-campaign-btn">{{ __('campaigns.login.continue') }}</button>
        </form>

        <p class="rc-campaign-card__hint">{{ __('campaigns.login.note') }}</p>
    </main>
</body>
</html>
