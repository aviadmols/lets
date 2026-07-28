{{--
    PREVIEW of Shopify's POST-PURCHASE page.

    Shopify draws that page from ITS OWN components and forbids custom CSS, so this
    is deliberately NOT the storefront card: previewing the card there showed a
    surface the shopper never sees, which is the bug this view exists to end.

    Faithful by construction: it walks the SAME $presentation contract
    (PostPurchasePresenter) that extensions/lets-post-purchase renders — same
    element order, same enabled set, same resolved copy. Change one and the other
    follows.

    TOKENS: public/upsell/lets-pp-preview.css, which approximates Shopify's default
    checkout branding and reads NONE of the merchant's accent/radius/shadow/font —
    because none of them apply here. ZERO inline CSS.
--}}
@php
    $dir = in_array(app()->getLocale(), ['he', 'ar'], true) ? 'rtl' : 'ltr';
    $copy = $presentation['copy'] ?? [];
    $on = $presentation['elements'] ?? [];
    $order = $presentation['order'] ?? [];
    $mediaSide = ($presentation['layout'] ?? 'stacked') === 'media_side';
    $declineLink = ($presentation['decline_style'] ?? 'link') === 'link';
    // Text blocks follow the merchant's order; image and buttons own their slots.
    $blocks = array_values(array_filter($order, fn (string $k): bool => ! in_array($k, ['image', 'cta', 'decline'], true)));
    $showImage = ($on['image'] ?? false) && ! empty($copy['image_url']);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('upsell.preview.title') }}</title>
    <link href="{{ asset('upsell/lets-pp-preview.css') }}" rel="stylesheet">
</head>
<body class="pp">
<div class="pp__frame">

    {{-- The sentence that stops a merchant tuning a control that cannot apply. --}}
    <div class="pp__note">
        <span>⚠</span>
        <span>
            <strong>{{ __('upsell.preview.shopify_owned_title') }}</strong>
            {{ __('upsell.preview.shopify_owned_body') }}
        </span>
    </div>

    @if(($on['headline'] ?? false) && ! empty($copy['headline']))
        <div class="pp__banner">
            <p class="pp__banner-title">{{ $copy['headline'] }}</p>
            @if(! empty($copy['subcopy']))
                <p class="pp__banner-sub">{{ $copy['subcopy'] }}</p>
            @endif
        </div>
    @endif

    <div class="pp__card">
        <div class="pp__layout {{ $mediaSide && $showImage ? 'pp__layout--side' : '' }}">

            @if($showImage)
                <div class="pp__media">
                    <img src="{{ $copy['image_url'] }}" alt="{{ $copy['product_name'] ?? '' }}">
                </div>
            @endif

            <div class="pp__copy">
                @foreach($blocks as $key)
                    @switch($key)
                        @case('eyebrow')
                            @if(! empty($copy['eyebrow']))<p class="pp__eyebrow">{{ $copy['eyebrow'] }}</p>@endif
                            @break
                        @case('badge')
                            @if(! empty($copy['badge']))<p class="pp__badge">{{ $copy['badge'] }}</p>@endif
                            @break
                        @case('headline')
                            @if(! empty($copy['headline']))<p class="pp__headline">{{ $copy['headline'] }}</p>@endif
                            @break
                        @case('product_name')
                            @if(! empty($copy['product_name']))<p class="pp__product">{{ $copy['product_name'] }}</p>@endif
                            @break
                        @case('subcopy')
                            @if(! empty($copy['subcopy']))<p class="pp__subcopy">{{ $copy['subcopy'] }}</p>@endif
                            @break
                        @case('price')
                            <div class="pp__price">
                                @if(! empty($copy['was_display']))
                                    <span class="pp__price-was">{{ $copy['was_display'] }}</span>
                                @endif
                                <span class="pp__price-now">{{ $copy['price_display'] }}</span>
                            </div>
                            @break
                        @case('save')
                            @if(! empty($copy['save_label']))<p class="pp__save">{{ $copy['save_label'] }}</p>@endif
                            @break
                        @case('trust')
                            @if(! empty($copy['trust']))<p class="pp__trust">{{ $copy['trust'] }}</p>@endif
                            @break
                        @case('disclosure')
                            @if(! empty($copy['disclosure']))<p class="pp__disclosure">{{ $copy['disclosure'] }}</p>@endif
                            @break
                    @endswitch
                @endforeach

                {{-- Buttons are pinned below the copy, exactly as the extension pins
                     them: a reordered element list must never put "No thanks" above
                     the price it declines. --}}
                <div class="pp__actions">
                    <span class="pp__btn pp__btn--primary">{{ $copy['accept_cta'] }}</span>
                    @if($on['decline'] ?? false)
                        <span class="pp__btn {{ $declineLink ? 'pp__btn--plain' : 'pp__btn--subdued' }}">
                            {{ $copy['decline_cta'] }}
                        </span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
