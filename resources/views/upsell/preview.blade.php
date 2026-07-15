{{--
    Standalone PREVIEW host for the post-purchase card (Phase 3). A bare noindex HTML doc that
    loads the SAME shared CSS + JS + presenter view-model the storefront uses, so the preview
    literally IS the storefront card. Rendered by AdminUpsellPreviewController inside the panel
    (Filament-authed, tenant-scoped). Opened both as the "View post-purchase" tab and inside the
    Appearance page's live-preview iframe (which postMessages unsaved draft appearance in).

    The <style> block here styles only the PREVIEW HOST FRAME (page bg + centering) — not the card,
    whose every rule lives in the shared lets-ppu.css. No style="" attributes.
--}}
@php
    $dir = in_array(app()->getLocale(), ['he', 'ar'], true) ? 'rtl' : 'ltr';
    $dark = ($viewModel['appearance']['theme'] ?? 'light') === 'dark';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('upsell.preview.title') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link href="{{ asset('upsell/lets-ppu.css') }}" rel="stylesheet">
    <style>
        html, body { margin: 0; padding: 0; }
        .lets-preview {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            min-height: 100vh;
            padding: 28px 18px;
            background: #f4f4f5;
            transition: background 0.4s ease;
        }
        .lets-preview.is-dark { background: #0e0f12; }
    </style>
</head>
<body>
    <div class="lets-preview {{ $dark ? 'is-dark' : '' }}" id="lets-preview-frame">
        <div id="lets-preview-mount" data-lets-upsell hidden></div>
    </div>

    <script>window.LetsUpsellPreview = @json($viewModel);</script>
    <script src="{{ asset('upsell/lets-ppu.js') }}"></script>
    <script>
        (function () {
            'use strict';
            var mount = document.getElementById('lets-preview-mount');
            var frame = document.getElementById('lets-preview-frame');
            if (!window.LetsUpsell || !mount) { return; }

            LetsUpsell.renderCard(mount, window.LetsUpsellPreview, LetsUpsell.previewHandlers);

            // Live builder: the Appearance page posts an unsaved draft appearance (never money).
            // Same-origin only (the parent panel is on this origin).
            window.addEventListener('message', function (event) {
                if (event.origin !== window.location.origin) { return; }
                var data = event.data || {};
                if (data.type !== 'lets-preview-appearance' || !data.appearance) { return; }
                LetsUpsell.applyAppearance(data.appearance);
                if (frame) { frame.classList.toggle('is-dark', data.appearance.theme === 'dark'); }
            });

            // Tell the parent the preview is ready (so it can flush the initial draft).
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'lets-preview-ready' }, window.location.origin);
            }
        })();
    </script>
</body>
</html>
