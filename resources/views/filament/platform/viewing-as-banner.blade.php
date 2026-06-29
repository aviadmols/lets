{{--
    Persistent "Viewing as {shop} — Exit" banner (W2). Rendered at BODY_START of the
    panel, but ONLY paints when a platform admin is currently ENTERED into a shop —
    a merchant (or a platform admin in platform mode) sees nothing.
    TOKENS: .rc-platform-banner* (components/platform.css, published theme). ZERO
    inline CSS. Mirrors in RTL via logical properties. EN/HE via __().
--}}
@php
    use App\Support\PlatformContext;
    use App\Support\Ui\PanelAccess;
    use App\Models\Shop;

    $shopName = null;
    if (PanelAccess::isPlatformAdmin() && PlatformContext::isEntered()) {
        $shop = Shop::query()->whereKey(PlatformContext::enteredShopId())->first();
        // displayDomain() is never-null + platform-neutral: a WooCommerce shop has no
        // shopify_domain, so reading that left the banner blank for WC shops.
        $shopName = $shop?->displayDomain();
    }
@endphp

@if($shopName !== null)
    <div class="rc-platform-banner" role="status">
        <div class="rc-platform-banner__text">
            <span class="rc-platform-banner__title">{{ __('platform.banner.viewing_as', ['shop' => $shopName]) }}</span>
            <span class="rc-platform-banner__note">{{ __('platform.banner.note') }}</span>
        </div>
        <form method="POST" action="{{ route('platform.exit') }}" class="rc-platform-banner__form">
            @csrf
            <button type="submit" class="rc-platform-banner__exit">{{ __('platform.exit.action') }}</button>
        </form>
    </div>
@endif
