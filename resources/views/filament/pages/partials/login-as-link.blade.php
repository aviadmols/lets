{{--
    "Log in as customer" — the link, handed over rather than followed.

    It opens in a NEW TAB on purpose. This page can be running inside the
    wp-admin iframe, and navigating it swapped the merchant's own WordPress
    session for the customer's: the store's admin bar, and every other wp-admin
    tab, quietly became that shopper. A new tab keeps the two sessions apart —
    and the copyable link exists for the browser that blocks the tab, or for a
    private window, which is the only way to hold both sessions at once.

    The URL is single use and expires; it is not a password, and it stops
    working the moment it is spent.

    TOKENS: .rc-stack / .rc-cta / .rc-input / .rc-muted from the published theme.
    ZERO inline CSS.
--}}
<div class="rc-stack">
    <a
        class="rc-cta rc-cta--primary"
        href="{{ $url }}"
        target="_blank"
        rel="noopener noreferrer"
    >
        {{ __('customers.detail.login_as.open', ['customer' => $customer]) }}
    </a>

    <div class="rc-stack rc-stack--tight">
        <span class="rc-muted">{{ __('customers.detail.login_as.copy_help') }}</span>
        {{-- Alpine, not a raw onfocus attribute: the panel already ships it, and
             one click should select the whole link rather than half of it. --}}
        <input
            class="rc-input rc-ltr"
            type="text"
            readonly
            value="{{ $url }}"
            x-on:focus="$event.target.select()"
        >
    </div>

    <span class="rc-muted">{{ __('customers.detail.login_as.expires') }}</span>
</div>
