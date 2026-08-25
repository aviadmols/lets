{{--
    The SaaS-hosted personal area — where an emailed sign-in link lands a
    shopper whose store cannot mint a customer session for us (Shopify).

    Same host pattern as account/admin-view.blade.php, with one decisive
    difference: this page is LIVE. It loads the exact lets-account.{css,js} the
    storefront ships, hands them a real AccountPresenter model, and wires the
    endpoint — so pause, resume, skip and the rest all work, through the same
    CustomerSubscriptionActions the storefront uses. No `preview` flag here.

    The nonce is Laravel's CSRF token, carried in X-CSRF-TOKEN (the renderer's
    `nonceHeader` option; WordPress keeps its X-WP-Nonce default).

    TOKENS (via public/css/rc-admin.css → components/campaigns.css):
      .rc-hosted .rc-hosted__bar .rc-hosted__who .rc-hosted__logout
      .rc-hosted__mount
    Zero inline CSS.

    Props: $model, $shopName, $maskedEmail, $endpoint, $logoutUrl, $locale.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $model['appearance']['dir'] ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('campaigns.hosted.title') }} · {{ $shopName }}</title>
    <link rel="icon" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::MARK_PATH) }}">
    <link rel="stylesheet" href="{{ asset(\App\Providers\Filament\AdminPanelProvider::THEME_ASSET_PATH) }}">
    <link rel="stylesheet" href="{{ \App\Support\Ui\AccountAssets::url('account/lets-account.css') }}">
</head>
<body class="rc-hosted">
    <header class="rc-hosted__bar">
        <span class="rc-hosted__who">{{ __('campaigns.hosted.signed_in_as', ['email' => $maskedEmail]) }}</span>

        <form method="POST" action="{{ $logoutUrl }}">
            @csrf
            <button type="submit" class="rc-hosted__logout">{{ __('campaigns.hosted.logout') }}</button>
        </form>
    </header>

    <main id="lets-account-view" class="rc-hosted__mount"></main>

    <script src="{{ \App\Support\Ui\AccountAssets::url('account/lets-account.js') }}"></script>
    <script>
        (function () {
            var model = @json($model);

            window.LetsAccount.render(
                document.getElementById('lets-account-view'),
                model,
                {
                    endpoint: @json($endpoint),
                    nonce: document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    nonceHeader: 'X-CSRF-TOKEN'
                }
            );
        }());
    </script>
</body>
</html>
