@php
    use App\Domain\Upsell\UpsellChargeResult;

    $declined = $declined ?? false;
    $currency = (string) config('payplus.currency', 'ILS');
    $money = fn ($v) => number_format((float) $v, 2).' '.$currency;
    $amount = $money($offer->discountedPrice());

    // Pick the result copy + visual modifier from the charge outcome.
    [$modifier, $icon, $title, $body] = match (true) {
        $declined => ['', '👍', __('upsell.declined_title'), __('upsell.declined_body')],
        $result->result === UpsellChargeResult::RESULT_NO_CONSENT
            => ['ppu--failed', '🔒', __('upsell.no_consent_title'), __('upsell.no_consent_body')],
        $result->result === UpsellChargeResult::RESULT_FAILED || $result->result === UpsellChargeResult::RESULT_NO_METHOD
            => ['ppu--failed', '⚠️', __('upsell.failed_title'), __('upsell.failed_body')],
        $result->isCharged()
            => ['ppu--success', '✓', __('upsell.success_title'), __('upsell.success_body', ['amount' => $amount])],
        default => ['', '•', __('upsell.done'), ''],
    };
@endphp

@extends('upsell::storefront.layout', ['title' => $title, 'rootModifier' => $modifier])

@section('widget')
    <div class="ppu__card">
        <div class="ppu__result-icon" aria-hidden="true">{{ $icon }}</div>
        <h1 class="ppu__headline">{{ $title }}</h1>
        @if ($body !== '')
            <p class="ppu__subcopy">{{ $body }}</p>
        @endif

        {{-- Branch: route the customer to the next offer when the flow continues. --}}
        @if (! empty($nextOffer) && ! empty($nextOfferUrl ?? null))
            <div class="ppu__actions">
                <a class="ppu__btn ppu__btn--accept" href="{{ $nextOfferUrl }}" rel="nofollow">
                    {{ __('upsell.next_offer_cta') }}
                </a>
            </div>
        @else
            <p class="ppu__disclosure">{{ __('upsell.done') }}</p>
        @endif
    </div>
@endsection
