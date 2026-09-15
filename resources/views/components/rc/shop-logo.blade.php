{{--
    rc.shop-logo — the MERCHANT'S logo on a page their customer is looking at,
    falling back to ours when they have not set one.

    Every page that uses this is a page a shopper lands on from an email or an
    SMS: a card-update link, a sign-in link, an unsubscribe confirmation. Our
    mark on those pages answers the wrong question — the shopper is checking
    whether this is really their shop before they type a card number, and "let's"
    is a name they have never heard.

    TOKENS (components/campaigns.css): .rc-campaign-card__logo is passed in by the
    caller; the img carries only that class. ZERO inline CSS.

    The URL is read back through MerchantPortalAppearance::logoUrl(), which
    re-validates it on every render — https only, no data:/javascript: — because
    this string reaches an `src` on the one page that has to look trustworthy.

    Props:
      logo  — a pre-resolved URL, for callers that already read the settings
              (the customer area renders hundreds of blocks and should not query
              per block). Omit it and this reads the current shop's own setting.
--}}
@props(['logo' => null])

@php
    $resolved = $logo ?? (\App\Support\Tenant::check()
        ? \App\Models\MerchantPortalAppearance::current()->logoUrl()
        : null);
@endphp

@if($resolved)
    {{-- alt is empty on purpose: the shop's name is already the page's heading,
         and a screen reader announcing it twice is noise, not accessibility. --}}
    <img src="{{ $resolved }}" alt="" {{ $attributes }}>
@else
    <x-rc.logo {{ $attributes }} />
@endif
