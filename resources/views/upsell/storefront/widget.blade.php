@extends('upsell::storefront.layout', ['title' => __('upsell.default_headline')])

{{--
  The one-click thank-you-page upsell offer. Price is server-computed (passed in);
  the accept/decline links are SIGNED (the signature is the auth). The consent
  disclosure is REQUIRED — it states the amount, that the SAVED payment method is
  charged, and that it is a one-time charge with no card re-entry.

  Merchant copy (headline/subcopy/CTAs) is shown ESCAPED via {{ }} — display text,
  not an HTML template, so there is no strtr/Blade::render concern here.
--}}
@php
    $money = fn (float $v) => number_format($v, 2).' '.$currency;
    $headline = $offer->headline ?: __('upsell.default_headline');
    $subcopy = $offer->subcopy ?: __('upsell.default_subcopy');
    $acceptLabel = $offer->accept_cta ?: __('upsell.accept_cta');
    $declineLabel = $offer->decline_cta ?: __('upsell.decline_cta');
    $hasDiscount = $basePrice > $price;
@endphp

@section('widget')
    <div class="ppu__card">
        <span class="ppu__eyebrow">{{ __('upsell.widget_eyebrow') }}</span>
        <h1 class="ppu__headline">{{ $headline }}</h1>
        <p class="ppu__subcopy">{{ $subcopy }}</p>

        <div class="ppu__price">
            <span class="ppu__price-now">{{ $money($price) }}</span>
            @if ($hasDiscount)
                <span class="ppu__price-was">{{ __('upsell.was_price', ['price' => $money($basePrice)]) }}</span>
                <span class="ppu__save">{{ __('upsell.you_save', ['amount' => $money($basePrice - $price)]) }}</span>
            @endif
        </div>

        <div class="ppu__actions">
            <a class="ppu__btn ppu__btn--accept" href="{{ $acceptUrl }}" rel="nofollow">{{ $acceptLabel }}</a>
            <a class="ppu__btn ppu__btn--decline" href="{{ $declineUrl }}" rel="nofollow">{{ $declineLabel }}</a>
        </div>

        <p class="ppu__disclosure">
            {{ __('upsell.consent_disclosure', ['amount' => $money($price)]) }}
        </p>
    </div>
@endsection
