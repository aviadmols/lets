{{--
    The ONE answer to every refusal: missing, malformed, expired, revoked,
    already used, or a shop that is no longer live.

    Deliberately uniform. The difference between "this link never existed" and
    "this link was already used" is an oracle about somebody's subscription, and
    a page that distinguishes them lets a stranger with a guessed token learn
    that the guess was close. There is nothing to gain here and something to
    lose, so it says one thing and offers no retry.

    TOKENS: the same campaign-card component classes. Zero inline CSS.
    Props: $dir.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('card_update.gone.title') }}</title>
    <link rel="icon" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::MARK_PATH) }}">
    <link rel="stylesheet" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::THEME_ASSET_PATH) }}">
</head>
<body class="rc-campaign-page">
    <main class="rc-campaign-card">
        <x-rc.logo class="rc-campaign-card__logo" />

        <h1 class="rc-campaign-card__title">{{ __('card_update.gone.heading') }}</h1>

        <p class="rc-campaign-card__body">{{ __('card_update.gone.body') }}</p>
    </main>
</body>
</html>
