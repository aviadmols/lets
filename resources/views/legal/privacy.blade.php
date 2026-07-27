{{--
    The public privacy notice at /privacy — the page Shopify's review reads and a
    merchant's DPA points at. Standalone (NOT the Filament shell): it must render
    for a reviewer or a shopper who has no session and never opens the admin.

    TOKENS (via public/css/rc-admin.css → components/legal.css):
      .rc-legal .rc-legal__head .rc-legal__section .rc-legal__list
    Zero inline CSS; the stylesheet carries the tokens, as everywhere else.

    Content lives in lang/{en,he}/privacy.php so the Hebrew page is a real
    translation rather than a mirrored layout, and RTL follows the locale.
--}}
@php
    $locale = app()->getLocale();
    $isRtl = $locale === 'he';
    $email = config('mail.from.address') ?: 'support@lets.co.il';
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('privacy.title') }} · LETS</title>
    <meta name="robots" content="index,follow">
    <link rel="icon" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::MARK_PATH) }}">
    <link rel="stylesheet" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::THEME_ASSET_PATH) }}">
</head>
<body class="rc-legal">
    <main class="rc-legal__page">
        <header class="rc-legal__head">
            <x-rc.logo class="rc-legal__logo" />
            <h1 class="rc-legal__title">{{ __('privacy.title') }}</h1>
            <p class="rc-legal__subtitle">{{ __('privacy.subtitle') }}</p>
            <p class="rc-legal__meta">{{ __('privacy.updated', ['date' => '27 July 2026']) }}</p>
        </header>

        <p class="rc-legal__lead">{{ __('privacy.intro') }}</p>

        {{-- Sections with a single body paragraph. --}}
        @foreach (['roles'] as $key)
            <section class="rc-legal__section">
                <h2 class="rc-legal__heading">{{ __("privacy.{$key}.heading") }}</h2>
                <p>{!! \App\Support\Ui\LegalText::render(__("privacy.{$key}.body")) !!}</p>
            </section>
        @endforeach

        {{-- Sections that are a list, optionally with intro/outro copy. --}}
        @foreach (['collect', 'purposes', 'sharing', 'security', 'retention'] as $key)
            <section class="rc-legal__section">
                <h2 class="rc-legal__heading">{{ __("privacy.{$key}.heading") }}</h2>

                @if (\Illuminate\Support\Facades\Lang::has("privacy.{$key}.intro"))
                    <p>{{ __("privacy.{$key}.intro") }}</p>
                @endif

                <ul class="rc-legal__list">
                    @foreach ((array) __("privacy.{$key}.items") as $item)
                        <li>{!! \App\Support\Ui\LegalText::render($item) !!}</li>
                    @endforeach
                </ul>

                @if (\Illuminate\Support\Facades\Lang::has("privacy.{$key}.never"))
                    <p class="rc-legal__strong">{{ __("privacy.{$key}.never") }}</p>
                @endif
                @if (\Illuminate\Support\Facades\Lang::has("privacy.{$key}.outro"))
                    <p>{{ __("privacy.{$key}.outro") }}</p>
                @endif
            </section>
        @endforeach

        @foreach (['automated', 'rights'] as $key)
            <section class="rc-legal__section">
                <h2 class="rc-legal__heading">{{ __("privacy.{$key}.heading") }}</h2>
                <p>{!! \App\Support\Ui\LegalText::render(__("privacy.{$key}.body")) !!}</p>
            </section>
        @endforeach

        <section class="rc-legal__section">
            <h2 class="rc-legal__heading">{{ __('privacy.contact.heading') }}</h2>
            <p>{{ __('privacy.contact.body', ['email' => $email]) }}</p>
        </section>

        <footer class="rc-legal__foot">
            <a href="{{ url('/') }}" class="rc-legal__link">{{ __('privacy.back') }}</a>
        </footer>
    </main>
</body>
</html>
