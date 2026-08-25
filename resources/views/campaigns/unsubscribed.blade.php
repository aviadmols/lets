{{--
    "Done" — the address is on the shop's suppression list.

    It says plainly what KEEPS coming (receipts, reminders, sign-in codes), so
    nobody unsubscribes expecting silence and then reports a receipt as spam.

    TOKENS: .rc-campaign-page .rc-campaign-card .rc-campaign-card__title
            .rc-campaign-card__body

    Props: $shopName, $dir.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('campaigns.unsubscribe.done_title') }}</title>
    <link rel="icon" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::MARK_PATH) }}">
    <link rel="stylesheet" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::THEME_ASSET_PATH) }}">
</head>
<body class="rc-campaign-page">
    <main class="rc-campaign-card">
        <x-rc.logo class="rc-campaign-card__logo" />

        <h1 class="rc-campaign-card__title">{{ __('campaigns.unsubscribe.done_heading') }}</h1>
        <p class="rc-campaign-card__body">{{ __('campaigns.unsubscribe.done_lead', ['shop' => $shopName]) }}</p>
    </main>
</body>
</html>
