{{--
    The page behind an activation link.

    Two states. WAITING: what the customer bought, and one button — a real POST, never an
    auto-submit, because mail scanners open every link before the person does and the
    subscription must start when the PERSON chooses. ACTIVE: it has started, and when the
    next charge is, so a second click answers the question instead of failing.

    It shows the shop, the product and a date. Not the customer's name, email or card.

    TOKENS (via public/css/rc-admin.css → components/campaigns.css):
      .rc-campaign-page .rc-campaign-card .rc-campaign-card__title
      .rc-campaign-card__body .rc-campaign-card__hint
      .rc-campaign-card__actions .rc-campaign-btn
    Zero inline CSS, as everywhere outside the email templates.

    Props: $shopName, $productTitle, $awaiting, $activateUrl, $nextChargeDate, $dir, and
    optional $failed (the Shopify Payments rail: the store could not take the change yet).
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    {{-- The URL starts somebody's subscription; it must not travel onward. --}}
    <meta name="referrer" content="no-referrer">
    <title>{{ $awaiting ? __('activation.page.title') : __('activation.page.active_title') }}</title>
    <link rel="icon" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::MARK_PATH) }}">
    <link rel="stylesheet" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::THEME_ASSET_PATH) }}">
</head>
<body class="rc-campaign-page">
    <main class="rc-campaign-card">
        <x-rc.shop-logo class="rc-campaign-card__logo" />

        @if($awaiting)
            <h1 class="rc-campaign-card__title">{{ __('activation.page.heading') }}</h1>

            <p class="rc-campaign-card__body">
                @if($productTitle)
                    {{ __('activation.page.lead', ['shop' => $shopName, 'product' => $productTitle]) }}
                @else
                    {{ __('activation.page.lead_generic', ['shop' => $shopName]) }}
                @endif
            </p>

            @if(! empty($failed))
                <p class="rc-campaign-card__hint">{{ __('activation.page.failed') }}</p>
            @endif

            <form class="rc-campaign-card__actions" method="POST" action="{{ $activateUrl }}">
                @csrf
                <button type="submit" class="rc-campaign-btn">{{ __('activation.page.button') }}</button>
            </form>

            <p class="rc-campaign-card__hint">{{ __('activation.page.note') }}</p>
        @else
            <h1 class="rc-campaign-card__title">{{ __('activation.page.active_heading') }}</h1>

            <p class="rc-campaign-card__body">
                @if($productTitle)
                    {{ __('activation.page.active_lead', ['shop' => $shopName, 'product' => $productTitle]) }}
                @else
                    {{ __('activation.page.active_lead_generic', ['shop' => $shopName]) }}
                @endif
            </p>

            @if($nextChargeDate)
                <p class="rc-campaign-card__hint">{{ __('activation.page.next_charge', ['date' => $nextChargeDate]) }}</p>
            @endif
        @endif
    </main>
</body>
</html>
