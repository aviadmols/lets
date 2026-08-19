{{--
    "View as customer" — the shopper's own personal area inside a modal.

    The iframe loads the SAME public/account/lets-account.{css,js} the storefront
    ships (via the panel's account.preview route with ?customer=…), so this is the
    page, not a rendering of it. It is inert by construction: the host document
    renders with `preview: true` and is handed no endpoint, so nothing in here can
    pause, cancel or charge anything.

    TOKENS: .rc-stack / .rc-muted / .rc-link / .rc-customer-area__frame from the
    published theme. ZERO inline CSS.
--}}
<div class="rc-stack rc-stack--tight">
    <iframe
        class="rc-customer-area__frame"
        src="{{ $url }}"
        title="{{ __('customers.detail.view_as.heading') }}"
        loading="lazy"
    ></iframe>

    <div class="rc-row rc-row--between">
        <span class="rc-muted">{{ __('customers.detail.view_as.read_only') }}</span>
        <a class="rc-link" href="{{ $url }}" target="_blank" rel="noopener">
            {{ __('loyalty.admin.preview.open') }}
        </a>
    </div>
</div>
