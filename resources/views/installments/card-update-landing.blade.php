{{--
    The landing behind a card-update link.

    THE GET MINTS NOTHING. Two reasons, and both have burnt this kind of link:
    mail scanners follow every URL in an email before the person sees it, and a
    PayPlus page minted then would already have expired by the time the customer
    clicks. The POST below is what mints it, at that instant.

    It is a REAL BUTTON, not an auto-submit. The campaign sign-in page submits
    itself because arriving inside your own account is harmless; this one sends
    the customer to a page asking for card details, and a person must have
    chosen that. It also shows the last four digits of the card being replaced,
    which is what turns "some payment page opened" into "yes, that is my card".

    It reveals the shop's name and four digits, and nothing else — a link that
    reaches the wrong inbox must not be a profile of the right person.

    TOKENS (via public/css/rc-admin.css → components/campaigns.css):
      .rc-campaign-page .rc-campaign-card .rc-campaign-card__title
      .rc-campaign-card__body .rc-campaign-card__hint
      .rc-campaign-card__actions .rc-campaign-btn
    Zero inline CSS, as everywhere outside the email templates.

    Props: $shopName, $cardLastFour, $continueUrl, $expiresAt, $dir.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    {{-- The URL is a credential until it is spent; it must not travel onward. --}}
    <meta name="referrer" content="no-referrer">
    <title>{{ __('card_update.landing.title') }}</title>
    <link rel="icon" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::MARK_PATH) }}">
    <link rel="stylesheet" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::THEME_ASSET_PATH) }}">
</head>
<body class="rc-campaign-page">
    <main class="rc-campaign-card">
        <x-rc.logo class="rc-campaign-card__logo" />

        <h1 class="rc-campaign-card__title">{{ __('card_update.landing.heading') }}</h1>

        <p class="rc-campaign-card__body">
            @if($cardLastFour)
                {{ __('card_update.landing.lead_card', ['shop' => $shopName, 'last_four' => $cardLastFour]) }}
            @else
                {{ __('card_update.landing.lead', ['shop' => $shopName]) }}
            @endif
        </p>

        <form class="rc-campaign-card__actions" method="POST" action="{{ $continueUrl }}">
            @csrf
            <button type="submit" class="rc-campaign-btn">{{ __('card_update.landing.continue') }}</button>
        </form>

        <p class="rc-campaign-card__hint">{{ __('card_update.landing.note') }}</p>

        @if($expiresAt)
            <p class="rc-campaign-card__hint">
                {{ __('card_update.landing.expires', ['date' => $expiresAt]) }}
            </p>
        @endif
    </main>
</body>
</html>
