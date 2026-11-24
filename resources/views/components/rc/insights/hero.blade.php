{{--
    rc.insights.hero — the ONE black card of a screen (the LETS panel language): an eyebrow,
    the headline number in large type, a sentence whose numbers are lime, an optional bar
    chart, and a column of dark tiles beside it.
    TOKENS: .rc-ins-hero* (components/insights.css). ZERO inline CSS.

    Props: eyebrow, big, chart (rcInsights.bar payload | null)
    Slots: default = the sentence under the number; tiles = <x-rc.insights.tile> column
--}}
@props(['eyebrow' => '', 'big' => '', 'chart' => null])
<section {{ $attributes->merge(['class' => 'rc-ins-hero']) }}>
    <div class="rc-ins-hero__main">
        <div class="rc-ins-hero__eyebrow">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 2h14v4.5L13.6 12 19 17.5V22H5v-4.5L10.4 12 5 6.5z"/></svg>
            <span>{{ $eyebrow }}</span>
        </div>
        <div class="rc-ins-hero__big">{{ $big }}</div>
        <p class="rc-ins-hero__say">{{ $slot }}</p>
        @if ($chart !== null)
            {{-- Drawn by rc-insights-charts.js from data-rc-chart — never from an Alpine
                 x-init, which broke the Filament table on the same page. --}}
            <div class="rc-ins-chart rc-ins-chart--hero"
                 wire:ignore
                 wire:key="hero-chart-{{ md5(json_encode($chart)) }}"
                 data-rc-chart="{{ json_encode(['type' => 'bar'] + $chart, JSON_UNESCAPED_UNICODE) }}"></div>
        @endif
    </div>
    @isset($tiles)
        <div class="rc-ins-hero__tiles">{{ $tiles }}</div>
    @endisset
</section>
