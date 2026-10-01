{{--
    Analytics placeholder screen — "Coming in this build". Rendered by every screen class that still
    extends PlaceholderScreen (ScreenRegistry already points at it).
    TOKENS: .rc-an-card .rc-an-soon .rc-an-soon__title/__body/__list .rc-an-pill
    Vars: $sketch (board file name), $spec (spec.md heading)
--}}
<section class="rc-an-card">
    <div class="rc-an-soon">
        <x-rc.chart.pill tone="info" :label="__('analytics.placeholder.badge')" />
        <h2 class="rc-an-soon__title">{{ __('analytics.placeholder.title') }}</h2>
        <p class="rc-an-soon__body">{{ __('analytics.placeholder.body') }}</p>
        <ul class="rc-an-soon__list">
            @if($spec)<li><x-rc.chart.pill tone="neutral" :label="__('analytics.placeholder.spec', ['spec' => $spec])" /></li>@endif
            @if($sketch)<li><x-rc.chart.pill tone="neutral" :label="__('analytics.placeholder.sketch', ['sketch' => $sketch])" /></li>@endif
        </ul>
    </div>
</section>
