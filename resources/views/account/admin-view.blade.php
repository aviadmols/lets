{{--
    "View as customer" — the personal area with ONE REAL shopper's data, opened
    from the admin. Same host pattern as account/preview.blade.php: it loads the
    exact lets-account.{css,js} the storefront ships and hands them a REAL
    AccountPresenter::present() model, with `preview: true` making every control
    inert. A read-only window, never a session.

    The banner is the one honest difference from the preview: the admin must
    always know they are looking at a person's real account, not sample data.

    Token reference: colors below are the admin chrome's neutral grays (the
    frame, not the component); the component itself styles from lets-account.css.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $model['appearance']['dir'] ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('customers.detail.view_as.heading') }}</title>
    <link rel="stylesheet" href="{{ \App\Support\Ui\AccountAssets::url('account/lets-account.css') }}">
    <style>
        html, body { margin: 0; padding: 0; background: #f3f4f6; }
        body { padding: 20px; font-family: system-ui, sans-serif; }
        .lets-view-as-note {
            margin: 0 0 16px; padding: 10px 14px; border-radius: 8px;
            background: #fef3c7; color: #92400e; font-size: 14px;
        }
        @media (prefers-color-scheme: dark) {
            html, body { background: #0b1220; }
            .lets-view-as-note { background: #451a03; color: #fcd34d; }
        }
    </style>
</head>
<body>
    <p class="lets-view-as-note">{{ __('customers.detail.view_as.note') }}</p>
    <div id="lets-account-view"></div>

    <script src="{{ \App\Support\Ui\AccountAssets::url('account/lets-account.js') }}"></script>
    <script>
        (function () {
            var model = @json($model);

            window.LetsAccount.render(
                document.getElementById('lets-account-view'),
                model,
                // No endpoint: this window must never be able to act, and
                // `preview` additionally makes every control inert.
                { preview: true }
            );
        }());
    </script>
</body>
</html>
