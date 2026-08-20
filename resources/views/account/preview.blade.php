{{--
    The shopper's personal area, inside the merchant's admin preview iframe.

    This document exists only to be a HOST for the real thing: it loads
    public/account/lets-account.css and public/account/lets-account.js — the exact
    files the WordPress plugin ships — and hands them AccountPresenter::sample().
    There is no second copy of the markup here, so the preview cannot drift from
    what a shopper sees; if it looks wrong here, it is wrong there.

    The page deliberately declares NO font-family. On a real storefront the area
    inherits the merchant's theme typography, and a preview that imposed one would
    be lying about the one thing merchants notice first. system-ui is the browser's
    own default, which is the honest stand-in for "whatever your theme uses".

    ZERO application CSS: the only <style> block writes the frame's background and
    padding, which belong to the iframe chrome, not to the component.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $model['appearance']['dir'] ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('account.admin.preview.heading') }}</title>
    {{-- Cache-busted by content mtime, the same way the admin theme is
         (AdminPanelProvider::themeAssetUrl). The storefront busts these on the
         plugin version; this preview had nothing, so a merchant could tune a
         setting and be shown a renderer from before the last deploy — the one
         place where looking right and being right must not come apart. --}}
    <link rel="stylesheet" href="{{ \App\Support\Ui\AccountAssets::url('account/lets-account.css') }}">
    <style>
        html, body { margin: 0; padding: 0; background: #f3f4f6; }
        body { padding: 20px; font-family: system-ui, sans-serif; }
        @media (prefers-color-scheme: dark) { html, body { background: #0b1220; } }
    </style>
</head>
<body>
    <div id="lets-account-preview"></div>

    <script src="{{ \App\Support\Ui\AccountAssets::url('account/lets-account.js') }}"></script>
    <script>
        (function () {
            var model = @json($model);

            window.LetsAccount.render(
                document.getElementById('lets-account-preview'),
                model,
                // No endpoint: a preview must never be able to cancel anything,
                // and `preview` additionally makes every control inert.
                { preview: true }
            );

            // Tell the admin shell we are mounted, so a draft appearance that
            // arrived before this document finished loading gets replayed.
            if (window.parent !== window) {
                window.parent.postMessage({ type: 'lets-account-preview-ready' }, window.location.origin);
            }
        }());
    </script>
</body>
</html>
