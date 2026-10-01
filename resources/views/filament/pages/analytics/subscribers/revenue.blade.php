{{--
    Analytics › Subscribers › Revenue (sketch Revenue.dc.html, spec §1.4).
    Draws only: App\Filament\Pages\Analytics\Screens\SubscribersRevenue::data() shapes every prop.
    TOKENS: rc.chart.* components + .rc-an-kpi-row .rc-an-mini*
            .rc-ans-band .rc-ans-trio(__cell) .rc-ans-head(__title|__value) (analytics-subscribers.css)
    Vars: everything SubscribersRevenue::data() returns, plus $context.
--}}
@php $t = 'analytics/subscribers_revenue.'; @endphp

{{-- 1 · Subscriber vs non-subscriber revenue — needs every customer's orders: not tracked. --}}
<x-rc.chart.card :title="__($t.'customers.title')" :subtitle="__($t.'customers.subtitle')">
    <x-rc.chart.empty variant="not_tracked" compact :body="__($t.'customers.not_tracked')" />
</x-rc.chart.card>

{{-- 2 · Subscription vs non-subscription revenue — only the subscription side is tracked. --}}
<x-rc.chart.card :title="__($t.'orders.title')" :subtitle="__($t.'orders.subtitle')">
    <div class="rc-an-kpi-row rc-ans-band">
        <div class="rc-an-mini">
            <span class="rc-an-mini__label">{{ __($t.'orders.overall') }}</span>
            <span class="rc-an-mini__value rc-an-mini__value--empty">—</span>
            <span class="rc-an-mini__sub">{{ __('analytics.empty.not_tracked_title') }}</span>
        </div>
        <div class="rc-an-mini">
            <span class="rc-an-mini__label">{{ __($t.'orders.subscription') }}</span>
            <span class="rc-an-mini__value rc-ltr">{{ $subscription_revenue }}</span>
            <x-rc.chart.delta :delta="$subscription_delta" />
        </div>
        <div class="rc-an-mini">
            <span class="rc-an-mini__label">{{ __($t.'orders.share') }}</span>
            <span class="rc-an-mini__value rc-an-mini__value--empty">—</span>
            <span class="rc-an-mini__sub">{{ __('analytics.empty.not_tracked_title') }}</span>
        </div>
    </div>
    <x-slot name="note">{{ __($t.'orders.note') }}</x-slot>
</x-rc.chart.card>

{{-- 3 · Recurring vs checkout subscription revenue --}}
<x-rc.chart.card :title="__($t.'split.title')" :subtitle="__($t.'split.subtitle')">
    <x-slot name="actions">
        <x-rc.chart.toggle :options="$grains" :active="$grain" action="setGrain" :target="$chart_id" :label="__('analytics.grain.label')" />
    </x-slot>
    <div class="rc-an-kpi-row rc-ans-band">
        @foreach($split as $cell)
            <div class="rc-an-mini">
                <span class="rc-an-mini__label">{{ $cell['label'] }}</span>
                <span class="rc-an-mini__value rc-ltr">{{ $cell['value'] }}</span>
                <x-rc.chart.delta :delta="$cell['delta']" />
            </div>
        @endforeach
    </div>
    @if($has_data)
        <x-rc.chart.legend :items="$legend" />
        <div class="rc-ans-trio">
            @foreach($charts as $chart)
                <div class="rc-ans-trio__cell">
                    <div class="rc-ans-head">
                        <span class="rc-ans-head__title">{{ $chart['title'] }}</span>
                        <span class="rc-ans-head__value">{{ $chart['value'] }}</span>
                    </div>
                    <x-rc.chart.line :id="$chart['id']" :title="$chart['title']" :labels="$labels" :series="$chart['series']"
                                     :format="$chart['format']" size="sm" :legend="false" />
                </div>
            @endforeach
        </div>
    @else
        <x-rc.chart.empty variant="no_data" />
    @endif
    <x-slot name="note">{{ __($t.'split.note') }}</x-slot>
</x-rc.chart.card>
